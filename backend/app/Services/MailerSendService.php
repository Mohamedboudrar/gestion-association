<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Single reusable client for sending transactional email via the MailerSend
 * API (https://developers.mailersend.com/api/v1/email.html). Used only by
 * the member-portal welcome/passkey-reset emails — nothing else in this app
 * sends mail through it, and it never receives the passkey itself as
 * anything other than pre-rendered HTML (see resources/views/emails/*).
 *
 * If MAILERSEND_API_KEY isn't configured (e.g. local dev before real
 * credentials exist), send() logs the attempt instead of calling out to the
 * API and still returns true — callers don't need to branch on "is mail
 * configured yet", matching how this app already treats other not-yet-issued
 * API keys (see TomTomMapPicker/Viewer's placeholder pattern).
 */
class MailerSendService
{
    private const ENDPOINT = 'https://api.mailersend.com/v1/email';

    public function send(string $toEmail, string $toName, string $subject, string $html): bool
    {
        $apiKey = config('services.mailersend.key');

        if (! $apiKey) {
            Log::info('MailerSend not configured — email not sent.', [
                'to' => $toEmail,
                'subject' => $subject,
            ]);

            return true;
        }

        $response = Http::withToken($apiKey)->post(self::ENDPOINT, [
            'from' => [
                'email' => config('services.mailersend.from_email'),
                'name' => config('services.mailersend.from_name'),
            ],
            'to' => [
                ['email' => $toEmail, 'name' => $toName],
            ],
            'subject' => $subject,
            'html' => $html,
        ]);

        if ($response->failed()) {
            // Safe to log MailerSend's own error body — it's the API's
            // validation/rejection message about the request (e.g. an
            // invalid `from` address), never anything derived from $html,
            // so this can never leak the passkey.
            Log::error('MailerSend request failed.', [
                'to' => $toEmail,
                'status' => $response->status(),
                'error' => $response->json(),
            ]);

            return false;
        }

        return true;
    }
}
