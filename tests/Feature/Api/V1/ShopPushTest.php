<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\Favorite;
use App\Models\FeatureFlag;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Shop → customer push: off until the admin enables it, reaches only the
 * shop's own followers/customers, and respects the plan's monthly reach, one
 * send a day and quiet hours.
 */
class ShopPushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.business_timezone' => 'Asia/Kolkata']);
        // 11 AM in the shop's time zone — outside quiet hours.
        Carbon::setTestNow(Carbon::parse('2026-10-14 11:00:00', 'Asia/Kolkata')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function shop(): array
    {
        $owner = User::factory()->businessOwner()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);

        return [$owner, $business];
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);
    }

    private function token(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    private function enable(): void
    {
        FeatureFlag::where('key', 'owner_notifications')->update(['enabled' => true]);
    }

    public function test_it_is_off_until_the_admin_switches_it_on(): void
    {
        [$owner] = $this->shop();

        $this->token($owner)->getJson('/api/v1/business/push')
            ->assertOk()
            ->assertJsonPath('data.blockedReason', 'Customer notifications are not switched on for shops yet.');

        $this->token($owner)->postJson('/api/v1/business/push', ['audience' => 'followers', 'title' => 'Hi'])
            ->assertStatus(422);
    }

    public function test_followers_get_it_and_strangers_do_not(): void
    {
        $this->enable();
        [$owner, $business] = $this->shop();
        $follower = $this->customer();
        $buyer = $this->customer();
        $stranger = $this->customer();
        Favorite::create(['customer_id' => $follower->id, 'business_id' => $business->id]);
        Order::factory()->create(['business_id' => $business->id, 'customer_id' => $buyer->id]);

        $this->token($owner)->getJson('/api/v1/business/push')
            ->assertJsonPath('data.blockedReason', null)
            ->assertJsonPath('data.audiences.0.key', 'everyone')
            ->assertJsonPath('data.audiences.0.count', 2)
            ->assertJsonPath('data.audiences.1.count', 1);

        $this->token($owner)->postJson('/api/v1/business/push', [
            'audience' => 'followers',
            'title' => 'Fresh cakes at 5 PM',
            'body' => 'Come early!',
        ])->assertCreated()->assertJsonPath('data.recipients', 1);

        $this->assertDatabaseHas('notifications', ['user_id' => $follower->id, 'type' => 'shop_update']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $buyer->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $stranger->id]);
    }

    public function test_one_send_a_day_and_quiet_hours(): void
    {
        $this->enable();
        [$owner, $business] = $this->shop();
        Favorite::create(['customer_id' => $this->customer()->id, 'business_id' => $business->id]);

        $this->token($owner)->postJson('/api/v1/business/push', ['audience' => 'followers', 'title' => 'One'])
            ->assertCreated();
        $this->token($owner)->postJson('/api/v1/business/push', ['audience' => 'followers', 'title' => 'Two'])
            ->assertStatus(422);

        // Next day, but 11 PM — quiet hours.
        Carbon::setTestNow(Carbon::parse('2026-10-15 23:00:00', 'Asia/Kolkata')->utc());
        $this->token($owner)->postJson('/api/v1/business/push', ['audience' => 'followers', 'title' => 'Late'])
            ->assertStatus(422);
    }

    public function test_the_plan_reach_caps_who_gets_it(): void
    {
        $this->enable();
        Plan::factory()->create([
            'is_default' => true,
            'push_reach_per_month' => 2,
            'max_push_per_month' => null,
            'features' => ['push_notifications'],
        ]);
        [$owner, $business] = $this->shop();
        foreach (range(1, 3) as $_) {
            Favorite::create(['customer_id' => $this->customer()->id, 'business_id' => $business->id]);
        }

        $this->token($owner)->postJson('/api/v1/business/push', ['audience' => 'followers', 'title' => 'Hello'])
            ->assertCreated()
            ->assertJsonPath('data.recipients', 2);

        $this->token($owner)->getJson('/api/v1/business/push')
            ->assertJsonPath('data.reach.remaining', 0);
    }

    public function test_one_customer_gets_at_most_the_plans_number_a_month(): void
    {
        $this->enable();
        Plan::factory()->create([
            'is_default' => true,
            'push_reach_per_month' => null,
            'max_push_per_month' => null,
            'push_per_customer_per_month' => 1,
            'features' => ['push_notifications'],
        ]);
        [$owner, $business] = $this->shop();
        $old = $this->customer();
        Favorite::create(['customer_id' => $old->id, 'business_id' => $business->id]);

        $this->token($owner)->postJson('/api/v1/business/push', ['audience' => 'followers', 'title' => 'One'])
            ->assertCreated()->assertJsonPath('data.recipients', 1);

        // Next day: the old follower is capped, a new one still gets it.
        Carbon::setTestNow(Carbon::parse('2026-10-15 11:00:00', 'Asia/Kolkata')->utc());
        $new = $this->customer();
        Favorite::create(['customer_id' => $new->id, 'business_id' => $business->id]);

        $this->token($owner)->postJson('/api/v1/business/push', ['audience' => 'followers', 'title' => 'Two'])
            ->assertCreated()->assertJsonPath('data.recipients', 1);
        $this->assertDatabaseHas('shop_push_recipients', ['user_id' => $new->id]);
        $this->assertSame(1, \DB::table('shop_push_recipients')->where('user_id', $old->id)->count());

        $this->token($owner)->getJson('/api/v1/business/push/customers?audience=followers')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.customers.1.capped', true);

        // Next month the count starts again.
        Carbon::setTestNow(Carbon::parse('2026-11-02 11:00:00', 'Asia/Kolkata')->utc());
        $this->token($owner)->postJson('/api/v1/business/push', ['audience' => 'followers', 'title' => 'Three'])
            ->assertCreated()->assertJsonPath('data.recipients', 2);
    }

    public function test_the_list_masks_contact_details_unless_the_plan_shows_them(): void
    {
        $this->enable();
        $plan = Plan::factory()->create(['is_default' => true, 'features' => ['push_notifications']]);
        [$owner, $business] = $this->shop();
        $c = User::factory()->create([
            'role' => UserRole::Customer, 'status' => UserStatus::Active,
            'name' => 'Rahul', 'email' => 'rahul.k@gmail.com', 'phone' => '9876543210',
        ]);
        Favorite::create(['customer_id' => $c->id, 'business_id' => $business->id]);

        $this->token($owner)->getJson('/api/v1/business/push/customers')
            ->assertOk()
            ->assertJsonPath('data.customers.0.name', 'Rahul')
            ->assertJsonPath('data.customers.0.email', 'ra*****@gmail.com')
            ->assertJsonPath('data.customers.0.phone', '98******10');
        // Masked: the search can't find someone by a hidden email.
        $this->token($owner)->getJson('/api/v1/business/push/customers?search=rahul.k@')
            ->assertJsonPath('data.total', 0);

        $plan->update(['customer_details' => 'full']);
        $this->token($owner)->getJson('/api/v1/business/push/customers?search=98765')
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.customers.0.email', 'rahul.k@gmail.com')
            ->assertJsonPath('data.customers.0.phone', '9876543210');
    }

    public function test_the_owner_can_pick_customers_from_the_list(): void
    {
        $this->enable();
        [$owner, $business] = $this->shop();
        $a = $this->customer();
        $b = $this->customer();
        Favorite::create(['customer_id' => $a->id, 'business_id' => $business->id]);
        Favorite::create(['customer_id' => $b->id, 'business_id' => $business->id]);

        $this->token($owner)->postJson('/api/v1/business/push', [
            'audience' => 'followers', 'title' => 'Just you', 'customerIds' => [$b->uuid],
        ])->assertCreated()->assertJsonPath('data.recipients', 1);

        $this->assertDatabaseHas('notifications', ['user_id' => $b->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $a->id]);
    }

    public function test_outside_links_are_rejected(): void
    {
        $this->enable();
        [$owner] = $this->shop();

        $this->token($owner)->postJson('/api/v1/business/push', [
            'audience' => 'followers',
            'title' => 'Hi',
            'link' => 'https://evil.example',
        ])->assertStatus(422)->assertJsonValidationErrors('link');
    }

    public function test_the_owner_sees_who_a_sent_notification_went_to(): void
    {
        $this->enable();
        [$owner, $business] = $this->shop();
        $followers = collect(range(1, 12))->map(fn () => $this->customer());
        foreach ($followers as $f) {
            Favorite::create(['customer_id' => $f->id, 'business_id' => $business->id]);
        }

        $id = $this->token($owner)->postJson('/api/v1/business/push', ['audience' => 'followers', 'title' => 'Hello'])
            ->assertCreated()->json('data.id');

        $this->token($owner)->getJson("/api/v1/business/push/{$id}/recipients")
            ->assertOk()
            ->assertJsonPath('data.campaign.title', 'Hello')
            ->assertJsonPath('data.campaign.recipients', 12)
            ->assertJsonPath('data.total', 12)
            ->assertJsonPath('data.lastPage', 2)
            ->assertJsonCount(10, 'data.people');

        $this->token($owner)->getJson("/api/v1/business/push/{$id}/recipients?page=2")
            ->assertOk()->assertJsonCount(2, 'data.people');

        // Another shop can't look.
        [$other] = $this->shop();
        $this->token($other)->getJson("/api/v1/business/push/{$id}/recipients")->assertNotFound();
    }

    public function test_customer_lists_come_ten_at_a_time(): void
    {
        [$owner, $business] = $this->shop();
        foreach (range(1, 13) as $_) {
            Favorite::create(['customer_id' => $this->customer()->id, 'business_id' => $business->id]);
        }

        $this->token($owner)->getJson('/api/v1/business/push/customers?audience=followers&perPage=10')
            ->assertOk()->assertJsonCount(10, 'data.customers')->assertJsonPath('data.total', 13);
    }
}
