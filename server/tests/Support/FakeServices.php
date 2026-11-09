<?php
declare(strict_types=1);

namespace StretchEffect\Tests\Support;

use StretchEffect\Contracts\{AuditLog, AuthService, BookingService, ClientService, Config, CreditService, Crypto, Database, DomainError, RateLimiter, Services};

/** RFC 6238 helper for tests and the e2e script. */
final class FakeTotp
{
    public static function base32Decode(string $s): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(strtoupper(rtrim($s, '='))) as $c) {
            $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    public static function code(string $base32, int $time): string
    {
        $step = intdiv($time, 30);
        $hash = hash_hmac('sha1', pack('N*', 0, $step), self::base32Decode($base32), true);
        $o = ord($hash[19]) & 0xf;
        $n = ((ord($hash[$o]) & 0x7f) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
        return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    }
}

final class FakeDatabase implements Database
{
    public \PDO $pdo;
    public \DateTimeImmutable $now;

    public function __construct()
    {
        $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        foreach (['001_init.sql', '002_security_hardening.sql'] as $f) {
            $this->pdo->exec((string) file_get_contents(__DIR__ . '/../../migrations/sqlite/' . $f));
        }
        $this->now = new \DateTimeImmutable('2026-11-09 08:00:00', new \DateTimeZone('UTC'));
    }
    public function pdo(): \PDO { return $this->pdo; }
    public function now(): \DateTimeImmutable { return $this->now; }
    public function newId(): string { return bin2hex(random_bytes(8)); }
    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $r = $fn();
            $this->pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
    public function driver(): string { return 'sqlite'; }
}

final class FakeConfig implements Config
{
    /** @param array<string,mixed> $data */
    public function __construct(public array $data = []) {}
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
    public function baseUrl(): string { return 'https://studio.test'; }
    public bool $live = true;
    public function isLive(): bool { return $this->live; }
}

final class FakeCrypto implements Crypto
{
    public function encrypt(string $plain): string { return base64_encode($plain); }
    public function decrypt(string $cipher): string { return (string) base64_decode($cipher); }
    public function randomToken(int $bytes = 32): string { return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '='); }
    public function hash(string $token): string { return hash('sha256', $token); }
}

final class FakeAuth implements AuthService
{
    /** @var array<string,string> user id => base32 secret */
    public array $totpSecrets = [];
    /** @var array<string,int> */
    private array $lastStep = [];
    public function __construct(private FakeDatabase $db) {}
    public function currentUser(): ?array { return null; }
    public function verifyPassword(string $email, string $password, string $ip): ?array
    {
        $st = $this->db->pdo->prepare('SELECT * FROM users WHERE email = ?');
        $st->execute([strtolower($email)]);
        $u = $st->fetch();
        if (!$u || !password_verify($password, $u['password_hash'])) {
            return null;
        }
        unset($u['password_hash'], $u['totp_secret_enc']);
        return $u;
    }
    public function verifyTotp(string $userId, string $code, string $ip = ''): bool
    {
        $secret = $this->totpSecrets[$userId] ?? null;
        if ($secret === null) {
            return false;
        }
        $t = $this->db->now->getTimestamp();
        foreach ([-1, 0, 1] as $drift) {
            $step = intdiv($t, 30) + $drift;
            if (hash_equals(FakeTotp::code($secret, $step * 30), $code)) {
                if (($this->lastStep[$userId] ?? 0) >= $step) {
                    return false;
                }
                $this->lastStep[$userId] = $step;
                return true;
            }
        }
        return false;
    }
    public function csrfToken(): string { return 'x'; }
    public function checkCsrf(?string $token): bool { return true; }
}

final class FakeCredits implements CreditService
{
    public function __construct(private FakeDatabase $db) {}
    public function balance(string $userId): int
    {
        $st = $this->db->pdo->prepare('SELECT COALESCE(SUM(delta),0) FROM credit_ledger WHERE user_id = ?');
        $st->execute([$userId]);
        return (int) $st->fetchColumn();
    }
    public function adjust(string $userId, int $delta, string $reason, ?string $note, string $actor, ?string $bookingId = null): array
    {
        $id = $this->db->newId();
        $at = $this->db->now->format('Y-m-d H:i:s');
        $st = $this->db->pdo->prepare('INSERT INTO credit_ledger (id,user_id,delta,reason,note,actor,booking_id,created_at) VALUES (?,?,?,?,?,?,?,?)');
        $st->execute([$id, $userId, $delta, $reason, $note, $actor, $bookingId, $at]);
        return ['id' => $id, 'userId' => $userId, 'delta' => $delta, 'reason' => $reason, 'actor' => $actor, 'createdAt' => $at];
    }
    public function chatgptAddedToday(): int
    {
        $st = $this->db->pdo->prepare("SELECT COALESCE(SUM(delta),0) FROM credit_ledger WHERE reason = 'chatgpt_adjust' AND delta > 0 AND substr(created_at,1,10) = ?");
        $st->execute([$this->db->now->format('Y-m-d')]);
        return (int) $st->fetchColumn();
    }
    public function markPackPaid(string $packRequestId, ?string $paymentReference, string $actor): array { return []; }
}

