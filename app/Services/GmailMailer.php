<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends plain emails through the Gmail account an admin saved in settings
 * (address + a Google App Password — not the account password). Built at send
 * time, so connecting or changing the account needs no .env edit or deploy.
 */
class GmailMailer
{
    public function __construct(private readonly SettingService $settings) {}

    public function isConfigured(): bool
    {
        return filled($this->settings->get('gmailAddress'))
            && filled($this->settings->get('gmailAppPassword'));
    }

    /** Best-effort: returns false (and logs) instead of throwing. */
    public function send(string $to, string $subject, string $body): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $from = (string) $this->settings->get('gmailAddress');

        try {
            Mail::build([
                'transport' => 'smtp',
                'host' => 'smtp.gmail.com',
                'port' => 587,
                'encryption' => 'tls',
                'username' => $from,
                // App passwords are shown with spaces ("abcd efgh …"); SMTP wants them without.
                'password' => str_replace(' ', '', (string) $this->settings->get('gmailAppPassword')),
                'timeout' => 15,
            ])->raw($body, function ($message) use ($to, $subject, $from): void {
                $message->to($to)
                    ->from($from, (string) $this->settings->get('brandName'))
                    ->subject($subject);
            });

            return true;
        } catch (Throwable $e) {
            Log::warning('Gmail send failed', ['to' => $to, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
