<?php
declare(strict_types=1);

namespace StretchEffect\Mail;

final class NativeMailer implements Mailer
{
    public function __construct(private readonly string $from)
    {
    }

    public function send(string $to, string $subject, string $text, string $html): void
    {
        $m = MimeMessage::build($this->from, $to, $subject, $text, $html);
        // mail() adds To and Subject itself; pass the rest as headers.
        $headers = implode("\r\n", array_filter(explode("\r\n", $m['headers']), static fn (string $h): bool => !str_starts_with($h, 'To:') && !str_starts_with($h, 'Subject:')));
        if (!@mail(MimeMessage::clean($to), '=?UTF-8?B?' . base64_encode(MimeMessage::clean($subject)) . '?=', $m['body'], $headers)) {
            throw new \RuntimeException('mail() refused the message');
        }
    }
}
