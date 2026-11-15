<?php

namespace Tests\Feature\Api\V1;

use App\Enums\BusinessStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\CmsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public legal pages (Play Store links) the admin writes, and deleting an
 * account from inside the app.
 */
class PagesAndAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    public function test_store_pages_are_seeded_and_public(): void
    {
        // The migration fills the pages Google Play links to.
        $this->getJson('/api/v1/pages/privacy')->assertOk()
            ->assertJsonPath('data.title', 'Privacy Policy')
            ->assertJsonPath('data.slug', 'privacy');
        $this->assertStringContainsString('Camera', $this->getJson('/api/v1/pages/privacy')->json('data.body'));
        $this->getJson('/api/v1/pages/delete-account')->assertOk();

        $slugs = collect($this->getJson('/api/v1/pages')->assertOk()->json('data'))->pluck('slug');
        $this->assertTrue($slugs->contains('terms') && $slugs->contains('refund'));

        // Drafts stay hidden.
        CmsPage::where('slug', 'faq')->update(['status' => 'draft']);
        $this->getJson('/api/v1/pages/faq')->assertNotFound();
    }

    public function test_admin_adds_edits_and_deletes_pages_but_not_core_ones(): void
    {
        $admin = User::factory()->admin()->create(['status' => UserStatus::Active]);

        $this->as($admin)->putJson('/api/v1/admin/cms/shipping-policy', ['title' => 'Shipping', 'body' => '## Delivery'])
            ->assertOk()->assertJsonPath('data.core', false);
        $this->getJson('/api/v1/pages/shipping-policy')->assertOk()->assertJsonPath('data.body', '## Delivery');

        $this->as($admin)->putJson('/api/v1/admin/cms/Bad Slug!', ['title' => 'x'])->assertStatus(422);

        $this->as($admin)->deleteJson('/api/v1/admin/cms/privacy')->assertStatus(422);
        $this->as($admin)->deleteJson('/api/v1/admin/cms/shipping-policy')->assertOk();
        $this->getJson('/api/v1/pages/shipping-policy')->assertNotFound();
    }

    public function test_a_customer_deletes_their_account(): void
    {
        $customer = User::factory()->create([
            'role' => UserRole::Customer, 'status' => UserStatus::Active,
            'name' => 'Priya', 'phone' => '9876543210', 'email' => 'priya@example.com',
        ]);

        $this->as($customer)->deleteJson('/api/v1/account', [])->assertStatus(422);
        $this->as($customer)->deleteJson('/api/v1/account', ['confirm' => 'DELETE'])->assertOk();

        $row = User::withTrashed()->find($customer->id);
        $this->assertTrue($row->trashed());
        $this->assertSame('Deleted user', $row->name);
        $this->assertNull($row->phone);
        $this->assertNull($row->email);
        $this->assertSame(0, $row->tokens()->count());

        // Signed out everywhere.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/profile')->assertUnauthorized();
    }

    public function test_an_owner_deleting_takes_the_shop_offline(): void
    {
        $owner = User::factory()->businessOwner()->create(['status' => UserStatus::Active]);
        $business = Business::factory()->create(['owner_id' => $owner->id]);

        $this->as($owner)->deleteJson('/api/v1/account', ['confirm' => 'DELETE'])->assertOk();

        $this->assertSame(BusinessStatus::Suspended, $business->fresh()->status);
        $this->getJson("/api/v1/businesses/{$business->slug}")->assertNotFound();
    }

    public function test_admins_cannot_delete_themselves_from_the_app(): void
    {
        $admin = User::factory()->admin()->create(['status' => UserStatus::Active]);
        $this->as($admin)->deleteJson('/api/v1/account', ['confirm' => 'DELETE'])->assertForbidden();
    }
}
