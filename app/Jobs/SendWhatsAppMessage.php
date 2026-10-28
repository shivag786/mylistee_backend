<?php

namespace App\Jobs;

use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One automatic WhatsApp message (order update, new-order alert). Dispatched
 * after the response, so it needs no queue worker and never slows the request.
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    /** @param  array<string, string|null>  $context */
    public function __construct(
        public readonly int $templateId,
        public readonly string $phone,
        public readonly string $purpose,
        public readonly array $context,
        public readonly ?int $businessId = null,
    ) {}

    public function handle(WhatsAppSender $sender): void
    {
        $template = WhatsAppTemplate::find($this->templateId);
        if ($template !== null) {
            $sender->send($template, $this->phone, $this->purpose, $this->context, businessId: $this->businessId);
        }
    }
}
