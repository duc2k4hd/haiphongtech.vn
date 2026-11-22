<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class MailConfigHelper
{
    /**
     * Load mail settings from `settings` table and apply dynamically to Laravel mailer.
     *
     * @return array<string, string>
     */
    public static function applyFromSettings(): array
    {
        $rows = DB::table('settings')
            ->whereIn('key', [
                'mail_mailer',
                'mail_host',
                'mail_port',
                'mail_encryption',
                'mail_username',
                'mail_password',
                'mail_from_address',
                'mail_from_name',
                'mail_receive_address',
                'mail_notify_on_contact',
                'email',
                'name',
                'company',
            ])
            ->pluck('value', 'key')
            ->toArray();

        $mailer = trim((string) ($rows['mail_mailer'] ?? '')) ?: (string) config('mail.default', 'smtp');
        $host = trim((string) ($rows['mail_host'] ?? ''));
        $port = (int) ($rows['mail_port'] ?? 0);
        $encryption = trim((string) ($rows['mail_encryption'] ?? ''));
        $username = trim((string) ($rows['mail_username'] ?? ''));
        $password = (string) ($rows['mail_password'] ?? '');
        $fromAddress = trim((string) ($rows['mail_from_address'] ?? ''))
            ?: ($username !== '' ? $username : (string) config('mail.from.address', 'no-reply@haiphongtech.vn'));
        $fromName = trim((string) ($rows['mail_from_name'] ?? ''))
            ?: trim((string) ($rows['name'] ?? ($rows['company'] ?? config('mail.from.name', 'Hải Phòng Tech'))));

        if ($host !== '') {
            $scheme = match (strtolower($encryption)) {
                'ssl', 'smtps' => 'smtps',
                'none', 'null', '' => null,
                default => 'smtp',
            };

            config([
                'mail.default' => $mailer,
                'mail.mailers.smtp.host' => $host,
                'mail.mailers.smtp.port' => $port > 0 ? $port : 587,
                'mail.mailers.smtp.encryption' => ($encryption === '' || strtolower($encryption) === 'none') ? null : strtolower($encryption),
                'mail.mailers.smtp.scheme' => $scheme,
                'mail.mailers.smtp.username' => $username !== '' ? $username : null,
                'mail.mailers.smtp.password' => $password !== '' ? $password : null,
            ]);
        } else {
            config(['mail.default' => $mailer]);
        }

        config([
            'mail.from.address' => $fromAddress,
            'mail.from.name' => $fromName,
        ]);

        // Purge cached mailer instance so new runtime SMTP settings apply immediately
        try {
            Mail::purge($mailer);
            Mail::purge('smtp');
        } catch (\Throwable $e) {
            // Ignore if mailer not yet resolved
        }

        return $rows;
    }

    /**
     * Parse incoming notification email(s) configured in Admin Settings.
     *
     * @param array<string, string>|null $rows
     * @return array<int, string>
     */
    public static function getReceiveEmails(?array $rows = null): array
    {
        if ($rows === null) {
            $rows = DB::table('settings')
                ->whereIn('key', ['mail_receive_address', 'email'])
                ->pluck('value', 'key')
                ->toArray();
        }

        $raw = trim((string) ($rows['mail_receive_address'] ?? ''));
        if ($raw === '') {
            $raw = trim((string) ($rows['email'] ?? ''));
        }

        if ($raw === '') {
            return [];
        }

        $emails = [];
        foreach (preg_split('/[,\n;]+/', $raw) as $part) {
            $addr = trim((string) $part);
            if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $addr;
            }
        }

        return array_values(array_unique($emails));
    }
}
