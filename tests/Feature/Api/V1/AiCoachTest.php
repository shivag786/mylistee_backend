<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Services\SettingService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The AI sales manager bubble: free tips for every shop, rationed nudges, the "ask" task. */
class AiCoachTest extends TestCase
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
    private function shop(int $dishes = 0): array
    {
        $owner = User::factory()->businessOwner()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id, 'name' => 'Chai Point']);
        Product::factory()->count($dishes)->create(['business_id' => $business->id]);

        return [$owner, $business];
    }

    private function aiInFreePlan(bool $on): void
    {
        $modules = collect(array_keys(config('plan_modules')))
            ->mapWithKeys(fn ($id) => [$id => ['on' => $id === 'ai_assistant' ? $on : true, 'days' => null]])
            ->all();
        $this->as(User::factory()->admin()->create())
            ->patchJson('/api/v1/admin/plans/free', ['modules' => $modules])
            ->assertOk();
    }

    public function test_every_shop_gets_tips_even_without_the_ai_module(): void
    {
        $this->aiInFreePlan(false);
        [$owner] = $this->shop();
        Http::fake();

        $this->as($owner)->getJson('/api/v1/business/coach')
            ->assertOk()
            ->assertJsonPath('data.inPlan', false)
            ->assertJsonPath('data.tips.0.id', 'menu_empty')
            ->assertJsonPath('data.tips.0.action.route', 'menuScan')
            ->assertJsonPath('data.nudge', null);

        Http::assertNothingSent(); // tips never call the AI
    }

    public function test_nudges_are_rationed(): void
    {
        $this->aiInFreePlan(true);
        [$owner] = $this->shop(3); // small menu, no orders, no offer
        $this->travelTo(now()->setTime(9, 0));

        $first = $this->as($owner)->getJson('/api/v1/business/coach?nudge=1')->assertOk()->json('data.nudge.id');
        $this->assertSame('no_orders', $first);
        // Right after: too soon.
        $this->as($owner)->getJson('/api/v1/business/coach?nudge=1')->assertJsonPath('data.nudge', null);

        $this->travel(5)->hours();
        $this->as($owner)->getJson('/api/v1/business/coach?nudge=1')->assertJsonPath('data.nudge.id', 'menu_small');

        // Two a day at most.
        $this->travel(5)->hours();
        $this->as($owner)->getJson('/api/v1/business/coach?nudge=1')->assertJsonPath('data.nudge', null);

        // Next day: a tip not shown in the last 3 days.
        $this->travelTo(now()->addDay()->setTime(9, 0));
        $this->as($owner)->getJson('/api/v1/business/coach?nudge=1')->assertJsonPath('data.nudge.id', 'no_offer');

        // "Not now" keeps it quiet for a day.
        $this->as($owner)->postJson('/api/v1/business/coach/nudge', ['action' => 'dismiss', 'tipId' => 'no_offer'])->assertOk();
        $this->travel(20)->hours();
        $this->as($owner)->getJson('/api/v1/business/coach?nudge=1')->assertJsonPath('data.nudge', null);
    }

    public function test_ask_answers_from_the_shops_own_numbers(): void
    {
        app(SettingService::class)->set(['aiEnabled' => true, 'geminiApiKey' => 'AQ.test']);
        $this->aiInFreePlan(true);
        [$owner] = $this->shop(2);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [[
                'text' => json_encode(['suggestions' => [['text' => 'Abhi orders kam hain.', 'steps' => ['Table QR lagao']]]]),
            ]]]]]]),
        ]);

        $this->as($owner)->postJson('/api/v1/business/ai/generate', ['kind' => 'ask', 'input' => ['question' => 'Mera sabse slow item kaunsa hai?']])
            ->assertOk()
            ->assertJsonPath('data.suggestions.0.text', 'Abhi orders kam hain.')
            ->assertJsonPath('data.suggestions.0.steps.0', 'Table QR lagao');

        Http::assertSent(fn ($r) => str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'sales manager')
            && str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'slowSellers'));

        $this->as($owner)->postJson('/api/v1/business/ai/generate', ['kind' => 'ask', 'input' => []])->assertStatus(422);
    }
}
