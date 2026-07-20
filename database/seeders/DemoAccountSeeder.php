<?php

namespace Database\Seeders;

use App\Enums\BusinessStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\BusinessCategory;
use App\Models\User;
use App\Services\QrService;
use Illuminate\Database\Seeder;

/**
 * Demo mobile + PIN accounts for the login page. Idempotent.
 *
 * SECURITY NOTE: these are demo credentials with a shared default PIN (1234).
 * Fine for local/demo use; change the PINs (and remove/guard this seeder)
 * before a real production launch.
 */
class DemoAccountSeeder extends Seeder
{
    private const DEFAULT_PIN = '1234';

    public const ADMIN_PHONE = '9000000001';

    public const OWNER_PHONE = '9000000002';

    public function run(): void
    {
        // SECURITY (Milestone 16): never seed known-credential demo accounts into
        // production unless explicitly opted in. Local/staging seed them freely.
        if (app()->environment('production') && ! env('SEED_DEMO_ACCOUNTS', false)) {
            return;
        }

        // Demo Super Admin
        User::updateOrCreate(
            ['email' => 'admin@listee.test'],
            [
                'name' => 'Platform Admin',
                'phone' => self::ADMIN_PHONE,
                'pin' => self::DEFAULT_PIN,
                'pin_plain' => self::DEFAULT_PIN,
                'role' => UserRole::Admin,
                'status' => UserStatus::Active,
                'provider' => 'pin',
            ],
        );

        // Demo Business Owner (+ a ready business so the owner app is populated)
        $owner = User::updateOrCreate(
            ['email' => 'owner@listee.test'],
            [
                'name' => 'Demo Owner',
                'phone' => self::OWNER_PHONE,
                'pin' => self::DEFAULT_PIN,
                'pin_plain' => self::DEFAULT_PIN,
                'role' => UserRole::BusinessOwner,
                'status' => UserStatus::Active,
                'provider' => 'pin',
            ],
        );
        $this->ensureBusiness($owner);

        // Backfill: give every existing owner a PIN so they can sign in, filling a
        // missing mobile from their business phone where possible.
        User::where('role', UserRole::BusinessOwner->value)
            ->whereNull('pin')
            ->chunkById(200, function ($owners): void {
                foreach ($owners as $owner) {
                    if (empty($owner->phone)) {
                        $owner->phone = $owner->businesses()->orderByDesc('id')->value('phone');
                    }
                    $owner->pin = self::DEFAULT_PIN;
                    $owner->pin_plain = self::DEFAULT_PIN;
                    $owner->save();
                }
            });

        // Owners seeded before pin_plain existed: record the known default so the
        // admin panel can show their credentials.
        User::where('role', UserRole::BusinessOwner->value)
            ->whereNotNull('pin')
            ->whereNull('pin_plain')
            ->update(['pin_plain' => self::DEFAULT_PIN]);
    }

    private function ensureBusiness(User $owner): void
    {
        if ($owner->businesses()->exists()) {
            return;
        }

        $business = Business::create([
            'owner_id' => $owner->id,
            'category_id' => BusinessCategory::query()->value('id'),
            'name' => 'Demo Cafe',
            'owner_name' => $owner->name,
            'description' => 'A demo business for exploring the owner app.',
            'address' => '123 Demo Street',
            'phone' => $owner->phone,
            'email' => $owner->email,
            'opening_time' => '09:00:00',
            'closing_time' => '21:00:00',
            'status' => BusinessStatus::Active,
        ]);

        app(QrService::class)->createForBusiness($business);
    }
}
