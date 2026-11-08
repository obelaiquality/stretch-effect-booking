<?php
declare(strict_types=1);

namespace StretchEffect;

use StretchEffect\Contracts\Config as ConfigContract;

final class Config implements ConfigContract
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data)
    {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Config file not found: ' . $path);
        }
        $data = require $path;
        if (!is_array($data)) {
            throw new \RuntimeException('Config file must return an array');
        }
        return new self($data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $cur = $this->data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return $default;
            }
            $cur = $cur[$part];
        }
        return $cur;
    }

    public function baseUrl(): string
    {
        return rtrim((string) $this->get('app.base_url', 'http://localhost'), '/');
    }

    public function isLive(): bool
    {
        return $this->get('app.env', 'dev') === 'live';
    }
}
