<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function token(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    public function test_customer_can_report_someone_elses_review(): void
    {
        $review = Review::factory()->create();
        $customer = User::factory()->create();

        $this->withToken($this->token($customer))
            ->postJson("/api/v1/reviews/{$review->uuid}/report", ['reason' => 'offensive', 'note' => 'Abusive words'])
            ->assertOk();

        $this->assertDatabaseHas('review_reports', [
            'review_id' => $review->id,
            'reporter_id' => $customer->id,
            'reason' => 'offensive',
        ]);
    }

    public function test_reporting_again_updates_the_same_report(): void
    {
        $review = Review::factory()->create();
        $customer = User::factory()->create();
        $token = $this->token($customer);

        $this->withToken($token)->postJson("/api/v1/reviews/{$review->uuid}/report", ['reason' => 'spam'])->assertOk();
        $this->withToken($token)->postJson("/api/v1/reviews/{$review->uuid}/report", ['reason' => 'fake'])->assertOk();

        $this->assertDatabaseCount('review_reports', 1);
        $this->assertDatabaseHas('review_reports', ['review_id' => $review->id, 'reason' => 'fake']);
    }

    public function test_customer_cannot_report_own_review_or_use_unknown_reason(): void
    {
        $customer = User::factory()->create();
        $own = Review::factory()->create(['customer_id' => $customer->id]);
        $other = Review::factory()->create();
        $token = $this->token($customer);

        $this->withToken($token)
            ->postJson("/api/v1/reviews/{$own->uuid}/report", ['reason' => 'spam'])
            ->assertStatus(422);

        $this->withToken($token)
            ->postJson("/api/v1/reviews/{$other->uuid}/report", ['reason' => 'boring'])
            ->assertStatus(422);

        $this->assertDatabaseCount('review_reports', 0);
    }

    public function test_guest_cannot_report_and_unknown_review_is_404(): void
    {
        $review = Review::factory()->create();

        $this->postJson("/api/v1/reviews/{$review->uuid}/report", ['reason' => 'spam'])->assertUnauthorized();

        $this->withToken($this->token(User::factory()->create()))
            ->postJson('/api/v1/reviews/no-such-review/report', ['reason' => 'spam'])
            ->assertNotFound();
    }

    public function test_admin_sees_reported_reviews_with_counts(): void
    {
        $reported = Review::factory()->create();
        Review::factory()->create(); // never reported
        foreach (['spam', 'spam', 'offensive'] as $reason) {
            $this->withToken($this->token(User::factory()->create()))
                ->postJson("/api/v1/reviews/{$reported->uuid}/report", ['reason' => $reason])
                ->assertOk();
            // Each request signs in a different customer.
            $this->app['auth']->forgetGuards();
        }

        $admin = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);

        $this->withToken($this->token($admin))
            ->getJson('/api/v1/admin/reviews?status=reported')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $reported->uuid)
            ->assertJsonPath('data.0.reportsCount', 3)
            ->assertJsonPath('data.0.reportReasons.spam', 2)
            ->assertJsonPath('data.0.reportReasons.offensive', 1);

        $this->withToken($this->token($admin))
            ->getJson('/api/v1/admin/reviews')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
