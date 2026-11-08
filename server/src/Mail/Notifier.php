<?php
declare(strict_types=1);

namespace StretchEffect\Mail;

use StretchEffect\Support\Log;
use StretchEffect\Support\Time;

/** Booking e-mails. Failures are logged, never thrown: a booking must not fail because mail did. */
final class Notifier
{
    public function __construct(private readonly Mailer $mailer, private readonly string $businessName = 'The Stretch Effect')
    {
    }

    /** @param array<string,mixed> $booking row incl. start_at @param array<string,mixed> $user */
    public function confirmation(array $booking, array $user, string $headline = 'Your session is booked'): void
    {
        $when = self::when((string) $booking['start_at']);
        $text = "Hi {$user['name']},\n\n$headline.\n\nWhen: $when (South African time)\nDuration: 60 minutes\n\n"
            . "Need to change it? Sign in and cancel or rebook. Cancelling more than 24 hours before the start returns your session credit.\n\n"
            . "{$this->businessName}\n";
        $this->send((string) $user['email'], $headline . ' - ' . $this->businessName, $text, $this->html($user, $headline, [
            ['When', $when . ' (South African time)'], ['Duration', '60 minutes'],
        ], 'Need to change it? Sign in and cancel or rebook. Cancelling more than 24 hours before the start returns your session credit.'));
    }

    /** @param array<string,mixed> $booking @param array<string,mixed> $user */
    public function cancellation(array $booking, array $user, bool $refunded): void
    {
        $when = self::when((string) $booking['start_at']);
        $line = $refunded ? 'Your session credit has been returned.' : 'The session credit was not returned (cancelled inside the free-cancellation window).';
        $text = "Hi {$user['name']},\n\nYour session on $when has been cancelled.\n\n$line\n\n{$this->businessName}\n";
        $this->send((string) $user['email'], 'Session cancelled - ' . $this->businessName, $text, $this->html($user, 'Your session was cancelled', [
            ['Was booked for', $when . ' (South African time)'],
        ], $line));
    }

    private static function when(string $dbUtc): string
    {
        return Time::fromDb($dbUtc)->setTimezone(Time::tz())->format('l j F Y \a\t H:i');
    }

    /** @param array<string,mixed> $user @param list<array{0:string,1:string}> $rows */
    private function html(array $user, string $headline, array $rows, string $footer): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tr = '';
        foreach ($rows as [$k, $v]) {
            $tr .= '<tr><td style="padding:4px 16px 4px 0;color:#666">' . $e($k) . '</td><td style="padding:4px 0"><strong>' . $e($v) . '</strong></td></tr>';
        }
        return '<!doctype html><html><body style="font-family:Arial,Helvetica,sans-serif;color:#111;max-width:520px">'
            . '<h2 style="margin:0 0 12px">' . $e($headline) . '</h2><p>Hi ' . $e((string) $user['name']) . ',</p>'
            . '<table style="border-collapse:collapse">' . $tr . '</table><p style="margin-top:16px">' . $e($footer) . '</p>'
            . '<p style="color:#666">' . $e($this->businessName) . '</p></body></html>';
    }

    private function send(string $to, string $subject, string $text, string $html): void
    {
        try {
            $this->mailer->send($to, $subject, $text, $html);
        } catch (\Throwable $e) {
            Log::error('Mail to ' . $to . ' failed: ' . $e->getMessage());
        }
    }
}
