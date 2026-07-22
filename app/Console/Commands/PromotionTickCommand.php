<?php

namespace App\Console\Commands;

use App\Services\PromotionService;
use Illuminate\Console\Command;

/**
 * Advances automatic promotions (Phase 7.2b, 07A "event-driven automation").
 * Scheduled every minute in routes/console.php so promotions start and stop on
 * their own without owner intervention.
 */
class PromotionTickCommand extends Command
{
    protected $signature = 'promotions:tick';

    protected $description = 'Start scheduled promotions and expire finished ones.';

    public function handle(PromotionService $promotions): int
    {
        $changed = $promotions->tick();
        $this->info("Promotions updated: {$changed}.");

        return self::SUCCESS;
    }
}
