<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database. Note: model events are NOT muted — our
     * models assign their `uuid` in a `creating` hook, so seeding relies on it.
     */
    public function run(): void
    {
        $this->call(BusinessCategorySeeder::class);
        $this->call(PlanSeeder::class);
        $this->call(FeatureFlagSeeder::class);
        $this->call(CmsPageSeeder::class);
        $this->call(DemoAccountSeeder::class);

        // Idempotent so a re-seed doesn't collide on the unique email.
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User'],
        );
    }
}
