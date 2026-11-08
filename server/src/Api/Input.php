<?php
declare(strict_types=1);

namespace StretchEffect\Api;

use DateTimeImmutable;
use StretchEffect\Contracts\DomainError;
use StretchEffect\Support\Time;

/** Validated access to a decoded JSON body or query array. */
final class Input
{
    /** @param array<string,mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    private static function fail(string $msg): never
    {
        throw new DomainError('validation', $msg);
    }

    public function str(string $key, int $min = 1, int $max = 255, bool $required = true): ?string
    {
        $v = $this->data[$key] ?? null;
        if ($v === null || $v === '') {
            $required && self::fail("$key is required");
            return null;
        }
        if (!is_string($v)) {
            self::fail("$key must be text");
        }
        $v = trim($v);
        $len = mb_strlen($v);
        if ($len < $min || $len > $max) {
            self::fail("$key must be between $min and $max characters");
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $v)) {
            self::fail("$key contains invalid characters");
        }
        return $v;
    }

    /** Password: not trimmed. */
    public function secret(string $key, int $min, int $max = 200): string
    {
        $v = $this->data[$key] ?? null;
        if (!is_string($v) || strlen($v) < $min) {
            self::fail("$key must be at least $min characters");
        }
        if (strlen($v) > $max) {
            self::fail("$key is too long");
        }
        return $v;
    }

    public function email(string $key = 'email'): string
    {
        $v = strtolower((string) $this->str($key, 3, 190));
        if (filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            self::fail('Enter a valid email address');
        }
        return $v;
    }

    public function int(string $key, int $min, int $max, bool $required = true): ?int
    {
        $v = $this->data[$key] ?? null;
        if ($v === null) {
            $required && self::fail("$key is required");
            return null;
        }
        if (!is_int($v)) {
            self::fail("$key must be a whole number");
        }
        if ($v < $min || $v > $max) {
            self::fail("$key must be between $min and $max");
        }
        return $v;
    }

    public function bool(string $key, bool $required = true): ?bool
    {
        $v = $this->data[$key] ?? null;
        if ($v === null) {
            $required && self::fail("$key is required");
            return null;
        }
        if (!is_bool($v)) {
            self::fail("$key must be true or false");
        }
        return $v;
    }

    /** @param list<string> $allowed */
    public function enum(string $key, array $allowed, ?string $default = null): string
    {
        $v = $this->data[$key] ?? $default;
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            self::fail("$key must be one of: " . implode(', ', $allowed));
        }
        return $v;
    }

    /** ISO 8601 instant -> normalised "…Z". */
    public function instant(string $key): string
    {
        $v = $this->data[$key] ?? null;
        $d = is_string($v) ? Time::parseIso($v) : null;
        if ($d === null) {
            self::fail("$key must be an ISO 8601 time such as 2026-11-09T07:00:00Z");
        }
        return Time::iso($d);
    }

    /** "Y-m-d" local date. */
    public function date(string $key): string
    {
        $v = $this->data[$key] ?? null;
        if (!is_string($v) || Time::localDate($v) === null) {
            self::fail("$key must be a date like 2026-11-09");
        }
        return $v;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->data;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public static function daysBetween(string $from, string $to): int
    {
        $a = Time::localDate($from);
        $b = Time::localDate($to);
        return (int) $a->diff($b)->format('%r%a') + 1;
    }
}
