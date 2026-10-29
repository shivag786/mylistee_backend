<?php

namespace App\Console\Commands;

use App\Services\OccasionService;
use Illuminate\Console\Command;

/**
 * Daily: reminds customers of birthdays and anniversaries coming up (their
 * own and their saved people's), with what their favourite shops offer.
 * Each date is reminded once a year, so re-runs on the same day are harmless.
 */
class OccasionRemindersCommand extends Command
{
    protected $signature = 'occasions:remind';

    protected $description = 'Remind customers of upcoming birthdays and anniversaries.';

    public function handle(OccasionService $occasions): int
    {
        $sent = $occasions->sendReminders();
        $this->info("Sent {$sent} occasion reminders.");

        return self::SUCCESS;
    }
}
