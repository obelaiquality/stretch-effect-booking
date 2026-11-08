<?php
declare(strict_types=1);

namespace StretchEffect\Google;

final class GoogleApiException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
