<?php
declare(strict_types=1);

namespace StretchEffect\Services;

use StretchEffect\Config;
use StretchEffect\Contracts\AuthService as AuthServiceContract;
use StretchEffect\Contracts\DomainError;
use StretchEffect\Crypto;
use StretchEffect\Db;
use StretchEffect\Session\SessionStore;
use StretchEffect\Support\Time;

final class AuthService implements AuthServiceContract
{
    private const PUBLIC_COLS = 'id, role, name, email, phone, totp_enabled, created_at';
    private const ARGON2ID_OPTIONS = ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1];

    public function __construct(
        private readonly Db $db,
        private readonly Config $config,
        private readonly Crypto $crypto,
        private readonly SessionStore $session,
        private readonly RateLimiter $limiter,
    ) {
    }

    // ---------- passwords ----------

    public static function hashPassword(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID, self::ARGON2ID_OPTIONS);
        }
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /** @return array<string,mixed>|null user row without secrets (snake_case columns) */
    public function userById(string $id): ?array
    {
        return $this->db->one('SELECT ' . self::PUBLIC_COLS . ' FROM users WHERE id = ?', [$id]);
    }

    public function verifyPassword(string $email, string $password, string $ip): ?array
    {
        $email = strtolower(trim($email));
        $row = $this->db->one('SELECT ' . self::PUBLIC_COLS . ', password_hash FROM users WHERE email = ?', [$email]);
        // Key the limiters on the stored address when the user exists: MySQL's collation treats look-alike spellings as the
        // same email, so a variant must not get a fresh budget.
        $canon = is_array($row) ? strtolower((string) $row['email']) : $email;
        $emailKey = hash('sha256', $canon);
        $pairKey = hash('sha256', $canon . '|' . $ip);
        // Strict limiter per email+IP (a stranger cannot burn the owner's own budget from another address); a looser
        // per-email limiter still slows a distributed guesser. All three are one generic message.
        if (!$this->limiter->hit('login_ip', $ip, 20, 900)
            || !$this->limiter->hit('login_email_ip', $pairKey, 5, 900)
            || !$this->limiter->hit('login_email', $emailKey, 30, 900)) {
            throw new DomainError('rate_limited', 'Too many sign-in attempts. Please wait a few minutes and try again.');
        }
        // Always run a hash verification so timing does not reveal whether the email exists.
        static $dummy = null;
        $dummy ??= self::hashPassword('dummy-password-for-timing');
        $ok = password_verify($password, $row['password_hash'] ?? $dummy);
        if ($row === null || !$ok) {
            return null;
        }
        $this->limiter->reset('login_email_ip', $pairKey);
        $this->limiter->reset('login_email', $emailKey);
        // the same options as hashPassword(): without them every argon2id hash looks outdated and is rewritten on each login
        if (defined('PASSWORD_ARGON2ID')
            ? password_needs_rehash($row['password_hash'], PASSWORD_ARGON2ID, self::ARGON2ID_OPTIONS)
            : password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [self::hashPassword($password), $row['id']]);
        }
        unset($row['password_hash']);
        return $row;
    }

    // ---------- TOTP ----------

    public function verifyTotp(string $userId, string $code, string $ip = ''): bool
    {
        // Per user+IP (8 / 15 min) and per user (30 / 15 min): a stranger on another address cannot exhaust the owner's attempts.
        if (!$this->limiter->hit('totp_ip', $userId . '|' . ($ip === '' ? '-' : $ip), 8, 900) || !$this->limiter->hit('totp', $userId, 30, 900)) {
            throw new DomainError('rate_limited', 'Too many attempts. Please wait a few minutes and try again.');
        }
        $row = $this->db->one('SELECT totp_secret_enc, totp_enabled, totp_last_step FROM users WHERE id = ?', [$userId]);
        if ($row === null || !(int) $row['totp_enabled'] || empty($row['totp_secret_enc'])) {
            return false;
        }
        $secret = $this->crypto->decrypt((string) $row['totp_secret_enc']);
        $step = Totp::match($secret, trim($code), $this->db->now()->getTimestamp());
        if ($step === null) {
            return false;
        }
        if ($row['totp_last_step'] !== null && $step <= (int) $row['totp_last_step']) {
            return false; // same or older time step: replay
        }
        // Atomic: only one request can claim this step.
        $claimed = $this->db->run(
            'UPDATE users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)',
            [$step, $userId, $step]
        );
        return $claimed === 1;
    }

    // ---------- web session ----------

    public function currentUser(): ?array
    {
        $uid = $this->session->get('uid');
        if (!is_string($uid) || $uid === '') {
            return null;
        }
        $user = $this->userById($uid);
        if ($user === null) {
            $this->session->remove('uid');
        }
        return $user;
    }

    /** Full login (clients after password; owners after TOTP). */
    public function startSession(string $userId): void
    {
        $this->session->regenerate();
        $this->session->remove('pending_totp');
        $this->session->remove('totp_failures');
        $this->session->set('uid', $userId);
    }

    /** Owner: password accepted, waiting for the TOTP code. */
    public function beginTotp(string $userId): void
    {
        $this->session->regenerate();
        $this->session->remove('uid');
        $this->session->set('pending_totp', ['uid' => $userId, 'at' => $this->db->now()->getTimestamp()]);
    }

    /** @return array<string,mixed>|null the user when the code is right */
    public function completeTotp(string $code, string $ip = ''): ?array
    {
        $p = $this->session->get('pending_totp');
        if (!is_array($p) || !isset($p['uid']) || $this->db->now()->getTimestamp() - (int) $p['at'] > 600) {
            $this->session->remove('pending_totp');
            throw new DomainError('unauthorized', 'Sign in with your password first.');
        }
        if ($this->verifyTotp((string) $p['uid'], $code, $ip)) {
            $this->startSession((string) $p['uid']);
            return $this->userById((string) $p['uid']);
        }
        $fails = (int) $this->session->get('totp_failures', 0) + 1;
        $this->session->set('totp_failures', $fails);
        if ($fails >= 5) {
            $this->session->remove('pending_totp');
        }
        return null;
    }

    public function logout(): void
    {
        $this->session->destroy();
    }

    public function csrfToken(): string
    {
        $t = $this->session->get('csrf');
        if (!is_string($t) || $t === '') {
            $t = $this->crypto->randomToken(32);
            $this->session->set('csrf', $t);
        }
        return $t;
    }

    public function checkCsrf(?string $token): bool
    {
        $t = $this->session->get('csrf');
        return is_string($t) && $t !== '' && $token !== null && $token !== '' && hash_equals($t, $token);
    }

    public function session(): SessionStore
    {
        return $this->session;
    }
}
