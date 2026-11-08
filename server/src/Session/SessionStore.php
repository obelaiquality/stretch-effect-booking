<?php
declare(strict_types=1);

namespace StretchEffect\Session;

interface SessionStore
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value): void;
    public function remove(string $key): void;
    /** New session id, keeps data (call on login). */
    public function regenerate(): void;
    /** Drop all data and the cookie (logout). */
    public function destroy(): void;
}
