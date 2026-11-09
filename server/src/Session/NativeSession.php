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

    /** Anonymous (no signed-in user) session files are removed after this many seconds of inactivity, by Maintenance. */
    public const ANONYMOUS_MAX_AGE = 7200;

    /** True when the request carries our session cookie (so there may be a session worth loading). */
    private function hasCookie(): bool
    {
        return isset($_COOKIE[$this->name]) && is_string($_COOKIE[$this->name]) && $_COOKIE[$this->name] !== '';
    }

    /** Reads and removals never create a session for a visitor without one. */
    private function canSkip(): bool
    {
        return !$this->started && session_status() !== PHP_SESSION_ACTIVE && !$this->hasCookie();
    }

    /**
     * Delete session files older than $maxAge seconds that never held a signed-in user (no `uid` key). Signed-in sessions
     * live for the full cookie lifetime (PHP's own GC, gc_maxlifetime). @return int files deleted
     */
    public static function pruneAnonymous(string $savePath, int $maxAge = self::ANONYMOUS_MAX_AGE, ?int $now = null): int
    {
        $now ??= time();
        $n = 0;
        foreach (glob(rtrim($savePath, '/') . '/sess_*') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime === false || $mtime >= $now - $maxAge) {
                continue;
            }
            $body = @file_get_contents($file);
            if ($body === false || str_contains($body, 'uid|s:')) {
                continue;
            }
            if (@unlink($file)) {
                $n++;
            }
        }
        return $n;
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
        if ($this->canSkip()) {
            return $default;
        }
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
        if ($this->canSkip()) {
            return;
        }
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
