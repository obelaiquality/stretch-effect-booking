<?php
declare(strict_types=1);

namespace StretchEffect\Session;

/** PHP native sessions with a private save path. Started lazily on first use. */
final class NativeSession implements SessionStore
{
    private bool $started = false;

    public function __construct(
        private readonly string $savePath,
        private readonly bool $secure,
        private readonly string $name = 'se_sess',
        private readonly int $lifetime = 1209600,
    ) {
    }

    private function start(): void
    {
        if ($this->started) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }
        if (!is_dir($this->savePath)) {
            @mkdir($this->savePath, 0700, true);
        }
        session_save_path($this->savePath);
        session_name($this->name);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) $this->lifetime);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        session_set_cookie_params([
            'lifetime' => $this->lifetime,
            'path' => '/',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];
        if (!headers_sent()) {
            setcookie($this->name, '', [
                'expires' => time() - 3600, 'path' => '/', 'secure' => $this->secure,
                'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
        session_destroy();
        $this->started = false;
    }
}
