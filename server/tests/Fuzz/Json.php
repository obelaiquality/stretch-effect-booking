<?php
declare(strict_types=1);

namespace StretchEffect\Tests\Fuzz;

/** JSON writer that can emit what json_encode refuses: invalid UTF-8, raw tokens, duplicate keys. */
final class Json
{
    public static function encode(mixed $v): string
    {
        if ($v instanceof RawJson) {
            return $v->text;
        }
        if ($v instanceof Pairs) {
            $parts = [];
            foreach ($v->pairs as [$k, $val]) {
                $parts[] = self::str((string) $k) . ':' . self::encode($val);
            }
            return '{' . implode(',', $parts) . '}';
        }
        if ($v instanceof \stdClass) {
            $v = (array) $v;
            if ($v === []) {
                return '{}';
            }
            return self::assoc($v);
        }
        if (is_array($v)) {
            if ($v === []) {
                return '[]';
            }
            if (array_is_list($v)) {
                return '[' . implode(',', array_map([self::class, 'encode'], $v)) . ']';
            }
            return self::assoc($v);
        }
        if (is_string($v)) {
            return self::str($v);
        }
        if (is_float($v)) {
            return is_finite($v) ? json_encode($v, JSON_PRESERVE_ZERO_FRACTION) : 'null';
        }
        return json_encode($v);
    }

    /** @param array<int|string,mixed> $a */
    private static function assoc(array $a): string
    {
        $parts = [];
        foreach ($a as $k => $val) {
            $parts[] = self::str((string) $k) . ':' . self::encode($val);
        }
        return '{' . implode(',', $parts) . '}';
    }

    private static function str(string $s): string
    {
        if (mb_check_encoding($s, 'UTF-8')) {
            return (string) json_encode($s, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE);
        }
        $enc = (string) json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return str_replace("\u{FFFD}", "\xC3\x28", $enc); // an invalid UTF-8 sequence inside the string
    }
}
