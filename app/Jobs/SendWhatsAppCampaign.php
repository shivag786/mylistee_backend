<?php

namespace App\Jobs;

use App\Models\WhatsAppCampaign;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers one shop's WhatsApp offer, one number at a time (Interakt has no
 * bulk send), then records how many went and failed. Dispatched after the
 * response, so it runs without a queue worker.
 */
class SendWhatsAppCampaign implements ShouldQueue
{
    use Queueable;

    /** @param  list<array{phone: string, name: ?string}>  $recipients */
    public function __construct(
        public readonly int $campaignId,
        public readonly array $recipients,
    ) {}

    public function handle(WhatsAppSender $sender): void
    {
        $campaign = WhatsAppCampaign::with(['template', 'business'])->find($this->campaignId);
        if ($campaign?->template === null || $campaign->business === null) {
            return;
        }

        @set_time_limit(0);
        $shop = WhatsAppSender::shopContext($campaign->business);
        $typed = (array) $campaign->values;
        $sent = 0;
        $failed = 0;

        foreach ($this->recipients as $r) {
            $message = $sender->send($campaign->template, $r['phone'], 'campaign', $shop + ['customer_name' => $r['name']], $typed, $campaign->business_id, $campaign->id);
            $message->status === 'failed' ? $failed++ : $sent++;
        }

        $campaign->update(['sent' => $sent, 'failed' => $failed, 'finished_at' => now()]);
    }
}
