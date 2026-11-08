<?php
declare(strict_types=1);

namespace StretchEffect\Support;

/** DB rows -> API shapes of web/src/domain/types.ts (camelCase, UTC ISO "…Z", null fields omitted). */
final class Presenter
{
    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function user(array $r): array
    {
        $u = [
            'id' => (string) $r['id'],
            'role' => (string) $r['role'],
            'name' => (string) $r['name'],
            'email' => (string) $r['email'],
        ];
        if (!empty($r['phone'])) {
            $u['phone'] = (string) $r['phone'];
        }
        $u['createdAt'] = Time::iso((string) $r['created_at']);
        if ($r['role'] === 'owner') {
            $u['totpEnabled'] = (bool) ($r['totp_enabled'] ?? false);
        }
        return $u;
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function ledger(array $r): array
    {
        $e = [
            'id' => (string) $r['id'],
            'userId' => (string) $r['user_id'],
            'delta' => (int) $r['delta'],
            'reason' => (string) $r['reason'],
        ];
        if ($r['note'] !== null && $r['note'] !== '') {
            $e['note'] = (string) $r['note'];
        }
        $e['actor'] = (string) $r['actor'];
        $e['createdAt'] = Time::iso((string) $r['created_at']);
        return $e;
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function package(array $r): array
    {
        $p = ['id' => (string) $r['id'], 'name' => (string) $r['name'], 'sessions' => (int) $r['sessions'], 'priceZar' => (int) $r['price_zar']];
        if ($r['note'] !== null && $r['note'] !== '') {
            $p['note'] = (string) $r['note'];
        }
        return $p;
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function pack(array $r): array
    {
        $p = [
            'id' => (string) $r['id'],
            'userId' => (string) $r['user_id'],
            'packageId' => (string) $r['package_id'],
            'sessions' => (int) $r['sessions'],
            'priceZar' => (int) $r['price_zar'],
            'status' => (string) $r['status'],
            'createdAt' => Time::iso((string) $r['created_at']),
        ];
        if ($r['paid_at'] !== null) {
            $p['paidAt'] = Time::iso((string) $r['paid_at']);
        }
        if ($r['payment_reference'] !== null) {
            $p['paymentReference'] = (string) $r['payment_reference'];
        }
        return $p;
    }

    /** @param array<string,mixed> $r row incl. client_name @return array<string,mixed> */
    public static function booking(array $r): array
    {
        $b = [
            'id' => (string) $r['id'],
            'userId' => (string) $r['user_id'],
            'clientName' => (string) ($r['client_name'] ?? ''),
            'start' => Time::iso((string) $r['start_at']),
            'end' => Time::iso((string) $r['end_at']),
            'status' => (string) $r['status'],
            'createdBy' => (string) $r['created_by'],
        ];
        if (!empty($r['google_event_id'])) {
            $b['googleEventId'] = (string) $r['google_event_id'];
        }
        if ($r['cancelled_at'] !== null) {
            $b['cancelledAt'] = Time::iso((string) $r['cancelled_at']);
        }
        if ($r['cancel_reason'] !== null && $r['cancel_reason'] !== '') {
            $b['cancelReason'] = (string) $r['cancel_reason'];
        }
        $b['refunded'] = (bool) $r['refunded'];
        return $b;
    }
}
