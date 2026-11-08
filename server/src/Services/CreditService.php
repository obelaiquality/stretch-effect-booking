<?php
declare(strict_types=1);

namespace StretchEffect\Services;

use StretchEffect\Contracts\CreditService as CreditServiceContract;
use StretchEffect\Contracts\DomainError;
use StretchEffect\Db;
use StretchEffect\Support\Presenter;
use StretchEffect\Support\Time;

/** Ledger only: balance is always SUM(delta). */
final class CreditService implements CreditServiceContract
{
    private const REASONS = ['purchase', 'booking', 'cancel_refund', 'owner_adjust', 'chatgpt_adjust', 'welcome_offer'];

    public function __construct(private readonly Db $db)
    {
    }

    public function balance(string $userId): int
    {
        return (int) $this->db->val('SELECT COALESCE(SUM(delta), 0) FROM credit_ledger WHERE user_id = ?', [$userId]);
    }

    public function adjust(string $userId, int $delta, string $reason, ?string $note, string $actor, ?string $bookingId = null): array
    {
        if ($delta === 0) {
            throw new DomainError('validation', 'Credit change cannot be zero');
        }
        if (!in_array($reason, self::REASONS, true)) {
            throw new DomainError('validation', 'Unknown ledger reason');
        }
        return $this->db->transaction(function () use ($userId, $delta, $reason, $note, $actor, $bookingId): array {
            if ($this->db->val('SELECT 1 FROM users WHERE id = ?' . $this->db->forUpdate(), [$userId]) === null) {
                throw new DomainError('not_found', 'Client not found');
            }
            if ($delta < 0 && $this->balance($userId) + $delta < 0) {
                throw new DomainError('no_credits', 'Not enough session credits');
            }
            $id = $this->db->newId();
            $now = Time::toDb($this->db->now());
            $note = $note === null ? null : mb_substr(trim($note), 0, 255);
            $this->db->run(
                'INSERT INTO credit_ledger (id, user_id, delta, reason, note, actor, booking_id, created_at) VALUES (?,?,?,?,?,?,?,?)',
                [$id, $userId, $delta, $reason, $note === '' ? null : $note, mb_substr($actor, 0, 60), $bookingId, $now]
            );
            return Presenter::ledger($this->db->one('SELECT * FROM credit_ledger WHERE id = ?', [$id]) ?? []);
        });
    }

    public function chatgptAddedToday(): int
    {
        $tz = Time::tz();
        $start = $this->db->now()->setTimezone($tz)->setTime(0, 0);
        $end = $start->modify('+1 day');
        return (int) $this->db->val(
            "SELECT COALESCE(SUM(delta), 0) FROM credit_ledger WHERE reason = 'chatgpt_adjust' AND delta > 0 AND created_at >= ? AND created_at < ?",
            [Time::toDb($start), Time::toDb($end)]
        );
    }

    public function markPackPaid(string $packRequestId, ?string $paymentReference, string $actor): array
    {
        $ref = $paymentReference === null ? null : PaymentReferences::normalize($paymentReference);
        $ref = $ref === '' ? null : $ref;
        return $this->db->transaction(function () use ($packRequestId, $ref, $actor): array {
            $pack = $this->db->one('SELECT * FROM pack_requests WHERE id = ?' . $this->db->forUpdate(), [$packRequestId]);
            if ($pack === null) {
                throw new DomainError('not_found', 'Pack request not found');
            }
            if ($pack['status'] !== 'pending') {
                throw new DomainError('conflict', 'This pack request is already ' . $pack['status']);
            }
            if ($ref !== null) {
                // Same once-only table as the ChatGPT tool: one EFT reference cannot be used through both paths.
                PaymentReferences::claim($this->db->pdo(), $ref, (string) $pack['user_id'], 'admin', Time::toDb($this->db->now()));
            }
            try {
                $this->db->run(
                    "UPDATE pack_requests SET status = 'paid', paid_at = ?, payment_reference = ? WHERE id = ?",
                    [Time::toDb($this->db->now()), $ref, $packRequestId]
                );
            } catch (\PDOException) {
                throw new DomainError('conflict', 'That payment reference has already been used');
            }
            $this->adjust((string) $pack['user_id'], (int) $pack['sessions'], 'purchase',
                'Pack: ' . $pack['package_id'] . ($ref !== null ? ' (ref ' . $ref . ')' : ''), $actor);
            return Presenter::pack($this->db->one('SELECT * FROM pack_requests WHERE id = ?', [$packRequestId]) ?? []);
        });
    }

    // ---------- extras used by the web API ----------

    /** @return list<array<string,mixed>> */
    public function ledger(string $userId, int $limit = 500): array
    {
        $rows = $this->db->all('SELECT * FROM credit_ledger WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(1000, $limit)), [$userId]);
        return array_map([Presenter::class, 'ledger'], $rows);
    }

    /** @return list<array<string,mixed>> */
    public function packs(string $userId): array
    {
        $rows = $this->db->all('SELECT * FROM pack_requests WHERE user_id = ? ORDER BY created_at DESC, id DESC', [$userId]);
        return array_map([Presenter::class, 'pack'], $rows);
    }

    /** @return list<array<string,mixed>> */
    public function packages(): array
    {
        return array_map([Presenter::class, 'package'], $this->db->all('SELECT * FROM packages WHERE active = 1 ORDER BY sort, id'));
    }

    /** @return array<string,mixed> */
    public function requestPack(string $userId, string $packageId): array
    {
        $pkg = $this->db->one('SELECT * FROM packages WHERE id = ? AND active = 1', [$packageId]);
        if ($pkg === null) {
            throw new DomainError('not_found', 'Package not found');
        }
        if ((int) $this->db->val("SELECT COUNT(*) FROM pack_requests WHERE user_id = ? AND status = 'pending'", [$userId]) >= 5) {
            throw new DomainError('conflict', 'You already have several pending pack requests. Please wait for them to be confirmed.');
        }
        $id = $this->db->newId();
        $this->db->run(
            "INSERT INTO pack_requests (id, user_id, package_id, sessions, price_zar, status, created_at) VALUES (?,?,?,?,?,'pending',?)",
            [$id, $userId, $pkg['id'], $pkg['sessions'], $pkg['price_zar'], Time::toDb($this->db->now())]
        );
        return Presenter::pack($this->db->one('SELECT * FROM pack_requests WHERE id = ?', [$id]) ?? []);
    }
}
