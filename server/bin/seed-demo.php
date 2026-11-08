<?php
declare(strict_types=1);

// Usage: php bin/seed-demo.php [--with-owner]   LOCAL TESTING ONLY. Synthetic @example.com data. Refuses on app.env=live.
require __DIR__ . '/_boot.php';

use StretchEffect\Services\AuthService;
use StretchEffect\Services\Totp;
use StretchEffect\Support\Time;

$app = se_app();
if ($app->config()->isLive()) {
    fwrite(STDERR, "Refusing to seed demo data when app.env is 'live'.\n");
    exit(1);
}
$db = $app->db();
$o = se_opts($argv);
$now = $db->now();
$ts = Time::toDb($now);
$hash = AuthService::hashPassword('demo-password-123');

$clients = [
    ['Thandi Demo', 'thandi@example.com', '+27 82 000 0001', 5],
    ['Pieter Demo', 'pieter@example.com', '+27 82 000 0002', 2],
    ['Aisha Demo', 'aisha@example.com', null, 0],
    ['Sam Demo', 'sam@example.com', '+27 82 000 0004', 10],
];
$ids = [];
foreach ($clients as [$name, $email, $phone, $credits]) {
    if ($db->val('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
        $ids[$email] = $db->val('SELECT id FROM users WHERE email = ?', [$email]);
        continue;
    }
    $id = $db->newId();
    $db->run("INSERT INTO users (id, role, name, email, phone, password_hash, totp_enabled, created_at) VALUES (?, 'client', ?, ?, ?, ?, 0, ?)", [$id, $name, $email, $phone, $hash, $ts]);
    if ($credits > 0) {
        $app->credits()->adjust($id, $credits, 'purchase', 'Demo pack', 'seed');
    }
    $ids[$email] = $id;
}

// A few bookings in the next days, using real availability.
$from = $now->setTimezone(Time::tz())->modify('+1 day')->format('Y-m-d');
$to = $now->setTimezone(Time::tz())->modify('+6 days')->format('Y-m-d');
$slots = $app->bookings()->availability($from, $to);
$made = 0;
foreach ([0, 5, 12, 20] as $i => $idx) {
    $email = array_keys($ids)[$i % 2 === 0 ? 0 : 1];
    if (isset($slots[$idx]) && $app->credits()->balance($ids[$email]) > 0) {
        try {
            $app->bookings()->create($ids[$email], $slots[$idx]['start'], 'client', $ids[$email]);
            $slots = $app->bookings()->availability($from, $to);
            $made++;
        } catch (Throwable $e) {
            echo 'skip booking: ' . $e->getMessage() . "\n";
        }
    }
}
// One pending pack request for the owner to confirm.
if ($db->val("SELECT 1 FROM pack_requests WHERE user_id = ? AND status = 'pending'", [$ids['aisha@example.com']]) === null) {
    $app->credits()->requestPack($ids['aisha@example.com'], 'five');
}
echo 'Seeded ' . count($ids) . " clients (password: demo-password-123) and $made bookings.\n";

if (isset($o['with-owner']) && $db->val("SELECT 1 FROM users WHERE role = 'owner'") === null) {
    $secret = Totp::generateSecret();
    $db->run("INSERT INTO users (id, role, name, email, password_hash, totp_secret_enc, totp_enabled, created_at) VALUES (?, 'owner', 'Demo Owner', 'owner@example.com', ?, ?, 1, ?)",
        [$db->newId(), AuthService::hashPassword('demo-owner-password'), $app->crypto()->encrypt($secret), $ts]);
    echo "Owner: owner@example.com / demo-owner-password\nTOTP secret: $secret\nURI: " . Totp::otpauthUri($secret, 'owner@example.com') . "\n";
}