final class FakeClients implements ClientService
{
    public function __construct(private FakeDatabase $db, private FakeCredits $credits) {}
    private function summary(array $u): array
    {
        return ['user' => ['id' => $u['id'], 'role' => $u['role'], 'name' => $u['name'], 'email' => $u['email']],
            'balance' => $this->credits->balance($u['id']), 'upcoming' => 0, 'pendingPacks' => 0];
    }
    public function search(string $query, int $limit = 20): array
    {
        $st = $this->db->pdo->prepare("SELECT * FROM users WHERE role = 'client' AND (name LIKE ? OR email LIKE ?) LIMIT " . (int) $limit);
        $st->execute(['%' . $query . '%', '%' . $query . '%']);
        return array_map(fn($u) => $this->summary($u), $st->fetchAll());
    }
    public function get(string $userId): ?array
    {
        $st = $this->db->pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'client'");
        $st->execute([$userId]);
        $u = $st->fetch();
        if (!$u) {
            return null;
        }
        $l = $this->db->pdo->prepare('SELECT delta, reason, created_at AS createdAt, note FROM credit_ledger WHERE user_id = ? ORDER BY created_at DESC');
        $l->execute([$userId]);
        return ['summary' => $this->summary($u), 'ledger' => $l->fetchAll(), 'bookings' => [], 'packs' => []];
    }
}

final class FakeBookings implements BookingService
{
    public function __construct(private FakeDatabase $db, private FakeCredits $credits) {}
    public function availability(string $fromDate, string $toDate): array
    {
        return [['start' => $fromDate . 'T07:00:00Z', 'end' => $fromDate . 'T08:00:00Z'], ['start' => $fromDate . 'T09:00:00Z', 'end' => $fromDate . 'T10:00:00Z']];
    }
    private function shape(array $b): array
    {
        return ['id' => $b['id'], 'userId' => $b['user_id'], 'clientName' => $b['name'] ?? '', 'start' => str_replace(' ', 'T', $b['start_at']) . 'Z',
            'end' => str_replace(' ', 'T', $b['end_at']) . 'Z', 'status' => $b['status'], 'createdBy' => $b['created_by']];
    }
    public function list(string $fromDate, string $toDate, ?string $status = null): array
    {
        $sql = 'SELECT b.*, u.name FROM bookings b JOIN users u ON u.id = b.user_id WHERE substr(b.start_at,1,10) BETWEEN ? AND ?' . ($status ? ' AND b.status = ?' : '') . ' ORDER BY b.start_at';
        $st = $this->db->pdo->prepare($sql);
        $st->execute($status ? [$fromDate, $toDate, $status] : [$fromDate, $toDate]);
        return array_map(fn($b) => $this->shape($b), $st->fetchAll());
    }
    public function get(string $bookingId): ?array
    {
        $st = $this->db->pdo->prepare('SELECT b.*, u.name FROM bookings b JOIN users u ON u.id = b.user_id WHERE b.id = ?');
        $st->execute([$bookingId]);
        $b = $st->fetch();
        return $b ? $this->shape($b) : null;
    }
    public function create(string $userId, string $startUtc, string $createdBy, string $actor): array
    {
        if ($this->credits->balance($userId) < 1) {
            throw new DomainError('no_credits', 'The client has no sessions left.');
        }
        $id = $this->db->newId();
        $start = new \DateTimeImmutable($startUtc);
        $st = $this->db->pdo->prepare('INSERT INTO bookings (id,user_id,start_at,end_at,status,created_by,created_at) VALUES (?,?,?,?,?,?,?)');
        $st->execute([$id, $userId, $start->format('Y-m-d H:i:s'), $start->modify('+60 minutes')->format('Y-m-d H:i:s'), 'confirmed', $createdBy, $this->db->now->format('Y-m-d H:i:s')]);
        $this->credits->adjust($userId, -1, 'booking', null, $actor, $id);
        return $this->get($id);
    }
    public function cancel(string $bookingId, ?bool $refund, string $reason, string $actor): array
    {
        $b = $this->get($bookingId);
        if ($b['status'] !== 'confirmed') {
            throw new DomainError('conflict', 'Only confirmed bookings can be cancelled.');
        }
        $this->db->pdo->prepare("UPDATE bookings SET status='cancelled', cancel_reason=? WHERE id=?")->execute([$reason, $bookingId]);
        if ($refund) {
            $this->credits->adjust($b['userId'], 1, 'cancel_refund', null, $actor, $bookingId);
        }
        return ['booking' => $this->get($bookingId), 'refunded' => (bool) $refund];
    }
    public function reschedule(string $bookingId, string $newStartUtc, string $actor): array
    {
        $start = new \DateTimeImmutable($newStartUtc);
        $this->db->pdo->prepare('UPDATE bookings SET start_at=?, end_at=? WHERE id=?')
            ->execute([$start->format('Y-m-d H:i:s'), $start->modify('+60 minutes')->format('Y-m-d H:i:s'), $bookingId]);
        return $this->get($bookingId);
    }
    public function summary(string $period): array
    {
        return ['period' => $period, 'from' => '2026-11-09', 'to' => '2026-11-15', 'bookedSessions' => 3, 'completedSessions' => 1,
            'cancelledSessions' => 0, 'freeSlotHours' => 20, 'creditsSold' => 10, 'newClients' => 1, 'secretish' => ['nested' => 1]];
    }
    public function localToUtc(string $local): string
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone('Africa/Johannesburg')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}

