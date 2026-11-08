<?php
declare(strict_types=1);

namespace StretchEffect\Mcp;

/** Redacts tool arguments before they are written to audit_log: keep ids, numbers, dates; truncate free text. */
final class Redactor
{
    public const FREE_TEXT_MAX = 40;

    /** @param array<string,mixed> $args @return array<string,mixed> */
    public static function redact(array $args): array
    {
        $out = [];
        foreach ($args as $k => $v) {
            $key = (string) $k;
            if (is_int($v) || is_float($v) || is_bool($v) || $v === null) {
                $out[$key] = $v;
            } elseif (is_string($v)) {
                $clean = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $v) ?? '';
                $isId = $key === 'idempotency_key' || str_ends_with($key, '_id');
                $isStructured = $key === 'date' || $key === 'from' || $key === 'to' || $key === 'start' || $key === 'new_start'
                    || $key === 'period' || $key === 'status';
                $max = $isId ? 64 : ($isStructured ? 20 : self::FREE_TEXT_MAX);
                $out[$key] = mb_strlen($clean) > $max ? mb_substr($clean, 0, $max) . '...' : $clean;
            } else {
                $out[$key] = '[omitted]';
            }
        }
        $out['_args_hash'] = substr(hash('sha256', json_encode($args, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: ''), 0, 16);
        return $out;
    }
}
