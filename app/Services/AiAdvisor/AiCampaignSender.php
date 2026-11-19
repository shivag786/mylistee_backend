<?php

namespace App\Services\AiAdvisor;

use App\Models\AiSuggestion;
use App\Models\User;
use App\Services\ShopPushService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Delivers approved AI growth campaigns through the shop push system, with all
 * of its guard rails (plan quotas, per-customer cap, quiet hours, one send a
 * day). A send blocked only by timing (quiet hours, already sent today) moves
 * to the next allowed slot; anything else is marked failed with the reason.
 */
class AiCampaignSender
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(private readonly ShopPushService $push) {}

    public function sendIfDue(AiSuggestion $s, ?User $actor = null): void
    {
        $s->refresh();
        if ($s->kind !== AiSuggestion::KIND_GROWTH || $s->status !== 'approved' || $s->sent_at !== null) {
            return;
        }
        if (($s->result['push']['status'] ?? null) === 'failed') {
            return;
        }
        if ($s->send_at !== null && $s->send_at->isFuture()) {
            return;
        }

        $business = $s->business;
        $actor ??= $business->owner;
        $payload = $s->payload ?? [];
        $result = $s->result ?? [];
        $attempts = (int) ($result['push']['attempts'] ?? 0) + 1;

        try {
            $campaign = $this->push->send($business, $actor, [
                'audience' => $payload['segment'] ?? 'everyone',
                'title' => $payload['pushTitle'] ?? $s->title,
                'body' => $s->message,
                'link' => $s->type === 'review_request' ? "/review/{$business->slug}" : "/b/{$business->slug}",
            ]);
            $result['push'] = ['status' => 'sent', 'campaign' => $campaign->uuid, 'recipients' => $campaign->recipients, 'attempts' => $attempts];
            $s->update(['sent_at' => now(), 'result' => $result]);
        } catch (ValidationException $e) {
            $reason = (string) collect($e->errors())->flatten()->first();
            $timing = str_contains($reason, 'pause from 10 PM') || str_contains($reason, 'One notification a day');
            if ($timing && $attempts < self::MAX_ATTEMPTS) {
                $tz = (string) config('app.business_timezone', 'Asia/Kolkata');
                $next = str_contains($reason, 'One notification a day')
                    ? now($tz)->addDay()->setTime(10, 0)
                    : (now($tz)->hour >= 22 ? now($tz)->addDay()->setTime(8, 30) : now($tz)->setTime(8, 30));
                $result['push'] = ['status' => 'scheduled', 'note' => $reason, 'attempts' => $attempts];
                $s->update(['send_at' => $next->setTimezone(config('app.timezone')), 'result' => $result]);

                return;
            }
            $result['push'] = ['status' => 'failed', 'error' => $reason, 'attempts' => $attempts];
            $s->update(['result' => $result]);
        } catch (Throwable $e) {
            Log::warning('AI campaign push failed', ['suggestion' => $s->id, 'error' => $e->getMessage()]);
            $result['push'] = ['status' => 'failed', 'error' => 'The notification could not be sent.', 'attempts' => $attempts];
            $s->update(['result' => $result]);
        }
    }

    /** Send every approved campaign whose time has come (scheduler, every 5 minutes). */
    public function sendDue(): int
    {
        $sent = 0;
        AiSuggestion::query()
            ->where('kind', AiSuggestion::KIND_GROWTH)
            ->where('status', 'approved')
            ->whereNull('sent_at')
            ->where('send_at', '<=', now())
            ->orderBy('send_at')->limit(50)
            ->get()
            ->each(function (AiSuggestion $s) use (&$sent): void {
                $this->sendIfDue($s);
                $sent += $s->fresh()->sent_at !== null ? 1 : 0;
            });

        return $sent;
    }
}
