<?php

namespace App\Console\Commands;

use App\Enums\BusinessStatus;
use App\Models\AiSuggestion;
use App\Models\Business;
use App\Models\Order;
use App\Services\AiAdvisor\AiAdvisorService;
use App\Services\PlanAccessService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Nightly AI ideas: for every active shop whose plan has the AI assistant and
 * that had enough orders lately, ask the AI Sales Manager and the AI Customer
 * Growth Advisor for fresh suggestions. One shop at a time, with a pause
 * between AI calls so the free tier's requests-per-minute limit is never hit.
 * A shop that fails is logged and skipped; the run carries on.
 */
class AiAdvisorNightlyCommand extends Command
{
    protected $signature = 'ai:advisor-nightly {--business= : Only this business id} {--delay= : Seconds between AI calls}';

    protected $description = 'Generate AI sales and customer-growth suggestions for every eligible shop.';

    public function handle(AiAdvisorService $advisor, PlanAccessService $access): int
    {
        if (! $advisor->available()) {
            $this->warn('AI is off or not on Gemini — nothing to do.');

            return self::SUCCESS;
        }

        $delay = max(0, (int) ($this->option('delay') ?? config('ai_advisor.nightly.delay_seconds', 8)));
        $minOrders = max(AiAdvisorService::MIN_ORDERS, (int) config('ai_advisor.nightly.min_orders', 5));
        $since = now()->subDays((int) config('ai_advisor.window_days', 30));
        $statuses = (array) config('ai_advisor.counted_statuses', ['paid', 'completed']);

        $shops = Business::query()
            ->where('status', BusinessStatus::Active->value)
            ->when($this->option('business'), fn ($q, $id) => $q->whereKey($id))
            ->whereIn('id', Order::query()->select('business_id')
                ->whereIn('status', $statuses)->where('created_at', '>=', $since)
                ->groupBy('business_id')->havingRaw('COUNT(*) >= ?', [$minOrders]))
            ->orderBy('id')
            ->get();

        $calls = 0;
        $done = 0;
        foreach ($shops as $shop) {
            if (! $access->allows($shop, 'ai_assistant')) {
                continue;
            }
            foreach ([AiSuggestion::KIND_SALES, AiSuggestion::KIND_GROWTH] as $kind) {
                if ($calls++ > 0 && $delay > 0) {
                    sleep($delay);
                }
                try {
                    $count = count($advisor->generate($shop, $kind));
                    $done++;
                    $this->line("#{$shop->id} {$kind}: {$count} ideas");
                } catch (Throwable $e) {
                    $this->warn("#{$shop->id} {$kind}: ".$e->getMessage());
                }
            }
        }
        $this->info("AI advisor: {$done} runs for {$shops->count()} shops.");

        return self::SUCCESS;
    }
}
