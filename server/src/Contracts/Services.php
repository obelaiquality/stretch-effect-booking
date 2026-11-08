<?php
declare(strict_types=1);

namespace StretchEffect\Contracts;

use DateTimeImmutable;
use PDO;

/**
 * Service contracts. server-core implements them in StretchEffect\Services\*.
 * server-mcp (OAuth + MCP) uses ONLY these interfaces plus Database.
 * Times passed in/out: UTC strings "Y-m-d\TH:i:s\Z". Local dates: "Y-m-d" in Africa/Johannesburg.
 * Errors: throw DomainError with a code from DomainError::CODES.
 */
interface Database
{
    public function pdo(): PDO;
    public function now(): DateTimeImmutable;         // UTC; tests may freeze it
    public function newId(): string;                  // 16 hex chars
    /** @template T @param callable():T $fn @return T  (BEGIN / COMMIT / ROLLBACK) */
    public function transaction(callable $fn): mixed;
    public function driver(): string;                 // 'mysql' | 'sqlite'
}

interface Config
{
    public function get(string $key, mixed $default = null): mixed; // dot paths, e.g. "app.base_url"
    public function baseUrl(): string;                // "https://thestretcheffect.co.za" (no trailing slash)
    public function isLive(): bool;
}

interface Crypto
{
    public function encrypt(string $plain): string;   // sodium secretbox, base64
    public function decrypt(string $cipher): string;
    public function randomToken(int $bytes = 32): string; // base64url
    public function hash(string $token): string;      // sha256 hex
}

interface AuthService
{
    /** Signed-in user from the web session cookie, or null. @return array<string,mixed>|null user row (no secrets) */
    public function currentUser(): ?array;
    /** Check email + password (rate limited by caller's IP). @return array<string,mixed>|null user row */
    public function verifyPassword(string $email, string $password, string $ip): ?array;
    /** Check a 6-digit TOTP code for this user; rejects reuse of the same time step. */
    public function verifyTotp(string $userId, string $code, string $ip = ''): bool;
    public function csrfToken(): string;
    public function checkCsrf(?string $token): bool;
}

interface CreditService
{
    public function balance(string $userId): int;
    /** Append a ledger row. @return array<string,mixed> ledger entry (API shape) */
    public function adjust(string $userId, int $delta, string $reason, ?string $note, string $actor, ?string $bookingId = null): array;
    /** Credits added via ChatGPT for the current SA calendar day (for the daily cap). */
    public function chatgptAddedToday(): int;
    /** Mark a pending pack request paid and add its credits. */
    public function markPackPaid(string $packRequestId, ?string $paymentReference, string $actor): array;
}

interface ClientService
{
    /** @return list<array<string,mixed>> ClientSummary shapes (see web/src/domain/types.ts) */
    public function search(string $query, int $limit = 20): array;
    /** @return array<string,mixed>|null {summary, ledger, bookings, packs} */
    public function get(string $userId): ?array;
}

interface BookingService
{
    /** @return list<array{start:string,end:string}> */
    public function availability(string $fromDate, string $toDate): array;
    /** @return list<array<string,mixed>> Booking shapes */
    public function list(string $fromDate, string $toDate, ?string $status = null): array;
    public function get(string $bookingId): ?array;
    /** Creates booking, takes 1 credit, writes Google event. Throws no_credits / conflict. */
    public function create(string $userId, string $startUtc, string $createdBy, string $actor): array;
    /** @return array{booking:array<string,mixed>,refunded:bool} */
    public function cancel(string $bookingId, ?bool $refund, string $reason, string $actor): array;
    public function reschedule(string $bookingId, string $newStartUtc, string $actor): array;
    /** BusinessSummary shape */
    public function summary(string $period): array;
    /** Local "Y-m-d\TH:i" (Africa/Johannesburg) -> UTC string. */
    public function localToUtc(string $local): string;
}

interface AuditLog
{
    /** @param array<string,mixed> $args already redacted */
    public function write(?string $grantId, string $actor, string $action, array $args, string $status, string $summary, ?string $ip): void;
    /** @return list<array<string,mixed>> AuditEntry shapes */
    public function recent(int $limit = 100): array;
}

interface RateLimiter
{
    /** Fixed window. Returns false when over the limit (and does not count the hit). */
    public function hit(string $name, string $subject, int $limit, int $windowSeconds): bool;
}

/** Everything the OAuth + MCP code may use. Built by StretchEffect\App. */
interface Services
{
    public function db(): Database;
    public function config(): Config;
    public function crypto(): Crypto;
    public function auth(): AuthService;
    public function credits(): CreditService;
    public function clients(): ClientService;
    public function bookings(): BookingService;
    public function audit(): AuditLog;
    public function rateLimiter(): RateLimiter;
}
