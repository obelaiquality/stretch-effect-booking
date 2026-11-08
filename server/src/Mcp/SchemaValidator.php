<?php
declare(strict_types=1);

namespace StretchEffect\Mcp;

/** Strict validator for the small JSON-Schema subset used by our tool input schemas. */
final class SchemaValidator
{
    /**
     * @param array<string,mixed> $schema object schema (properties, required, additionalProperties:false)
     * @param array<string,mixed> $args
     * @return array<string,mixed> arguments with defaults applied
     * @throws ToolInputError listing every problem (field names only, never the offending values)
     */
    public static function validate(array $schema, array $args): array
    {
        $errors = [];
        $props = $schema['properties'] ?? [];
        foreach (array_keys($args) as $k) {
            if (!is_string($k) || !isset($props[$k])) {
                $errors[] = 'unknown argument "' . self::safeKey((string) $k) . '"';
            }
        }
        foreach ($schema['required'] ?? [] as $req) {
            if (!array_key_exists($req, $args) || $args[$req] === null) {
                $errors[] = '"' . $req . '" is required';
            }
        }
        $out = [];
        foreach ($props as $name => $def) {
            if (!array_key_exists($name, $args) || $args[$name] === null) {
                continue;
            }
            $err = self::check($def, $args[$name]);
            if ($err !== null) {
                $errors[] = '"' . $name . '" ' . $err;
            } else {
                $out[$name] = $args[$name];
            }
        }
        if ($errors !== []) {
            throw new ToolInputError('Invalid arguments: ' . implode('; ', $errors) . '.');
        }
        return $out;
    }

    private static function safeKey(string $k): string
    {
        return mb_substr(preg_replace('/[^\w.-]/u', '?', $k) ?? '', 0, 30);
    }

    /** @param array<string,mixed> $def */
    private static function check(array $def, mixed $v): ?string
    {
        switch ($def['type']) {
            case 'string':
                if (!is_string($v)) {
                    return 'must be a string';
                }
                if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $v) === 1) {
                    return 'contains control characters';
                }
                $len = mb_strlen($v);
                if (isset($def['minLength']) && $len < $def['minLength']) {
                    return 'must be at least ' . $def['minLength'] . ' characters';
                }
                if (isset($def['maxLength']) && $len > $def['maxLength']) {
                    return 'must be at most ' . $def['maxLength'] . ' characters';
                }
                if (isset($def['enum']) && !in_array($v, $def['enum'], true)) {
                    return 'must be one of: ' . implode(', ', $def['enum']);
                }
                if (isset($def['pattern']) && preg_match('/' . $def['pattern'] . '/D', $v) !== 1) {
                    return 'has the wrong format' . (isset($def['format']) ? ' (expected ' . $def['format'] . ')' : '');
                }
                if (($def['format'] ?? null) === 'date' && !self::validDate($v)) {
                    return 'is not a real date (expected YYYY-MM-DD)';
                }
                if (($def['format'] ?? null) === 'local-datetime' && !self::validLocalDateTime($v)) {
                    return 'is not a real date and time (expected YYYY-MM-DDTHH:MM)';
                }
                return null;
            case 'integer':
                if (!is_int($v)) {
                    return 'must be a whole number';
                }
                if (isset($def['minimum']) && $v < $def['minimum']) {
                    return 'must be at least ' . $def['minimum'];
                }
                if (isset($def['maximum']) && $v > $def['maximum']) {
                    return 'must be at most ' . $def['maximum'];
                }
                return null;
            case 'boolean':
                return is_bool($v) ? null : 'must be true or false';
        }
        return 'has an unsupported type';
    }

    public static function validDate(string $v): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) !== 1) {
            return false;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v, new \DateTimeZone('UTC'));
        return $d !== false && $d->format('Y-m-d') === $v;
    }

    public static function validLocalDateTime(string $v): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $v) !== 1) {
            return false;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $v, new \DateTimeZone('UTC'));
        return $d !== false && $d->format('Y-m-d\TH:i') === $v;
    }
}
