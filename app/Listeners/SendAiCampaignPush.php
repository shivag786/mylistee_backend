<?php

namespace App\Listeners;

use App\Events\AiCampaignApproved;
use App\Services\AiAdvisor\AiCampaignSender;

/**
 * Sends an approved AI growth campaign as a shop push right away when its
 * send time has come; otherwise `ai:campaigns-send` (every 5 minutes) sends
 * it at `send_at`.
 */
class SendAiCampaignPush
{
    public function __construct(private readonly AiCampaignSender $sender) {}

    public function handle(AiCampaignApproved $event): void
    {
        $this->sender->sendIfDue($event->suggestion, $event->actor);
    }
}
