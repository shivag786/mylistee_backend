<?php

namespace App\Events;

use App\Models\AiSuggestion;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The owner approved an AI Customer Growth Advisor campaign. Its coupon (if
 * any) already exists; listeners deliver the message — SendAiCampaignPush
 * sends the shop push notification at `send_at`.
 *
 * WhatsApp is not sent from here: WhatsApp needs pre-approved templates, so a
 * free-text AI message cannot go out on it.
 */
class AiCampaignApproved
{
    use Dispatchable;

    public function __construct(
        public readonly AiSuggestion $suggestion,
        public readonly User $actor,
    ) {}
}
