<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\Favorite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Customer profile, the people they celebrate, and occasion reminders. */
class CustomerProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.business_timezone' => 'Asia/Kolkata']);
        Carbon::setTestNow(Carbon::parse('2026-10-29 08:00:00', 'Asia/Kolkata')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customer(array $attrs = []): User
    {
        return User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active] + $attrs);
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    public function test_a_customer_fills_in_their_profile(): void
    {
        $user = $this->customer(['phone' => null]);

        $this->as($user)->putJson('/api/v1/profile', [
            'phone' => '9876501234',
            'dob' => '1995-03-12',
            'gender' => 'female',
            'anniversary' => '2020-11-01',
        ])->assertOk()
            ->assertJsonPath('data.phone', '9876501234')
            ->assertJsonPath('data.phoneLocked', true)
            ->assertJsonPath('data.dob', '1995-03-12')
            ->assertJsonPath('data.gender', 'female');

        // The mobile can't be swapped afterwards.
        $this->as($user)->putJson('/api/v1/profile', ['phone' => '9876509999'])->assertUnprocessable();
        // Nor taken from another account.
        $this->as($this->customer(['phone' => null]))->putJson('/api/v1/profile', ['phone' => '9876501234'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_people_need_a_date_and_stay_private(): void
    {
        $user = $this->customer();

        $this->as($user)->postJson('/api/v1/profile/people', ['name' => 'Riya'])
            ->assertUnprocessable()->assertJsonValidationErrors('dob');

        $id = $this->as($user)->postJson('/api/v1/profile/people', [
            'name' => 'Riya', 'relation' => 'Sister', 'gender' => 'female', 'dob' => '2001-10-31',
        ])->assertCreated()->json('data.id');

        $this->as($user)->getJson('/api/v1/profile')->assertJsonPath('data.people.0.name', 'Riya');
        $this->as($this->customer())->deleteJson("/api/v1/profile/people/{$id}")->assertNotFound();
        $this->as($user)->deleteJson("/api/v1/profile/people/{$id}")->assertOk();
    }

    public function test_reminders_go_two_days_ahead_once_a_year(): void
    {
        $user = $this->customer(['dob' => '1990-10-31']);
        $user->people()->create(['name' => 'Riya', 'dob' => '2001-10-31']);
        $user->people()->create(['name' => 'Aman', 'dob' => '2001-11-05']);
        $shop = Business::factory()->create(['name' => 'Cake Corner']);
        Favorite::create(['customer_id' => $user->id, 'business_id' => $shop->id]);
        $this->as(User::find($shop->owner_id))->putJson('/api/v1/business/rewards', [
            'bill_discount' => ['type' => 'flat', 'value' => 50, 'min_bill' => 300],
            'comeback' => null,
            'loyalty' => null,
        ])->assertOk();

        $this->artisan('occasions:remind')->assertSuccessful();

        $titles = $user->appNotifications()->pluck('title')->all();
        $this->assertCount(2, $titles);
        $this->assertContains('Riya’s birthday is on 31 Oct 🎂', $titles);
        $this->assertContains('Your birthday is coming up 🎉', $titles);
        $this->assertStringContainsString('Cake Corner: ₹50 off', $user->appNotifications()->first()->body);

        // Running again the same day sends nothing new.
        $this->artisan('occasions:remind')->assertSuccessful();
        $this->assertSame(2, $user->appNotifications()->count());
    }

    public function test_shops_can_reach_followers_with_an_occasion_this_week(): void
    {
        $owner = User::factory()->businessOwner()->create();
        $shop = Business::factory()->create(['owner_id' => $owner->id]);
        $soon = $this->customer();
        $soon->people()->create(['name' => 'Mom', 'anniversary' => '1990-11-02']);
        $later = $this->customer(['dob' => '1990-12-25']);
        foreach ([$soon, $later] as $c) {
            Favorite::create(['customer_id' => $c->id, 'business_id' => $shop->id]);
        }

        $this->as($owner)->getJson('/api/v1/business/push/customers?audience=occasions')
            ->assertOk()->assertJsonPath('data.total', 1);
    }
}
