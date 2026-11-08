<?php
declare(strict_types=1);

namespace StretchEffect\Mail;

interface Mailer
{
    /** @throws \RuntimeException when the message could not be handed over */
    public function send(string $to, string $subject, string $text, string $html): void;
}
