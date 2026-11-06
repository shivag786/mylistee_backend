<?php

namespace Tests\Feature\Api\V1;

use App\Enums\PaymentStatus;
use App\Models\Business;
use App\Models\Payment;
use App\Models\PersonalLinkGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Personal link: free, never expires — just an on/off switch. */
class PersonalLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['razorpay.webhook_secret' => 'whsec_test']);
    }

    /** @return array{0: User, 1: Business} */
    private function owner(): array
    {
        $owner = User::factory()->businessOwner()->create();

        return [$owner, Business::factory()->create(['owner_id' => $owner->id])];
    }

    public function test_switching_on_turns_on_store_mode_with_no_expiry(): void
    {
        [$owner, $business] = $this->owner();
        $this->actingAs($owner);

        $this->getJson('/api/v1/business/personal-link')
            ->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.neverExpires', true)
            ->assertJsonPath('data.packs', []);

        $this->putJson('/api/v1/business/personal-link', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.expiresAt', null);

        $this->getJson("/api/v1/businesses/{$business->slug}")->assertJsonPath('data.business.storeMode', true);

        // Still on years later — it never runs out.
        $this->travel(800)->days();
        $this->getJson('/api/v1/business/personal-link')->assertJsonPath('data.active', true);

        $this->putJson('/api/v1/business/personal-link', ['enabled' => false])->assertJsonPath('data.active', false);
        $this->getJson("/api/v1/businesses/{$business->slug}")->assertJsonPath('data.business.storeMode', false);
    }

    public function test_a_shop_whose_old_paid_days_ran_out_stays_live(): void
    {
        [$owner, $business] = $this->owner();
        $business->forceFill(['personal_link_enabled' => true, 'personal_link_expires_at' => now()->subMonth()])->save();

        $this->actingAs($owner)->getJson('/api/v1/business/personal-link')->assertJsonPath('data.active', true);
    }

    public function test_packs_can_no_longer_be_bought(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->postJson('/api/v1/business/personal-link/checkout', ['pack' => 'month'])->assertNotFound();
    }

    public function test_admin_lists_shops_by_whether_their_link_is_on(): void
    {
        [, $on] = $this->owner();
        [, $off] = $this->owner();
        $on->forceFill(['personal_link_enabled' => true])->save();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/api/v1/admin/personal-links?status=on')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $on->uuid)
            ->assertJsonPath('data.0.enabled', true);
        $this->getJson('/api/v1/admin/personal-links?status=off')->assertJsonPath('data.0.id', $off->uuid);
    }

    public function test_an_old_payment_link_paid_later_is_still_recorded_once(): void
    {
        [, $business] = $this->owner();
        $payment = Payment::create([
            'business_id' => $business->id,
            'purpose' => 'personal_link',
            'gateway' => 'razorpay',
            'gateway_order_id' => 'plink_WH',
            'receipt' => 'plg_test',
            'status' => PaymentStatus::Created,
            'amount' => 1499,
            'amount_paise' => 149900,
            'currency' => 'INR',
            'meta' => ['pack' => 'year', 'days' => 365],
        ]);
        PersonalLinkGrant::create([
            'business_id' => $business->id, 'source' => 'admin_link', 'status' => 'pending',
            'pack' => 'year', 'days' => 365, 'amount' => 1499, 'payment_id' => $payment->id, 'link_id' => 'plink_WH',
        ]);

        $body = json_encode(['event' => 'payment_link.paid', 'payload' => [
            'payment_link' => ['entity' => ['id' => 'plink_WH', 'status' => 'paid']],
            'payment' => ['entity' => ['id' => 'pay_WH', 'order_id' => 'order_WH', 'amount' => 149900, 'status' => 'captured', 'method' => 'card']],
        ]]);
        $send = fn () => $this->call('POST', '/api/v1/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'whsec_test'),
        ], $body);

        $send()->assertOk()->assertJsonPath('data.result', 'captured');
        $send()->assertOk()->assertJsonPath('data.result', 'duplicate');

        $this->assertSame(PaymentStatus::Captured, $payment->refresh()->status);
        $this->assertSame('active', PersonalLinkGrant::first()->status);
        $this->assertTrue($business->refresh()->hasActivePersonalLink());
    }
}
