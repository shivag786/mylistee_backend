<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Models\Business;
use App\Services\GmailMailer;
use App\Services\NotificationService;
use App\Services\PersonalLinkService;
use App\Services\SettingService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Warns shop owners that their personal link is about to run out — once a day,
 * from `personalLinkReminderDays` before expiry. In-app + push always; email
 * too when the admin turned it on and a Gmail account is connected.
 *
 * Does nothing unless an admin enabled reminders (personalLinkReminderEnabled).
 * `personal_link_reminded_on` makes re-runs on the same day harmless.
 */
class PersonalLinkRemindersCommand extends Command
{
    protected $signature = 'personal-link:remind';

    protected $description = 'Send the daily "personal link expiring" reminder to shop owners.';

    public function handle(
        PersonalLinkService $links,
        NotificationService $notifications,
        SettingService $settings,
        GmailMailer $mailer,
    ): int {
        if (! $settings->get('personalLinkReminderEnabled')) {
            $this->info('Personal link reminders are switched off.');

            return self::SUCCESS;
        }

        $withEmail = (bool) $settings->get('personalLinkReminderEmail') && $mailer->isConfigured();
        $title = (string) $settings->get('personalLinkReminderTitle');
        $body = (string) $settings->get('personalLinkReminderBody');
        $today = now(config('app.business_timezone'))->toDateString();

        $sent = 0;
        $links->dueForReminder()->with('owner')->chunkById(100, function ($businesses) use (
            $notifications, $mailer, $withEmail, $title, $body, $today, &$sent
        ): void {
            foreach ($businesses as $business) {
                /** @var Business $business */
                try {
                    $vars = $this->vars($business);
                    $t = strtr($title, $vars);
                    $b = strtr($body, $vars);

                    if ($business->owner !== null) {
                        $notifications->notify($business->owner, NotificationType::System, $t, $b, [
                            'link' => '/business/personal-link',
                        ]);
                    }

                    $email = $business->email ?: $business->owner?->email;
                    if ($withEmail && filled($email)) {
                        $mailer->send($email, $t, $b."\n\nRenew: ".rtrim((string) config('app.frontend_url', config('app.url')), '/').'/business/personal-link');
                    }

                    $business->forceFill(['personal_link_reminded_on' => $today])->save();
                    $sent++;
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });

        $this->info("Sent {$sent} personal link reminder(s).");

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    private function vars(Business $business): array
    {
        $expires = $business->personal_link_expires_at;
        $days = max(1, (int) ceil(now()->diffInSeconds($expires) / 86400));

        return [
            '{shop}' => $business->name,
            '{days}' => $days === 1 ? '1 day' : "{$days} days",
            '{date}' => $expires->copy()->timezone(config('app.business_timezone'))->format('d M Y, h:i A'),
        ];
    }
}
