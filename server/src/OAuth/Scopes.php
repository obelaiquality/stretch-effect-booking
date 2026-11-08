<?php
declare(strict_types=1);

namespace StretchEffect\OAuth;

/** The four scopes of the ChatGPT connector. No wildcard and no admin scope exist. */
final class Scopes
{
    public const ALL = ['bookings:read', 'bookings:write', 'clients:read', 'credits:write'];

    public const DESCRIPTIONS = [
        'bookings:read'  => 'See the schedule, bookings, free slots and the business summary',
        'clients:read'   => 'Look up clients and their session balances',
        'bookings:write' => 'Create, move and cancel bookings',
        'credits:write'  => 'Add or remove client session credits',
    ];

    /** @return list<string> unique scope tokens, in the order given */
    public static function parse(?string $scope): array
    {
        if ($scope === null) {
            return [];
        }
        $parts = preg_split('/\s+/', trim($scope), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique($parts));
    }

    /** @param list<string> $scopes */
    public static function allValid(array $scopes): bool
    {
        foreach ($scopes as $s) {
            if (!in_array($s, self::ALL, true)) {
                return false;
            }
        }
        return true;
    }

    /** Keep the canonical order. @param list<string> $scopes @return list<string> */
    public static function normalize(array $scopes): array
    {
        return array_values(array_filter(self::ALL, static fn(string $s) => in_array($s, $scopes, true)));
    }
}
