<?php
declare(strict_types=1);

namespace StretchEffect\OAuth;

use StretchEffect\Contracts\Services;

/**
 * The owner-opened "pairing window". A client with no active (non-revoked) grant can only reach the consent page while the
 * window is open, so a stranger who registers their own client cannot phish the owner into approving it.
 * Stored in `settings` under key `connector_pairing_until` as {"until": "Y-m-d H:i:s"} (UTC). Single use: closed when
 * a grant is created for a new client.
 */
final class Pairing
{
    public const KEY = 'connector_pairing_until';
    public const MINUTES = 15;

    public function __construct(private Services $s) {}

    private function now(): \DateTimeImmutable
    {
        return $this->s->db()->now();
    }

    /** @return string|null stored UTC "Y-m-d H:i:s" while the window is still open, else null */
    private function untilDb(): ?string
    {
        $st = $this->s->db()->pdo()->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute([self::KEY]);
        $v = $st->fetchColumn();
        if ($v === false) {
            return null;
        }
        $d = json_decode((string) $v, true);
        $until = is_array($d) && is_string($d['until'] ?? null) ? $d['until'] : null;
        return $until !== null && $until > TokenStore::ts($this->now()) ? $until : null;
    }

    public function isOpen(): bool
    {
        return $this->untilDb() !== null;
    }

    /** @return array{open:bool,until:string|null} until as ISO UTC "Y-m-d\TH:i:s\Z" */
    public function status(): array
    {
        $u = $this->untilDb();
        return ['open' => $u !== null, 'until' => $u === null ? null : (new \DateTimeImmutable($u, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z')];
    }

    /** @return array{open:bool,until:string|null} */
    public function open(): array
    {
        $this->put(TokenStore::ts($this->now()->modify('+' . self::MINUTES . ' minutes')));
        return $this->status();
    }

    /** @return array{open:bool,until:string|null} */
    public function close(): array
    {
        $st = $this->s->db()->pdo()->prepare('DELETE FROM settings WHERE k = ?');
        $st->execute([self::KEY]);
        return $this->status();
    }

    /** True when this client already has a non-revoked grant (re-authorisation needs no window). */
    public function clientHasActiveGrant(string $clientId): bool
    {
        $st = $this->s->db()->pdo()->prepare('SELECT 1 FROM oauth_grants WHERE client_id = ? AND revoked_at IS NULL LIMIT 1');
        $st->execute([$clientId]);
        return $st->fetchColumn() !== false;
    }

    private function put(string $until): void
    {
        $pdo = $this->s->db()->pdo();
        $json = json_encode(['until' => $until]);
        $now = TokenStore::ts($this->now());
        $up = $pdo->prepare('UPDATE settings SET v = ?, updated_at = ? WHERE k = ?');
        $up->execute([$json, $now, self::KEY]);
        if ($up->rowCount() === 0) {
            try {
                $pdo->prepare('INSERT INTO settings (k, v, updated_at) VALUES (?,?,?)')->execute([self::KEY, $json, $now]);
            } catch (\PDOException) {
                $up->execute([$json, $now, self::KEY]);
            }
        }
    }
}