final class FakeAudit implements AuditLog
{
    public function __construct(private FakeDatabase $db) {}
    public function write(?string $grantId, string $actor, string $action, array $args, string $status, string $summary, ?string $ip): void
    {
        $st = $this->db->pdo->prepare('INSERT INTO audit_log (id,at,grant_id,actor,action,args_json,status,summary,ip) VALUES (?,?,?,?,?,?,?,?,?)');
        $st->execute([$this->db->newId(), $this->db->now->format('Y-m-d H:i:s'), $grantId, $actor, $action, json_encode($args), $status, $summary, $ip]);
    }
    public function recent(int $limit = 100): array
    {
        return $this->db->pdo->query('SELECT * FROM audit_log ORDER BY at DESC LIMIT ' . (int) $limit)->fetchAll();
    }
}

final class FakeRateLimiter implements RateLimiter
{
    public function __construct(private FakeDatabase $db) {}
    public function hit(string $name, string $subject, int $limit, int $windowSeconds): bool
    {
        $t = $this->db->now->getTimestamp();
        $bucket = $name . ':' . $subject . ':' . intdiv($t, $windowSeconds) * $windowSeconds;
        $st = $this->db->pdo->prepare('SELECT hits FROM rate_limits WHERE bucket = ?');
        $st->execute([$bucket]);
        $hits = $st->fetchColumn();
        if ($hits !== false && (int) $hits >= $limit) {
            return false;
        }
        if ($hits === false) {
            $this->db->pdo->prepare('INSERT INTO rate_limits (bucket,hits,expires_at) VALUES (?,?,?)')->execute([$bucket, 1, gmdate('Y-m-d H:i:s', $t + $windowSeconds)]);
        } else {
            $this->db->pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?')->execute([$bucket]);
        }
        return true;
    }
}

final class FakeServices implements Services
{
    public FakeDatabase $database;
    public FakeConfig $cfg;
    public FakeCrypto $crypto;
    public FakeAuth $authSvc;
    public FakeCredits $creditSvc;
    public FakeClients $clientSvc;
    public FakeBookings $bookingSvc;
    public FakeAudit $auditSvc;
    public FakeRateLimiter $limiter;
    public string $ownerId;
    public string $clientId;

    public const OWNER_EMAIL = 'ivan@example.com';
    public const OWNER_PASSWORD = 'correct horse battery staple';
    public const CLIENT_PASSWORD = 'client password 123';
    public const TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    public function __construct()
    {
        $this->database = new FakeDatabase();
        $this->cfg = new FakeConfig(['connector' => [
            'enabled' => true,
            'allowed_redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect'],
            'allowed_redirect_prefixes' => ['https://chatgpt.com/connector/oauth/'],
            'cimd_hosts' => ['chatgpt.com', 'openai.com'],
        ]]);
        $this->crypto = new FakeCrypto();
        $this->authSvc = new FakeAuth($this->database);
        $this->creditSvc = new FakeCredits($this->database);
        $this->clientSvc = new FakeClients($this->database, $this->creditSvc);
        $this->bookingSvc = new FakeBookings($this->database, $this->creditSvc);
        $this->auditSvc = new FakeAudit($this->database);
        $this->limiter = new FakeRateLimiter($this->database);
        $this->ownerId = $this->addUser('owner', 'Ivan Bond', self::OWNER_EMAIL, self::OWNER_PASSWORD);
        $this->clientId = $this->addUser('client', 'Thandi Test', 'thandi@example.com', self::CLIENT_PASSWORD);
        $this->authSvc->totpSecrets[$this->ownerId] = self::TOTP_SECRET;
        $this->authSvc->totpSecrets[$this->clientId] = self::TOTP_SECRET; // even a client with a valid code must be refused
    }

    public function addUser(string $role, string $name, string $email, string $password): string
    {
        $id = $this->database->newId();
        $st = $this->database->pdo->prepare('INSERT INTO users (id,role,name,email,password_hash,created_at) VALUES (?,?,?,?,?,?)');
        $st->execute([$id, $role, $name, $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]), '2026-01-01 00:00:00']);
        return $id;
    }

    public function advance(int $seconds): void { $this->database->now = $this->database->now->modify("+{$seconds} seconds"); }

    public function db(): Database { return $this->database; }
    public function config(): Config { return $this->cfg; }
    public function crypto(): Crypto { return $this->crypto; }
    public function auth(): AuthService { return $this->authSvc; }
    public function credits(): CreditService { return $this->creditSvc; }
    public function clients(): ClientService { return $this->clientSvc; }
    public function bookings(): BookingService { return $this->bookingSvc; }
    public function audit(): AuditLog { return $this->auditSvc; }
    public function rateLimiter(): RateLimiter { return $this->limiter; }
}
