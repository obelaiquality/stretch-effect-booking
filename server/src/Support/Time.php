<?php
declare(strict_types=1);

namespace StretchEffect\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class Time
{
    public const ISO = 'Y-m-d\TH:i:s\Z';
    public const DB = 'Y-m-d H:i:s';
    public const TZ = 'Africa/Johannesburg';

    public static function tz(): DateTimeZone
    {
        return new DateTimeZone(self::TZ);
    }

    public static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    /** DB "Y-m-d H:i:s" (UTC) -> DateTimeImmutable (UTC). */
    public static function fromDb(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s, self::utc());
    }

    public static function toDb(DateTimeInterface $d): string
    {
        return DateTimeImmutable::createFromInterface($d)->setTimezone(self::utc())->format(self::DB);
    }

    /** DB string or DateTime -> "…Z" ISO string. */
    public static function iso(DateTimeInterface|string $d): string
    {
        if (is_string($d)) {
            $d = self::fromDb($d);
        }
        return DateTimeImmutable::createFromInterface($d)->setTimezone(self::utc())->format(self::ISO);
    }

    public static function isoOrNull(?string $dbValue): ?string
    {
        return $dbValue === null ? null : self::iso($dbValue);
    }

    /** Parse an ISO 8601 instant with Z or offset. Null when invalid. */
    public static function parseIso(string $s): ?DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $s)) {
            return null;
        }
        try {
            return (new DateTimeImmutable($s))->setTimezone(self::utc());
        } catch (\Throwable) {
            return null;
        }
    }

    /** "Y-m-d" -> local midnight (Africa/Johannesburg). Null when invalid. */
    public static function localDate(string $s): ?DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s, self::tz());
        if ($d === false || $d->format('Y-m-d') !== $s) {
            return null;
        }
        return $d;
    }
}
