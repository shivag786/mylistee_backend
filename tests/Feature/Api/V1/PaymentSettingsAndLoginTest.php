<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Business;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The admin's payment and login settings, and the shop's payment choices.
 *
 * The admin side is mostly about what must never happen: a Razorpay secret
 * leaving the server, a blank form field wiping one, or every sign-in method
 * being switched off at once.
 */
class PaymentSettingsAndLoginTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    // ------------------------------------------------------- Razorpay keys

    public function test_the_admin_api_never_hands_a_secret_back(): void
    {
        $admin = $this->admin();

        $this->as($admin)->putJson('/api/v1/admin/settings', [
            'razorpayKeyId' => 'rzp_live_ABC123',
            'razorpayKeySecret' => 'super-secret-value',
            'razorpayWebhookSecret' => 'webhook-secret-value',
        ])->assertOk();

        $response = $this->as($admin)->getJson('/api/v1/admin/settings')
            ->assertOk()
            ->assertJsonPath('data.razorpayKeyId', 'rzp_live_ABC123')
            ->assertJsonPath('data.razorpayKeySecret', '')
            ->assertJsonPath('data.razorpayKeySecretSet', true);

        $this->assertStringNotContainsString('super-secret-value', $response->getContent());
        $this->assertStringNotContainsString('webhook-secret-value', $response->getContent());
    }

    public function test_saving_with_a_blank_secret_keeps_the_stored_one(): void
    {
        // The form never has the stored secret to send back, so blank must mean
        // "leave it" -- or saving any other field would wipe the gateway.
        $admin = $this->admin();
        $this->as($admin)->putJson('/api/v1/admin/settings', ['razorpayKeySecret' => 'keep-me'])->assertOk();

        $this->as($admin)->putJson('/api/v1/admin/settings', [
            'razorpayKeySecret' => '',
            'razorpayFeePercent' => 2.5,
        ])->assertOk();

        $this->assertSame('keep-me', app(SettingService::class)->get('razorpayKeySecret'));
    }

    public function test_the_keys_saved_by_the_admin_are_the_ones_used(): void
    {
        config(['razorpay.key_id' => null, 'razorpay.key_secret' => null]);
        $this->as($this->admin())->putJson('/api/v1/admin/settings', [
            'razorpayKeyId' => 'rzp_test_FROMPANEL',
            'razorpayKeySecret' => 'from-panel',
        ])->assertOk();

        $this->getJson('/api/v1/config')->assertJsonPath('data.payments.razorpay', true);
    }

    public function test_a_key_that_is_not_a_razorpay_key_is_refused(): void
    {
        $this->as($this->admin())->putJson('/api/v1/admin/settings', ['razorpayKeyId' => 'sk_live_123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('razorpayKeyId');
    }

    public function test_a_fee_typo_cannot_multiply_every_bill(): void
    {
        $this->as($this->admin())->putJson('/api/v1/admin/settings', ['razorpayFeePercent' => 25])
            ->assertUnprocessable();
    }

    public function test_only_an_admin_can_change_payment_settings(): void
    {
        $owner = User::factory()->businessOwner()->create();

        $this->as($owner)->putJson('/api/v1/admin/settings', ['razorpayKeySecret' => 'x'])->assertForbidden();
    }

    // ------------------------------------------------------ login methods

    public function test_every_sign_in_method_cannot_be_switched_off(): void
    {
        $admin = $this->admin();

        $this->as($admin)->putJson('/api/v1/admin/settings', ['loginGoogle' => false, 'loginMobile' => false])
            ->assertOk()
            ->assertJsonPath('data.loginGoogle', true);
    }

    public function test_switching_off_the_last_method_is_caught_across_saves(): void
    {
        // Mobile is off by default; turning Google off alone would leave nothing.
        $this->as($this->admin())->putJson('/api/v1/admin/settings', ['loginGoogle' => false])
            ->assertOk()
            ->assertJsonPath('data.loginGoogle', true);
    }

    public function test_the_login_page_learns_which_methods_are_on(): void
    {
        $this->as($this->admin())->putJson('/api/v1/admin/settings', ['loginMobile' => true])->assertOk();

        $this->getJson('/api/v1/config')
            ->assertJsonPath('data.auth.google', true)
            ->assertJsonPath('data.auth.mobile', true);
    }

    // --------------------------------------------------- customer mobile + PIN

    public function test_mobile_sign_up_is_refused_while_it_is_switched_off(): void
    {
        // Hiding the form is not enough -- the API is callable directly.
        $this->postJson('/api/v1/auth/register-customer', [
            'name' => 'Asha',
            'mobile' => '9876543210',
            'pin' => '4816',
        ])->assertForbidden();
    }

    public function test_a_customer_signs_up_and_back_in_with_mobile_and_pin(): void
    {
        app(SettingService::class)->set(['loginMobile' => true]);

        $this->postJson('/api/v1/auth/register-customer', [
            'name' => 'Asha',
            'mobile' => '9876543210',
            'pin' => '4816',
        ])->assertCreated()->assertJsonPath('data.user.role', 'customer');

        $user = User::where('phone', '9876543210')->firstOrFail();
        // Hashed, and not kept in plain text the way owner PINs are.
        $this->assertTrue(Hash::check('4816', $user->pin));
        $this->assertNull($user->pin_plain);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/pin-login', ['identifier' => '9876543210', 'pin' => '4816'])
            ->assertOk();
    }

    public function test_a_wrong_pin_does_not_let_a_customer_in(): void
    {
        app(SettingService::class)->set(['loginMobile' => true]);
        $this->postJson('/api/v1/auth/register-customer', [
            'name' => 'Asha', 'mobile' => '9876543210', 'pin' => '4816',
        ])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/pin-login', ['identifier' => '9876543210', 'pin' => '0000'])
            ->assertUnprocessable();
    }

    public function test_switching_mobile_off_never_locks_staff_out(): void
    {
        // Owners and admins sign in with mobile + PIN through the same endpoint.
        // The customer toggle must not reach them.
        $owner = User::factory()->businessOwner()->create(['phone' => '9000000077', 'pin' => '1234']);
        app(SettingService::class)->set(['loginMobile' => false]);

        $this->postJson('/api/v1/auth/pin-login', ['identifier' => '9000000077', 'pin' => '1234'])
            ->assertOk();
    }

    // -------------------------------------------------- shop payment choices

    public function test_an_owner_sets_a_partial_advance_and_turns_cash_off(): void
    {
        $owner = User::factory()->businessOwner()->create();
        Business::factory()->create(['owner_id' => $owner->id]);

        $this->as($owner)->putJson('/api/v1/business/service-settings', [
            'modes' => ['pickup'],
            'defaultMode' => 'pickup',
            'paymentMode' => 'partial',
            'partialPercent' => 30,
            'codEnabled' => false,
        ])->assertOk()
            ->assertJsonPath('data.paymentMode', 'partial')
            ->assertJsonPath('data.partialPercent', 30)
            ->assertJsonPath('data.codEnabled', false);
    }

    public function test_an_advance_outside_the_sensible_range_is_refused(): void
    {
        $owner = User::factory()->businessOwner()->create();
        Business::factory()->create(['owner_id' => $owner->id]);

        foreach ([5, 95] as $percent) {
            $this->as($owner)->putJson('/api/v1/business/service-settings', [
                'modes' => ['pickup'],
                'defaultMode' => 'pickup',
                'paymentMode' => 'partial',
                'partialPercent' => $percent,
            ])->assertUnprocessable()->assertJsonValidationErrors('partialPercent');
        }
    }

    public function test_saving_service_modes_alone_keeps_payment_choices(): void
    {
        // An older owner client knows nothing about payment fields.
        $owner = User::factory()->businessOwner()->create();
        Business::factory()->create(['owner_id' => $owner->id]);
        $this->as($owner)->putJson('/api/v1/business/service-settings', [
            'modes' => ['pickup'], 'defaultMode' => 'pickup',
            'paymentMode' => 'partial', 'partialPercent' => 40, 'codEnabled' => false,
        ])->assertOk();

        $this->as($owner)->putJson('/api/v1/business/service-settings', [
            'modes' => ['pickup'], 'defaultMode' => 'pickup',
        ])->assertOk()
            ->assertJsonPath('data.paymentMode', 'partial')
            ->assertJsonPath('data.codEnabled', false);
    }
}
