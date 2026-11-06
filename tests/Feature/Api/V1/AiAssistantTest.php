<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\User;
use App\Services\SettingService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The vendor app's AI assistant: admin switch, plan module, monthly limit. */
class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }

    /** @return array{0: User, 1: Business} */
    private function shop(): array
    {
        $owner = User::factory()->businessOwner()->create();

        return [$owner, Business::factory()->create(['owner_id' => $owner->id, 'name' => 'Chai Point'])];
    }

    private function aiInFreePlan(bool $on = true): void
    {
        $modules = collect(array_keys(config('plan_modules')))
            ->mapWithKeys(fn ($id) => [$id => ['on' => $id === 'ai_assistant' ? $on : true, 'days' => null]])
            ->all();
        $this->as(User::factory()->admin()->create())
            ->patchJson('/api/v1/admin/plans/free', ['modules' => $modules])
            ->assertOk();
    }

    private function fakeOpenAi(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode(['suggestions' => [
                    ['title' => 'Chai happy hour', 'body' => '20% off 4–6 pm'],
                    ['title' => 'Free biscuit', 'body' => 'With every 2 chais'],
                ]])]]],
            ]),
        ]);
    }

    public function test_owner_gets_suggestions_when_on_and_in_plan(): void
    {
        app(SettingService::class)->set(['aiEnabled' => true, 'openaiApiKey' => 'sk-test', 'aiMonthlyLimit' => 5]);
        $this->aiInFreePlan();
        $this->fakeOpenAi();
        [$owner] = $this->shop();

        $this->as($owner)->getJson('/api/v1/business/ai')
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.inPlan', true)
            ->assertJsonPath('data.used', 0);

        $this->as($owner)->postJson('/api/v1/business/ai/generate', ['kind' => 'sales_tips'])
            ->assertOk()
            ->assertJsonCount(2, 'data.suggestions')
            ->assertJsonPath('data.suggestions.0.title', 'Chai happy hour')
            ->assertJsonPath('data.used', 1);

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer sk-test')
            && str_contains(json_encode($r->data()), 'Chai Point'));
    }

    public function test_refused_when_not_in_plan_or_switched_off(): void
    {
        $this->fakeOpenAi();
        [$owner] = $this->shop();

        // On, but the plan has no AI module (unset plans never include it).
        app(SettingService::class)->set(['aiEnabled' => true, 'openaiApiKey' => 'sk-test']);
        $this->as($owner)->getJson('/api/v1/business/ai')->assertOk()->assertJsonPath('data.inPlan', false);
        $this->as($owner)->postJson('/api/v1/business/ai/generate', ['kind' => 'sales_tips'])->assertForbidden();

        // In the plan, but the admin switched it off.
        $this->aiInFreePlan();
        app(SettingService::class)->set(['aiEnabled' => false]);
        $this->as($owner)->postJson('/api/v1/business/ai/generate', ['kind' => 'sales_tips'])->assertStatus(503);

        Http::assertNothingSent();
    }

    public function test_monthly_limit_and_required_input(): void
    {
        app(SettingService::class)->set(['aiEnabled' => true, 'openaiApiKey' => 'sk-test', 'aiMonthlyLimit' => 1]);
        $this->aiInFreePlan();
        $this->fakeOpenAi();
        [$owner] = $this->shop();

        $this->as($owner)->postJson('/api/v1/business/ai/generate', ['kind' => 'product_description'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name', 'errors');

        $this->as($owner)->postJson('/api/v1/business/ai/generate', ['kind' => 'push_message'])->assertOk();
        $this->as($owner)->postJson('/api/v1/business/ai/generate', ['kind' => 'push_message'])->assertStatus(422);
    }

    public function test_api_key_never_leaves_the_server(): void
    {
        $admin = User::factory()->admin()->create();
        $this->as($admin)->putJson('/api/v1/admin/settings', ['openaiApiKey' => 'sk-secret', 'aiEnabled' => true])
            ->assertOk()
            ->assertJsonPath('data.openaiApiKey', '')
            ->assertJsonPath('data.openaiApiKeySet', true);

        $this->assertStringNotContainsString('sk-secret', $this->getJson('/api/v1/config')->getContent());
    }

    public function test_customer_sounds_reach_the_app_config(): void
    {
        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.customerSounds.enabled', true)
            ->assertJsonPath('data.customerSounds.placed', null);
    }
}
