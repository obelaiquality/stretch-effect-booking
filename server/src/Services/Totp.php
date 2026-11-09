<?php
declare(strict_types=1);

namespace StretchEffect\Services;

/** RFC 6238 TOTP: SHA1, 6 digits, 30 s step. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $s): string
    {
        $s = strtoupper(preg_replace('/[\s=-]/', '', $s) ?? '');
        $bits = '';
        foreach (str_split($s) as $c) {
            $p = strpos(self::ALPHABET, $c);
            if ($p === false) {
                return '';
            }
            $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    public static function step(int $timestamp): int
    {
        return intdiv($timestamp, 30);
    }

    public static function code(string $secretBase32, int $step): string
    {
        $key = self::base32Decode($secretBase32);
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $bin = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($bin % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Matching time step within +-$window steps, or null. */
    public static function match(string $secretBase32, string $code, int $timestamp, int $window = 1): ?int
    {
        if (!preg_match('/^\d{6}$/D', $code)) {
            return null;
        }
        $now = self::step($timestamp);
        $found = null;
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secretBase32, $now + $i), $code)) {
                $found = $now + $i;
            }
        }
        return $found;
    }

    public static function otpauthUri(string $secret, string $account, string $issuer = 'The Stretch Effect'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }
}
