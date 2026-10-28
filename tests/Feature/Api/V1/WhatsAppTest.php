<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShopCustomer;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\WhatsAppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WhatsApp through Interakt: the admin connects the account, makes and syncs
 * templates, maps automatic messages; orders and forgot-PIN send through it;
 * shops send ready-made offers within their plan; the webhook keeps statuses.
 */
class WhatsAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.business_timezone' => 'Asia/Kolkata']);
        Carbon::setTestNow(Carbon::parse('2026-10-28 11:00:00', 'Asia/Kolkata')->utc());
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function token(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function connect(array $events = []): void
    {
        app(WhatsAppSettings::class)->update(['enabled' => true, 'apiKey' => 'secret-key', 'webhookSecret' => 'hook-secret', 'events' => $events]);
    }

    private function template(array $attrs = []): WhatsAppTemplate
    {
        return WhatsAppTemplate::create($attrs + [
            'name' => 'listee_order_accepted',
            'language' => 'en',
            'category' => 'UTILITY',
            'body' => 'Hi {{1}}, {{2}} accepted your order *{{3}}*.',
            'variables' => ['header' => null, 'body' => [['source' => 'customer_name'], ['source' => 'shop_name'], ['source' => 'order_token']], 'buttons' => []],
            'status' => 'APPROVED',
        ]);
    }

    private function fakeSend(): void
    {
        Http::fake(['api.interakt.ai/v1/public/message/' => Http::response(['result' => true, 'id' => 'msg-1'])]);
    }

    /** @return list<array<string, mixed>> bodies of the send calls */
    private function sends(): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/message/'))
            ->map(fn ($pair) => $pair[0]->data())->values()->all();
    }

    public function test_the_api_key_is_stored_encrypted_and_never_returned(): void
    {
        $admin = $this->admin();

        $this->token($admin)->putJson('/api/v1/admin/whatsapp', ['enabled' => true])
            ->assertStatus(422);

        $this->token($admin)->putJson('/api/v1/admin/whatsapp', ['apiKey' => 'secret-key', 'enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.settings.apiKeySet', true)
            ->assertJsonPath('data.settings.enabled', true)
            ->assertJsonMissing(['apiKey' => 'secret-key']);

        $stored = Setting::where('key', 'whatsapp')->value('value');
        $this->assertStringNotContainsString('secret-key', json_encode($stored));

        // Saving the switch alone keeps the key.
        $this->token($admin)->putJson('/api/v1/admin/whatsapp', ['enabled' => false])->assertOk();
        $this->assertSame('secret-key', app(WhatsAppSettings::class)->apiKey());
    }

    public function test_a_preset_is_submitted_to_interakt_for_approval(): void
    {
        $this->connect();
        Http::fake(['api.interakt.ai/v1/public/track/templates/' => Http::response([
            'result' => true,
            'data' => ['id' => 'tpl-1', 'name' => 'listee_shop_discount', 'language' => 'en', 'category' => 'MARKETING', 'approval_status' => 'WAITING', 'wa_template_id' => '999'],
        ])]);

        $this->token($this->admin())->postJson('/api/v1/admin/whatsapp/templates', [
            'name' => 'listee_shop_discount',
            'category' => 'MARKETING',
            'body' => "Hi {{1}} 👋\n\nGet *{{2}}* at {{3}}.",
            'bodyVars' => [
                ['source' => 'customer_name'],
                ['source' => 'custom', 'label' => 'Discount', 'sample' => '15% off'],
                ['source' => 'shop_name'],
            ],
            'footer' => 'Reply STOP to stop offers',
            'buttons' => [['type' => 'URL', 'text' => 'Order now', 'url' => 'https://listee.org/b/{{1}}', 'var' => ['source' => 'shop_slug']]],
            'forShops' => true,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'WAITING')
            ->assertJsonPath('data.forShops', true);

        Http::assertSent(function (HttpRequest $r) {
            return $r->hasHeader('Authorization', 'Basic secret-key')
                && $r['category'] === 'Marketing'
                && $r['language'] === 'English'
                && $r['body_text'] === ['Priya', '15% off', 'Sharma Sweets']
                && $r['button_type'] === 'Call To Action'
                && $r['button_text'] === 'https://listee.org/b/sharma-sweets';
        });
        $this->assertSame('tpl-1', WhatsAppTemplate::first()->interakt_id);
    }

    public function test_placeholders_must_be_numbered_and_each_given_a_value(): void
    {
        $this->connect();
        Http::fake();

        $this->token($this->admin())->postJson('/api/v1/admin/whatsapp/templates', [
            'name' => 'bad',
            'category' => 'UTILITY',
            'body' => 'Hello {{1}} and {{3}}.',
            'bodyVars' => [['source' => 'customer_name'], ['source' => 'shop_name']],
        ])->assertStatus(422)->assertJsonValidationErrors('body');

        Http::assertNothingSent();
    }

    public function test_sync_brings_in_otp_templates_already_mapped_to_the_code(): void
    {
        $this->connect();
        Http::fake(['api.interakt.ai/v1/public/track/organization/templates*' => Http::response([
            'count' => 1,
            'has_next' => false,
            'results' => ['templates' => [[
                'id' => 'auth-1',
                'name' => 'itk_auth_one_tap',
                'language' => 'en',
                'category' => 'AUTHENTICATION',
                'body' => '*{{1}}* is your verification code.',
                'buttons' => '"[{\"type\": \"OTP\", \"otp_type\": \"COPY_CODE\", \"text\": \"Copy code\"}]"',
                'approval_status' => 'APPROVED',
                'wa_template_id' => '555',
            ]]],
        ])]);

        $this->token($this->admin())->postJson('/api/v1/admin/whatsapp/sync')
            ->assertOk()
            ->assertJsonPath('data.sync.added', 1);

        $template = WhatsAppTemplate::where('name', 'itk_auth_one_tap')->firstOrFail();
        $this->assertSame('APPROVED', $template->status);
        $this->assertSame([['source' => 'otp_code']], $template->variables['body']);
        $this->assertSame(['source' => 'otp_code'], $template->variables['buttons']['0']);
    }

    public function test_forgot_pin_sends_a_code_on_whatsapp_and_resets_the_pin(): void
    {
        $otp = $this->template([
            'name' => 'itk_auth_one_tap',
            'category' => 'AUTHENTICATION',
            'body' => '*{{1}}* is your verification code.',
            'variables' => ['header' => null, 'body' => [['source' => 'otp_code']], 'buttons' => ['0' => ['source' => 'otp_code']]],
        ]);
        $user = User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active, 'phone' => '9876543210', 'pin' => '2580']);
        $this->getJson('/api/v1/config')->assertJsonPath('data.auth.whatsappOtp', false);
        $this->connect(['otp' => $otp->uuid]);
        $this->fakeSend();

        $this->getJson('/api/v1/config')->assertJsonPath('data.auth.whatsappOtp', true);
        $this->postJson('/api/v1/auth/pin/forgot', ['mobile' => '98765 43210'])->assertOk();

        $sent = $this->sends();
        $this->assertCount(1, $sent);
        $this->assertSame('9876543210', $sent[0]['phoneNumber']);
        $code = $sent[0]['template']['bodyValues'][0];
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertSame(['0' => [$code]], $sent[0]['template']['buttonValues']);

        $this->postJson('/api/v1/auth/pin/reset', ['mobile' => '9876543210', 'code' => '000000', 'pin' => '7391'])
            ->assertStatus(422);
        $this->postJson('/api/v1/auth/pin/reset', ['mobile' => '9876543210', 'code' => $code, 'pin' => '7391'])
            ->assertOk();
        $this->postJson('/api/v1/auth/pin-login', ['identifier' => $user->phone, 'pin' => '7391'])->assertOk();

        // The code works once.
        $this->postJson('/api/v1/auth/pin/reset', ['mobile' => '9876543210', 'code' => $code, 'pin' => '1593'])
            ->assertStatus(422);
    }

    public function test_an_unknown_number_gets_the_same_answer_and_no_message(): void
    {
        $otp = $this->template(['name' => 'otp', 'category' => 'AUTHENTICATION', 'body' => 'Code {{1}}.', 'variables' => ['body' => [['source' => 'otp_code']]]]);
        $this->connect(['otp' => $otp->uuid]);
        $this->fakeSend();

        $this->postJson('/api/v1/auth/pin/forgot', ['mobile' => '9123456789'])->assertOk();
        $this->assertSame([], $this->sends());
    }

    public function test_orders_message_both_the_shop_and_the_customer(): void
    {
        $owner = User::factory()->businessOwner()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id, 'name' => 'Sharma Sweets', 'whatsapp' => '9000000001']);
        $product = Product::factory()->create(['business_id' => $business->id, 'selling_price' => 120]);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active, 'name' => 'Priya', 'phone' => '9000000002']);

        $toShop = $this->template([
            'name' => 'listee_new_order_shop',
            'body' => 'New order {{1}}: {{2}} · {{3}} from {{4}}.',
            'variables' => ['body' => [['source' => 'order_token'], ['source' => 'order_total'], ['source' => 'order_items'], ['source' => 'customer_name']]],
        ]);
        $accepted = $this->template();
        $this->connect(['order_placed_owner' => $toShop->uuid, 'order_accepted' => $accepted->uuid]);
        $this->fakeSend();

        $order = $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => 2]],
        ])->assertCreated()->json('data');

        $sent = $this->sends();
        $this->assertCount(1, $sent);
        $this->assertSame('9000000001', $sent[0]['phoneNumber']);
        $this->assertSame([$order['token'], '₹240', '2 items', 'Priya'], $sent[0]['template']['bodyValues']);

        $this->token($owner)->patchJson("/api/v1/business/orders/{$order['id']}/status", ['status' => 'confirmed'])->assertOk();

        // (After-response jobs of earlier test requests re-run on later ones, so
        // look for the customer's message rather than counting.)
        $toCustomer = collect($this->sends())->firstWhere('phoneNumber', '9000000002');
        $this->assertNotNull($toCustomer);
        $this->assertSame('listee_order_accepted', $toCustomer['template']['name']);
        $this->assertSame(['Priya', 'Sharma Sweets', $order['token']], $toCustomer['template']['bodyValues']);
        $this->assertSame(0, WhatsAppMessage::where('status', 'failed')->count());
    }

    public function test_nothing_is_sent_while_whatsapp_is_off_or_no_template_is_set(): void
    {
        $owner = User::factory()->businessOwner()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id, 'whatsapp' => '9000000001']);
        $product = Product::factory()->create(['business_id' => $business->id]);
        $customer = User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);
        Http::fake();

        $this->token($customer)->postJson('/api/v1/orders', [
            'business' => $business->slug,
            'items' => [['type' => 'product', 'id' => $product->uuid, 'quantity' => 1]],
        ])->assertCreated();

        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppMessage::count());
    }

    private function offerShop(?int $perMonth): array
    {
        Plan::factory()->create(['is_default' => true, 'modules' => ['whatsapp' => ['on' => true]], 'whatsapp_per_month' => $perMonth]);
        $owner = User::factory()->businessOwner()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id, 'name' => 'Sharma Sweets']);
        $offer = $this->template([
            'name' => 'listee_shop_discount',
            'category' => 'MARKETING',
            'body' => 'Hi {{1}}, get {{2}} at {{3}}.',
            'buttons' => [['type' => 'URL', 'text' => 'Order now', 'url' => 'https://listee.org/b/{{1}}']],
            'variables' => [
                'body' => [['source' => 'customer_name'], ['source' => 'custom', 'label' => 'Discount'], ['source' => 'shop_name']],
                'buttons' => ['0' => ['source' => 'shop_slug']],
            ],
            'for_shops' => true,
        ]);

        return [$owner, $business, $offer];
    }

    public function test_a_shop_sends_an_offer_to_its_customer_book_within_its_plan(): void
    {
        [$owner, $business, $offer] = $this->offerShop(2);
        $this->connect();
        $this->fakeSend();
        foreach (['9111111111', '9222222222', '9333333333'] as $i => $phone) {
            ShopCustomer::create(['business_id' => $business->id, 'phone' => $phone, 'name' => "C{$i}", 'source' => 'manual']);
        }

        $this->token($owner)->getJson('/api/v1/business/whatsapp')
            ->assertOk()
            ->assertJsonPath('data.blockedReason', null)
            ->assertJsonPath('data.quota.remaining', 2)
            ->assertJsonPath('data.templates.0.fields.0.label', 'Discount');

        $this->token($owner)->postJson('/api/v1/business/whatsapp', ['template' => $offer->uuid, 'audience' => 'book'])
            ->assertStatus(422)->assertJsonValidationErrors('values.body.1');

        $this->token($owner)->postJson('/api/v1/business/whatsapp', [
            'template' => $offer->uuid,
            'audience' => 'book',
            'values' => ['body.1' => '20% off'],
        ])->assertCreated()->assertJsonPath('data.recipients', 2);

        $sent = $this->sends();
        $this->assertCount(2, $sent);
        $this->assertSame(['C2', '20% off', 'Sharma Sweets'], $sent[0]['template']['bodyValues']);
        $this->assertSame(['0' => [$business->slug]], $sent[0]['template']['buttonValues']);

        // Quota used up; and only one send a day anyway.
        $this->token($owner)->getJson('/api/v1/business/whatsapp')
            ->assertJsonPath('data.quota.remaining', 0)
            ->assertJsonPath('data.blockedReason', 'All 2 WhatsApp messages your plan allows this month are used.');
    }

    public function test_a_plan_without_whatsapp_cannot_send(): void
    {
        Plan::factory()->create(['is_default' => true, 'modules' => ['ordering' => ['on' => true]]]);
        $owner = User::factory()->businessOwner()->create();
        Business::factory()->create(['owner_id' => $owner->id]);
        $this->connect();

        $this->token($owner)->getJson('/api/v1/business/whatsapp')
            ->assertJsonPath('data.blockedReason', 'Your plan does not include WhatsApp offers. Upgrade to send them.');
    }

    public function test_numbers_that_replied_stop_get_no_offers(): void
    {
        [$owner, $business, $offer] = $this->offerShop(null);
        $this->connect();
        $this->fakeSend();
        ShopCustomer::create(['business_id' => $business->id, 'phone' => '9111111111', 'name' => 'A', 'source' => 'manual']);
        ShopCustomer::create(['business_id' => $business->id, 'phone' => '9222222222', 'name' => 'B', 'source' => 'manual']);

        $this->webhook(['type' => 'message_received', 'data' => ['customer' => ['channel_phone_number' => '919111111111'], 'message' => ['message' => 'Stop']]])
            ->assertOk();

        $this->token($owner)->postJson('/api/v1/business/whatsapp', [
            'template' => $offer->uuid, 'audience' => 'book', 'values' => ['body.1' => '10% off'],
        ])->assertCreated()->assertJsonPath('data.recipients', 1);
        $this->assertSame('9222222222', $this->sends()[0]['phoneNumber']);
    }

    private function webhook(array $payload, ?string $secret = 'hook-secret'): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode($payload);

        return $this->call('POST', '/api/v1/webhooks/interakt', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_INTERAKT_SIGNATURE' => 'sha256='.hash_hmac('sha256', $raw, (string) $secret),
        ], $raw);
    }

    public function test_the_webhook_needs_a_valid_signature_and_updates_statuses(): void
    {
        $this->connect();
        $template = $this->template(['status' => 'WAITING', 'wa_template_id' => '777']);
        $message = WhatsAppMessage::create(['template_id' => $template->id, 'purpose' => 'test', 'phone' => '9111111111', 'status' => 'sent', 'interakt_message_id' => 'm-1']);

        $this->webhook(['type' => 'message_api_read', 'data' => ['message' => ['id' => 'm-1']]], 'wrong')->assertStatus(401);

        $this->webhook(['type' => 'message_api_read', 'data' => ['message' => ['id' => 'm-1']]])->assertOk();
        // A late "delivered" never moves it back.
        $this->webhook(['type' => 'message_api_delivered', 'data' => ['message' => ['id' => 'm-1']]])->assertOk();
        $this->assertSame('read', $message->fresh()->status);

        $this->webhook(['type' => 'message_template_status_update', 'data' => ['event' => 'REJECTED', 'message_template_id' => 777, 'reason' => 'INVALID_FORMAT']])->assertOk();
        $this->assertSame('REJECTED', $template->fresh()->status);
        $this->assertSame('INVALID_FORMAT', $template->fresh()->rejection_reason);
    }
}
