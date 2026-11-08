<?php
declare(strict_types=1);

namespace StretchEffect\Contracts;

final class DomainError extends \RuntimeException
{
    public const CODES = ['unauthorized', 'forbidden', 'not_found', 'validation', 'conflict', 'no_credits', 'rate_limited', 'server'];

    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            'unauthorized' => 401, 'forbidden' => 403, 'not_found' => 404, 'validation' => 422,
            'conflict', 'no_credits' => 409, 'rate_limited' => 429, default => 500,
        };
    }
}
