<?php
declare(strict_types=1);

namespace StretchEffect\Mail;

final class MimeMessage
{
    public static function clean(string $s): string
    {
        return trim(str_replace(["\r", "\n", "\0"], ' ', $s));
    }

    /** @return array{headers:string, body:string} CRLF separated */
    public static function build(string $from, string $to, string $subject, string $text, string $html): array
    {
        $b = 'se_' . bin2hex(random_bytes(8));
        $headers = [
            'From: ' . self::clean($from),
            'To: ' . self::clean($to),
            'Subject: =?UTF-8?B?' . base64_encode(self::clean($subject)) . '?=',
            'Date: ' . gmdate('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@stretch-effect>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $b . '"',
        ];
        $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . "--$b--\r\n";
        return ['headers' => implode("\r\n", $headers), 'body' => $body];
    }
}
