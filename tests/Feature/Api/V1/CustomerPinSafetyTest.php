<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * With no OTP, a PIN is all that guards a customer's account. These cover the
 * three things that keep that from being thin: the obvious PINs are refused,
 * guessing one PIN across many numbers is capped, and a customer locked out has
 * a way back in that does not leave their PIN lying around.
 */
class CustomerPinSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingService::class)->set(['loginMobile' => true]);
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    private function signUp(string $pin, string $mobile = '9876543210'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/register-customer', [
            'name' => 'Asha',
            'mobile' => $mobile,
            'pin' => $pin,
        ]);
    }

    // --------------------------------------------------------- weak PINs

    public function test_the_pins_an_attacker_tries_first_are_refused(): void
    {
        // Sign-up is throttled to five a minute; this checks the PIN rule, so
        // the request-rate limit is out of the way.
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (['1234', '4321', '0000', '1111', '123456', '987654', '1212', '2580'] as $pin) {
            $this->signUp($pin)->assertUnprocessable()->assertJsonValidationErrors('pin');
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_an_ordinary_pin_is_accepted(): void
    {
        $this->signUp('4816')->assertCreated();
    }

    public function test_a_weak_pin_cannot_be_chosen_by_changing_it_either(): void
    {
        $this->signUp('4816')->assertCreated();
        $user = User::where('phone', '9876543210')->firstOrFail();

        $this->as($user)->postJson('/api/v1/auth/change-pin', ['currentPin' => '4816', 'newPin' => '1234'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('newPin');
    }

    // ---------------------------------------------------- PIN in plain text

    public function test_changing_a_customers_pin_never_stores_it_readable(): void
    {
        // The change-PIN path stored every user's PIN in plain text for the
        // admin to read -- right for owners, wrong for customers.
        $this->signUp('4816')->assertCreated();
        $user = User::where('phone', '9876543210')->firstOrFail();

        $this->as($user)->postJson('/api/v1/auth/change-pin', ['currentPin' => '4816', 'newPin' => '7391'])
            ->assertOk();

        $this->assertNull($user->fresh()->pin_plain);
        $this->assertTrue(Hash::check('7391', $user->fresh()->pin));
    }

    public function test_an_owners_changed_pin_stays_readable_for_the_admin(): void
    {
        $owner = User::factory()->businessOwner()->create(['phone' => '9000000055', 'pin' => '4816']);

        $this->as($owner)->postJson('/api/v1/auth/change-pin', ['currentPin' => '4816', 'newPin' => '7391'])
            ->assertOk();

        $this->assertSame('7391', $owner->fresh()->pin_plain);
    }

    // -------------------------------------------------------- spraying

    public function test_one_address_guessing_across_many_numbers_is_capped(): void
    {
        // The route's own per-minute throttle would stop this loop long before
        // the cap under test, so it is taken out of the way: what is being
        // tested is the failure cap, not the request-rate one.
        $this->withoutMiddleware(ThrottleRequests::class);

        // One wrong guess per number never trips any number's own lockout. The
        // per-address cap is what stops it.
        for ($i = 0; $i < 50; $i++) {
            $this->postJson('/api/v1/auth/pin-login', [
                'identifier' => '98000'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'pin' => '4816',
            ])->assertUnprocessable();
        }

        User::factory()->create(['role' => UserRole::Customer, 'phone' => '9811111111', 'pin' => '4816']);

        // Even the right PIN for a real account is refused from this address now.
        $this->postJson('/api/v1/auth/pin-login', ['identifier' => '9811111111', 'pin' => '4816'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pin');
    }

    // ------------------------------------------------------- admin reset

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_an_admin_can_get_a_locked_out_customer_back_in(): void
    {
        $this->signUp('4816')->assertCreated();
        $customer = User::where('phone', '9876543210')->firstOrFail();

        $pin = $this->as($this->admin())->postJson("/api/v1/admin/customers/{$customer->uuid}/reset-pin")
            ->assertOk()
            ->json('data.pin');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/pin-login', ['identifier' => '9876543210', 'pin' => $pin])->assertOk();
        // And the forgotten one no longer works.
        $this->postJson('/api/v1/auth/pin-login', ['identifier' => '9876543210', 'pin' => '4816'])->assertUnprocessable();
    }

    public function test_a_reset_signs_out_whoever_held_the_account(): void
    {
        // If someone else signed up with this number, the reset takes it back.
        $this->signUp('4816')->assertCreated();
        $customer = User::where('phone', '9876543210')->firstOrFail();
        $customer->createToken('squatter');
        $this->assertGreaterThan(0, $customer->tokens()->count());

        $this->as($this->admin())->postJson("/api/v1/admin/customers/{$customer->uuid}/reset-pin")->assertOk();

        $this->assertSame(0, $customer->tokens()->count());
    }

    public function test_the_new_pin_is_never_stored_readable_or_logged(): void
    {
        $this->signUp('4816')->assertCreated();
        $customer = User::where('phone', '9876543210')->firstOrFail();

        $pin = $this->as($this->admin())->postJson("/api/v1/admin/customers/{$customer->uuid}/reset-pin")
            ->json('data.pin');

        $this->assertNull($customer->fresh()->pin_plain);

        $log = AuditLog::where('action', 'customer.pin_reset')->firstOrFail();
        $this->assertStringNotContainsString($pin, json_encode($log->toArray()));
    }

    public function test_a_customer_with_no_number_cannot_be_given_a_pin(): void
    {
        // A Google-only customer: a PIN would open nothing.
        $customer = User::factory()->create(['role' => UserRole::Customer, 'phone' => null]);

        $this->as($this->admin())->postJson("/api/v1/admin/customers/{$customer->uuid}/reset-pin")
            ->assertStatus(422);
    }

    public function test_only_an_admin_can_reset_a_pin(): void
    {
        $this->signUp('4816')->assertCreated();
        $customer = User::where('phone', '9876543210')->firstOrFail();
        $owner = User::factory()->businessOwner()->create();

        $this->as($owner)->postJson("/api/v1/admin/customers/{$customer->uuid}/reset-pin")->assertForbidden();
    }

    public function test_an_admin_finds_a_locked_out_customer_by_their_number(): void
    {
        // They call in with a number, not an email -- a mobile sign-up has none.
        $this->signUp('4816')->assertCreated();

        $this->as($this->admin())->getJson('/api/v1/admin/customers?search=98765')
            ->assertOk()
            ->assertJsonPath('data.0.phone', '9876543210');
    }

    public function test_an_owner_cannot_be_reset_through_the_customer_endpoint(): void
    {
        $owner = User::factory()->businessOwner()->create(['phone' => '9000000066']);

        $this->as($this->admin())->postJson("/api/v1/admin/customers/{$owner->uuid}/reset-pin")->assertNotFound();
    }

    // ------------------------------------------------------- has a PIN

    public function test_the_app_knows_whether_an_account_has_a_pin(): void
    {
        $this->signUp('4816')->assertCreated()->assertJsonPath('data.user.hasPin', true);
    }
}
