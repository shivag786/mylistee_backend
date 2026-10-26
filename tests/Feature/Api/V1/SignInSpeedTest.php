<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\LaravelCachePool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * What keeps sign-in quick: PINs hashed at a lighter cost (old ones upgraded
 * at the next sign-in), and a cache that survives the request for the
 * Firebase SDK (Google's signing keys).
 */
class SignInSpeedTest extends TestCase
{
    use RefreshDatabase;

    private function cost(string $hash): int
    {
        return (int) (password_get_info($hash)['options']['cost'] ?? 0);
    }

    public function test_a_new_pin_is_hashed_at_the_lighter_cost(): void
    {
        $this->postJson('/api/v1/auth/register-owner', ['name' => 'Asha', 'mobile' => '9876500001', 'pin' => '4826'])
            ->assertCreated();

        $pin = User::where('phone', '9876500001')->value('pin');
        $this->assertSame(User::PIN_ROUNDS, $this->cost($pin));
        $this->assertTrue(Hash::check('4826', $pin));
    }

    public function test_an_old_slow_pin_still_works_and_is_upgraded_on_sign_in(): void
    {
        $user = User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active, 'phone' => '9876500002']);
        // Stored the old way, at cost 12, straight into the table.
        DB::table('users')->where('id', $user->id)->update(['pin' => Hash::make('4826', ['rounds' => 12])]);

        $this->postJson('/api/v1/auth/pin-login', ['identifier' => '9876500002', 'pin' => '4826'])->assertOk();

        $pin = $user->fresh()->pin;
        $this->assertSame(User::PIN_ROUNDS, $this->cost($pin));
        $this->postJson('/api/v1/auth/pin-login', ['identifier' => '9876500002', 'pin' => '4826'])->assertOk();
        $this->postJson('/api/v1/auth/pin-login', ['identifier' => '9876500002', 'pin' => '1111'])->assertStatus(422);
    }

    public function test_the_cache_pool_keeps_values_across_pools_until_they_expire(): void
    {
        $store = Cache::store('array');
        $pool = new LaravelCachePool($store, 't:');

        $this->assertFalse($pool->getItem('keys')->isHit());

        $pool->save($pool->getItem('keys')->set(['kid' => 'pem'])->expiresAfter(3600));
        $pool->save($pool->getItem('nothing')->set(null));

        // A fresh pool (the next request) still sees them.
        $next = new LaravelCachePool($store, 't:');
        $this->assertSame(['kid' => 'pem'], $next->getItem('keys')->get());
        $this->assertTrue($next->getItem('nothing')->isHit());
        $this->assertNull($next->getItem('nothing')->get());

        // Already expired: gone.
        $next->save($next->getItem('keys')->set('x')->expiresAfter(0));
        $this->assertFalse($next->hasItem('keys'));

        // Deferred items land on commit.
        $next->saveDeferred($next->getItem('later')->set(1));
        $this->assertFalse((new LaravelCachePool($store, 't:'))->hasItem('later'));
        $next->commit();
        $this->assertTrue((new LaravelCachePool($store, 't:'))->hasItem('later'));
    }
}
