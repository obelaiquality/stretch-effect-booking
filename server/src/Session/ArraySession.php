<?php
declare(strict_types=1);

namespace StretchEffect\Session;

/** In-memory session for tests and CLI. One instance = one browser. */
final class ArraySession implements SessionStore
{
    /** @var array<string,mixed> */
    public array $data = [];
    public int $regenerations = 0;

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(): void
    {
        $this->regenerations++;
    }

    public function destroy(): void
    {
        $this->data = [];
    }
}
