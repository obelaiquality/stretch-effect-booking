<?php
declare(strict_types=1);

namespace StretchEffect\Mail;

/** Dev mailer: appends messages to a log file, sends nothing. */
final class NullMailer implements Mailer
{
    /** @var list<array{to:string,subject:string,text:string}> */
    public array $sent = [];

    public function __construct(private readonly ?string $logPath = null)
    {
    }

    public function send(string $to, string $subject, string $text, string $html): void
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'text' => $text];
        if ($this->logPath === null) {
            return;
        }
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @file_put_contents($this->logPath, '=== ' . gmdate('c') . " to: $to\nSubject: $subject\n\n$text\n\n", FILE_APPEND | LOCK_EX);
    }
}
