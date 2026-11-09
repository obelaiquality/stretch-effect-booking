<?php
declare(strict_types=1);

namespace StretchEffect\Services;

use StretchEffect\Contracts\DomainError;

/**
 * Once-only payment references, shared by the ChatGPT tool (add_session_credits) and the owner's "mark pack paid".
 * A reference is normalised (trim, collapse inner whitespace, upper case) so "pay-100 " and "PAY-100" are the same payment,
 * and kept permanently in `payment_references` (idempotency_keys is purged after 7 days, so it cannot be used for this).
 * Call claim() inside the same transaction as the ledger row.
 */
final class PaymentReferences
{
    public static function normalize(string $ref): string
    {
        $r = preg_replace('/[\p{C}\p{Zl}\p{Zp}]+/u', ' ', $ref) ?? '';
        $r = trim(preg_replace('/\s+/u', ' ', $r) ?? '');
        // upper-case first (a letter like the German sharp s grows), then cut to the column size, then drop a space the cut may leave at the end
        return rtrim(mb_substr(mb_strtoupper($r), 0, 80));
    }

    /**
     * @return string the normalised reference
     * @throws DomainError conflict when the reference was used before (in either path)
     */
    public static function claim(\PDO $pdo, string $ref, string $userId, string $source, string $nowDb): string
    {
        $norm = self::normalize($ref);
        if ($norm === '') {
            throw new DomainError('validation', 'The payment reference is empty.');
        }
        $chk = $pdo->prepare('SELECT 1 FROM pack_requests WHERE UPPER(payment_reference) = ?');
        $chk->execute([$norm]);
        if ($chk->fetchColumn() !== false) {
            throw new DomainError('conflict', 'That payment reference has already been used.');
        }
        try {
            $ins = $pdo->prepare('INSERT INTO payment_references (ref, user_id, source, created_at) VALUES (?,?,?,?)');
            $ins->execute([$norm, $userId, $source, $nowDb]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            throw new DomainError('conflict', 'That payment reference has already been used.');
        }
        return $norm;
    }
}
