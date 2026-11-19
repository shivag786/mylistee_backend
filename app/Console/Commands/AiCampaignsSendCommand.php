<?php

namespace App\Console\Commands;

use App\Services\AiAdvisor\AiCampaignSender;
use Illuminate\Console\Command;

/** Sends approved AI growth campaigns whose send time has come (every 5 minutes). */
class AiCampaignsSendCommand extends Command
{
    protected $signature = 'ai:campaigns-send';

    protected $description = 'Send approved AI customer-growth notifications that are due.';

    public function handle(AiCampaignSender $sender): int
    {
        $this->info('AI campaigns sent: '.$sender->sendDue().'.');

        return self::SUCCESS;
    }
}
