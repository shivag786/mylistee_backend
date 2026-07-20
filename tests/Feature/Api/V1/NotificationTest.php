<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    public function test_index_lists_notifications_with_unread_meta(): void
    {
        $user = User::factory()->create();
        Notification::factory()->count(2)->create(['user_id' => $user->id]);
        Notification::factory()->read()->create(['user_id' => $user->id]);

        $this->withToken($this->token($user))
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.unread', 2)
            ->assertJsonStructure(['data' => [['id', 'type', 'title', 'isRead', 'link']]]);
    }

    public function test_unread_count_endpoint(): void
    {
        $user = User::factory()->create();
        Notification::factory()->count(4)->create(['user_id' => $user->id]);

        $this->withToken($this->token($user))
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread', 4);
    }

    public function test_mark_all_read(): void
    {
        $user = User::factory()->create();
        Notification::factory()->count(3)->create(['user_id' => $user->id]);

        $this->withToken($this->token($user))
            ->patchJson('/api/v1/notifications/read')
            ->assertOk()
            ->assertJsonPath('data.unread', 0);
    }

    public function test_mark_single_read(): void
    {
        $user = User::factory()->create();
        $one = Notification::factory()->create(['user_id' => $user->id]);
        Notification::factory()->create(['user_id' => $user->id]);

        $this->withToken($this->token($user))
            ->patchJson('/api/v1/notifications/read', ['id' => $one->uuid])
            ->assertOk()
            ->assertJsonPath('data.unread', 1);
    }

    public function test_delete_notification(): void
    {
        $user = User::factory()->create();
        $n = Notification::factory()->create(['user_id' => $user->id]);

        $this->withToken($this->token($user))
            ->deleteJson("/api/v1/notifications/{$n->uuid}")
            ->assertOk();
        $this->assertDatabaseMissing('notifications', ['id' => $n->id]);
    }

    public function test_users_only_see_their_own_notifications(): void
    {
        $user = User::factory()->create();
        Notification::factory()->create(['user_id' => $user->id]);
        Notification::factory()->count(3)->create(); // others

        $this->withToken($this->token($user))
            ->getJson('/api/v1/notifications')
            ->assertJsonCount(1, 'data');
    }

    public function test_registering_a_device_token_persists_it(): void
    {
        $user = User::factory()->create();

        $this->withToken($this->token($user))
            ->postJson('/api/v1/notifications/device-token', ['token' => 'fcm-token-abc', 'platform' => 'web'])
            ->assertOk();

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $user->id,
            'token' => 'fcm-token-abc',
        ]);
    }

    public function test_spinning_creates_notifications_for_customer_and_owner(): void
    {
        $customer = User::factory()->create();
        $owner = User::factory()->businessOwner()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);
        Offer::factory()->create(['business_id' => $business->id]);

        $this->withToken($this->token($customer))
            ->postJson('/api/v1/spinner/spin', ['businessSlug' => $business->slug])
            ->assertOk();

        $this->assertDatabaseHas('notifications', ['user_id' => $customer->id, 'type' => 'reward_won']);
        $this->assertDatabaseHas('notifications', ['user_id' => $owner->id, 'type' => 'spin_activity']);
    }

    public function test_notifications_require_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401);
    }
}
