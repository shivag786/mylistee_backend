<?php

namespace App\Console\Commands;

use App\Enums\RewardStatus;
use App\Models\DeviceToken;
use App\Models\Reward;
use Illuminate\Console\Command;

/**
 * Daily maintenance (Milestone 18 §Scheduler). Reward expiry is also lazy (on
 * wallet read), but this proactively flips stale rewards and prunes dead data so
 * the DB stays tidy. Scheduled in routes/console.php.
 */
class MaintenanceCommand extends Command
{
    protected $signature = 'app:maintenance';

    protected $description = 'Expire stale rewards and prune dead device tokens.';

    public function handle(): int
    {
        $expired = Reward::query()->stale()->update(['status' => RewardStatus::Expired->value]);
        $this->info("Expired {$expired} stale reward(s).");

        $pruned = DeviceToken::query()->where('updated_at', '<', now()->subDays(60))->delete();
        $this->info("Pruned {$pruned} dead device token(s).");

        return self::SUCCESS;
    }
}
