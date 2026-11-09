<?php
declare(strict_types=1);

namespace StretchEffect\Tests;

use PHPUnit\Framework\TestCase;
use StretchEffect\App;
use StretchEffect\Contracts\DomainError;
use StretchEffect\Services\PaymentReferences;
use StretchEffect\Services\Totp;
use StretchEffect\Support\Time;

/**
 * Written against mutation testing (see docs/TESTING.md): the real credit, booking, auth, TOTP, grant and
 * rate-limit services, with exact values at the rule boundaries (24 h refund, 12 h notice, 28-day window, TOTP +-1 step).
 */
final class MutationServicesTest extends TestCase
{
    private const START = '2026-11-10T08:00:00Z'; // Tuesday 10:00 SAST

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (DomainError $e) {
            return $e->errorCode . ': ' . $e->getMessage();
        }
        return 'none';
    }

    private function ts(App $app): string { return Time::toDb($app->db()->now()); }

    // ------------------------------------------------------------ PaymentReferences

    public function testNormalizeExactForms(): void
    {
        $this->assertSame('PAY 100', PaymentReferences::normalize('  pay  100 '));
        $this->assertSame('PAY-100', PaymentReferences::normalize("pay-100\t"));
        $this->assertSame('A B C', PaymentReferences::normalize("a\x00b\u{2028}c"));
        $this->assertSame('', PaymentReferences::normalize(" \n\t "));
        $this->assertSame('ÉCOLE', PaymentReferences::normalize('école'));
        $this->assertSame(str_repeat('A', 80), PaymentReferences::normalize(str_repeat('a', 80)));
        $this->assertSame(str_repeat('A', 80), PaymentReferences::normalize(str_repeat('a', 90)));
        $this->assertSame(str_repeat('É', 80), PaymentReferences::normalize(str_repeat('é', 100)), 'cut in characters, not bytes');
        $this->assertSame(str_repeat('A', 79), PaymentReferences::normalize(str_repeat('a', 79) . ' b'), 'a space left by the cut is dropped');
        $this->assertSame(str_repeat('SS', 40), PaymentReferences::normalize(str_repeat('ß', 60)), 'upper-case first (ß grows), then cut');
        $this->assertSame(PaymentReferences::normalize(PaymentReferences::normalize(' pay  100 ')), PaymentReferences::normalize(' pay  100 '));
    }

    public function testClaimRules(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app);
        $pdo = $app->db()->pdo();
        $this->assertSame('PAY 1', PaymentReferences::claim($pdo, ' pay  1 ', $c['id'], 'chatgpt', '2026-11-08 08:00:00'));
        $row = $pdo->query('SELECT * FROM payment_references')->fetch();
        $this->assertSame(['ref' => 'PAY 1', 'user_id' => $c['id'], 'source' => 'chatgpt', 'created_at' => '2026-11-08 08:00:00'], $row);
        foreach (['PAY 1', 'pay 1', ' Pay   1'] as $dup) {
            try {
                PaymentReferences::claim($pdo, $dup, $c['id'], 'admin', '2026-11-08 09:00:00');
                $this->fail('duplicate accepted: ' . $dup);
            } catch (DomainError $e) {
                $this->assertSame('conflict', $e->errorCode);
                $this->assertSame('That payment reference has already been used.', $e->getMessage());
            }
        }
        try {
            PaymentReferences::claim($pdo, " \t ", $c['id'], 'admin', '2026-11-08 09:00:00');
            $this->fail('empty accepted');
        } catch (DomainError $e) {
            $this->assertSame('validation', $e->errorCode);
            $this->assertSame('The payment reference is empty.', $e->getMessage());
        }
        // a reference already used by an owner-marked pack is also refused
        $app->credits()->requestPack($c['id'], 'single');
        $app->db()->run("UPDATE pack_requests SET payment_reference = 'eft-9'");
        $this->assertStringStartsWith('conflict: That payment reference has already been used.', $this->code(fn() => PaymentReferences::claim($pdo, 'EFT-9', $c['id'], 'chatgpt', '2026-11-08 09:00:00')));
        // any other database error is not disguised as "already used"
        $pdo->exec('DROP TABLE payment_references');
        $this->expectException(\PDOException::class);
        PaymentReferences::claim($pdo, 'new-ref', $c['id'], 'chatgpt', '2026-11-08 09:00:00');
    }

    // ------------------------------------------------------------ CreditService

    public function testAdjustValidationAndStorage(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app);
        $cr = $app->credits();
        $this->assertSame('validation: Credit change cannot be zero', $this->code(fn() => $cr->adjust($c['id'], 0, 'purchase', null, 'a')));
        $this->assertSame('validation: Unknown ledger reason', $this->code(fn() => $cr->adjust($c['id'], 1, 'gift', null, 'a')));
        $this->assertSame('not_found: Client not found', $this->code(fn() => $cr->adjust('ghost', 1, 'purchase', null, 'a')));
        $this->assertSame('no_credits: Not enough session credits', $this->code(fn() => $cr->adjust($c['id'], -1, 'owner_adjust', null, 'a')));
        $cr->adjust($c['id'], 2, 'purchase', null, 'a');
        $this->assertSame('no_credits: Not enough session credits', $this->code(fn() => $cr->adjust($c['id'], -3, 'owner_adjust', null, 'a')));
        $this->assertSame('none', $this->code(fn() => $cr->adjust($c['id'], -2, 'owner_adjust', null, 'a')), 'down to exactly zero is allowed');
        $this->assertSame(0, $cr->balance($c['id']));
        $this->assertSame('no_credits: Not enough session credits', $this->code(fn() => $cr->adjust($c['id'], -1, 'owner_adjust', null, 'a')));
        foreach (['purchase', 'booking', 'cancel_refund', 'owner_adjust', 'chatgpt_adjust', 'welcome_offer'] as $reason) {
            $cr->adjust($c['id'], 1, $reason, null, 'a');
        }
        $this->assertSame(6, $cr->balance($c['id']));
        $row = $cr->adjust($c['id'], 1, 'purchase', "  padded note \n", str_repeat('x', 70), 'b-1');
        $this->assertSame(['userId' => $c['id'], 'delta' => 1, 'reason' => 'purchase'], array_intersect_key($row, array_flip(['userId', 'delta', 'reason'])));
        $stored = $app->db()->one('SELECT * FROM credit_ledger WHERE id = ?', [$row['id']]);
        $this->assertSame('padded note', $stored['note']);
        $this->assertSame(str_repeat('x', 60), $stored['actor']);
        $this->assertSame('b-1', $stored['booking_id']);
        $this->assertSame('2026-11-08 08:00:00', $stored['created_at']);
        $n255 = $cr->adjust($c['id'], 1, 'purchase', str_repeat('n', 255), 'a');
        $this->assertSame(str_repeat('n', 255), $app->db()->val('SELECT note FROM credit_ledger WHERE id = ?', [$n255['id']]));
        $n300 = $cr->adjust($c['id'], 1, 'purchase', str_repeat('é', 300), 'a');
        $this->assertSame(str_repeat('é', 255), $app->db()->val('SELECT note FROM credit_ledger WHERE id = ?', [$n300['id']]));
        $blank = $cr->adjust($c['id'], 1, 'purchase', "  \n ", 'a');
        $this->assertNull($app->db()->val('SELECT note FROM credit_ledger WHERE id = ?', [$blank['id']]));
        $none = $cr->adjust($c['id'], 1, 'purchase', null, 'a');
        $this->assertNull($app->db()->val('SELECT note FROM credit_ledger WHERE id = ?', [$none['id']]));
        $this->assertNull($app->db()->val('SELECT booking_id FROM credit_ledger WHERE id = ?', [$none['id']]));
    }

    public function testChatgptDailyTotalFollowsLocalMidnight(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app);
        $cr = $app->credits();
        $at = function (string $utc, int $delta, string $reason = 'chatgpt_adjust') use ($app, $cr, $c) {
            $app->db()->freeze($utc);
            $cr->adjust($c['id'], $delta, $reason, null, 'chatgpt:g');
        };
        $at('2026-11-08T21:59:59Z', 5);           // 23:59:59 on the 8th, local
        $at('2026-11-08T22:00:00Z', 7);           // 00:00:00 on the 9th, local
        $at('2026-11-09T10:00:00Z', 2, 'purchase'); // other reasons never count
        $at('2026-11-09T11:00:00Z', 4);
        $at('2026-11-09T12:00:00Z', -3);          // removals are not "added"
        $app->db()->freeze('2026-11-09T12:30:00Z');
        $this->assertSame(11, $cr->chatgptAddedToday());
        $app->db()->freeze('2026-11-09T21:59:59Z');
        $this->assertSame(11, $cr->chatgptAddedToday(), 'still the 9th at 23:59:59 local');
        $app->db()->freeze('2026-11-09T22:00:00Z');
        $this->assertSame(0, $cr->chatgptAddedToday(), 'a new local day starts at 22:00 UTC');
        $at('2026-11-09T22:00:00Z', 6);
        $this->assertSame(6, $cr->chatgptAddedToday());
        $app->db()->freeze('2026-11-08T21:59:59Z');
        $this->assertSame(5, $cr->chatgptAddedToday(), 'the 8th only has the first entry');
        $app->db()->freeze('2026-11-08T22:00:00Z');
        $this->assertSame(11, $cr->chatgptAddedToday());
    }

    public function testMarkPackPaidFlow(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app);
        $cr = $app->credits();
        $this->assertSame('not_found: Pack request not found', $this->code(fn() => $cr->markPackPaid('ghost', null, 'owner')));
        $pack = $cr->requestPack($c['id'], 'single');
        $paid = $cr->markPackPaid($pack['id'], '  eft  77 ', 'owner-1');
        $this->assertSame('paid', $paid['status']);
        $this->assertSame(1, $cr->balance($c['id']));
        $row = $app->db()->one('SELECT * FROM pack_requests WHERE id = ?', [$pack['id']]);
        $this->assertSame('EFT 77', $row['payment_reference']);
        $this->assertSame('2026-11-08 08:00:00', $row['paid_at']);
        $l = $app->db()->one("SELECT * FROM credit_ledger WHERE reason = 'purchase'");
        $this->assertSame('Pack: single (ref EFT 77)', $l['note']);
        $this->assertSame('owner-1', $l['actor']);
        $this->assertSame('conflict: This pack request is already paid', $this->code(fn() => $cr->markPackPaid($pack['id'], null, 'owner-1')));
        $p2 = $cr->requestPack($c['id'], 'single');
        $this->assertSame('conflict: That payment reference has already been used.', $this->code(fn() => $cr->markPackPaid($p2['id'], 'EFT 77', 'owner-1')));
        $this->assertSame(1, $cr->balance($c['id']), 'a refused reference credits nothing');
        $cr->markPackPaid($p2['id'], '   ', 'owner-1');
        $l2 = $app->db()->one("SELECT note FROM credit_ledger WHERE reason = 'purchase' ORDER BY rowid DESC LIMIT 1");
        $this->assertSame('Pack: single', $l2['note']);
        $this->assertNull($app->db()->val('SELECT payment_reference FROM pack_requests WHERE id = ?', [$p2['id']]));
        $this->assertSame(2, $cr->balance($c['id']));
    }

    public function testRequestPackLimits(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app);
        $cr = $app->credits();
        $this->assertSame('not_found: Package not found', $this->code(fn() => $cr->requestPack($c['id'], 'nope')));
        for ($i = 1; $i <= 5; $i++) {
            $p = $cr->requestPack($c['id'], 'single');
            $this->assertSame('pending', $p['status']);
        }
        $this->assertSame('conflict: You already have several pending pack requests. Please wait for them to be confirmed.', $this->code(fn() => $cr->requestPack($c['id'], 'single')));
        $other = CoreTestKit::client($app, 'other@example.com');
        $this->assertSame('none', $this->code(fn() => $cr->requestPack($other['id'], 'single')), 'the limit is per client');
        $cr->markPackPaid($p['id'], null, 'owner');
        $this->assertSame('none', $this->code(fn() => $cr->requestPack($c['id'], 'single')), 'paid requests do not count as pending');
        $this->assertCount(6, $cr->packs($c['id']));
        $this->assertSame(['id', 'userId', 'packageId', 'sessions', 'priceZar', 'status', 'createdAt'], array_slice(array_keys($cr->packs($c['id'])[0]), 0, 7));
        $this->assertNotEmpty($cr->packages());
    }

    public function testLedgerOrderingAndLimits(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app);
        $pdo = $app->db()->pdo();
        $ins = $pdo->prepare("INSERT INTO credit_ledger (id,user_id,delta,reason,note,actor,created_at) VALUES (?,?,?,?,?,?,?)");
        $pdo->beginTransaction();
        for ($i = 0; $i < 1005; $i++) {
            $ins->execute([sprintf('l%05d', $i), $c['id'], 1, 'purchase', null, 'a', gmdate('Y-m-d H:i:s', 1_700_000_000 + $i)]);
        }
        $pdo->commit();
        $cr = $app->credits();
        $this->assertCount(500, $cr->ledger($c['id']));
        $this->assertCount(1000, $cr->ledger($c['id'], 5000));
        $this->assertCount(1000, $cr->ledger($c['id'], 1000));
        $this->assertCount(999, $cr->ledger($c['id'], 999));
        $this->assertCount(1, $cr->ledger($c['id'], 0));
        $this->assertCount(1, $cr->ledger($c['id'], -5));
        $this->assertCount(2, $cr->ledger($c['id'], 2));
        $this->assertSame(['l01004', 'l01003'], array_column($cr->ledger($c['id'], 2), 'id'), 'newest first');
        $this->assertSame(1, $cr->ledger($c['id'], 1)[0]['delta']);
        $this->assertSame([], $cr->ledger('nobody'));
    }

    // ------------------------------------------------------------ RateLimiter

    public function testRateLimiterBoundariesAndWindows(): void
    {
        $app = CoreTestKit::app();
        $rl = $app->rateLimiter();
        foreach ([1, 2, 5] as $limit) {
            for ($i = 1; $i <= $limit; $i++) {
                $this->assertTrue($rl->hit('b', "s$limit", $limit, 60), "hit $i of $limit");
            }
            $this->assertFalse($rl->hit('b', "s$limit", $limit, 60), "hit " . ($limit + 1));
            $this->assertFalse($rl->hit('b', "s$limit", $limit, 60));
        }
        $this->assertSame(1, (int) $app->db()->val("SELECT hits FROM rate_limits WHERE bucket LIKE 'b:s1:%'"));
        $this->assertSame(5, (int) $app->db()->val("SELECT hits FROM rate_limits WHERE bucket LIKE 'b:s5:%'"));
        $ts = $app->db()->now()->getTimestamp();
        $w = intdiv($ts, 60) * 60;
        $this->assertSame(gmdate('Y-m-d H:i:s', $w + 60), $app->db()->val("SELECT expires_at FROM rate_limits WHERE bucket = 'b:s5:$w'"));
        // names and subjects are separate budgets
        $this->assertTrue($rl->hit('other', 's1', 1, 60));
        $this->assertTrue($rl->hit('b', 'fresh', 1, 60));
        // degenerate settings never allow anything
        $this->assertFalse($rl->hit('z', 's', 0, 60));
        $this->assertFalse($rl->hit('z', 's', -1, 60));
        $this->assertFalse($rl->hit('z', 's', 5, 0));
        $this->assertFalse($rl->hit('z', 's', 5, -60));
        $this->assertFalse($rl->hit('z', 's', 0, 0));
        $this->assertSame(0, (int) $app->db()->val("SELECT COUNT(*) FROM rate_limits WHERE bucket LIKE 'z:%'"));
        $this->assertTrue($rl->hit('z', 's', 1, 1));
        // a new window starts at the window boundary: freeze on a boundary and step across it
        $app->db()->freeze('2026-11-08T08:00:00Z');
        $this->assertTrue($rl->hit('edge', 's', 1, 60));
        $app->db()->freeze('2026-11-08T08:00:59Z');
        $this->assertFalse($rl->hit('edge', 's', 1, 60), 'same window until the 60 s mark');
        $app->db()->freeze('2026-11-08T08:01:00Z');
        $this->assertTrue($rl->hit('edge', 's', 1, 60), 'next window');
        $this->assertFalse($rl->hit('edge', 's', 1, 60));
        // windows of other lengths
        $app->db()->freeze('2026-11-08T08:00:00Z');
        $this->assertTrue($rl->hit('hour', 's', 1, 3600));
        $app->db()->freeze('2026-11-08T08:59:59Z');
        $this->assertFalse($rl->hit('hour', 's', 1, 3600));
        $app->db()->freeze('2026-11-08T09:00:00Z');
        $this->assertTrue($rl->hit('hour', 's', 1, 3600));
    }

    public function testRateLimiterBucketNamingAndLongSubjects(): void
    {
        $app = CoreTestKit::app();
        $app->db()->freeze('2026-11-08T08:00:00Z');
        $rl = $app->rateLimiter();
        $w = intdiv($app->db()->now()->getTimestamp(), 60) * 60;
        $rl->hit('nm', 'subject', 5, 60);
        $this->assertSame(1, (int) $app->db()->val("SELECT hits FROM rate_limits WHERE bucket = 'nm:subject:$w'"));
        // bucket length 190 is stored as is, 191 is hashed
        $fixed = strlen("nm::$w");
        $s190 = str_repeat('s', 190 - $fixed);
        $rl->hit('nm', $s190, 5, 60);
        $this->assertSame(1, (int) $app->db()->val('SELECT COUNT(*) FROM rate_limits WHERE bucket = ?', ["nm:$s190:$w"]));
        $s191 = str_repeat('s', 191 - $fixed);
        $rl->hit('nm', $s191, 5, 60);
        $this->assertSame(0, (int) $app->db()->val('SELECT COUNT(*) FROM rate_limits WHERE bucket = ?', ["nm:$s191:$w"]));
        $this->assertSame(1, (int) $app->db()->val('SELECT COUNT(*) FROM rate_limits WHERE bucket = ?', ['nm:' . hash('sha256', $s191) . ":$w"]));
        $this->assertTrue($rl->hit('nm', $s191, 2, 60));
        $this->assertFalse($rl->hit('nm', $s191, 2, 60), 'a long subject is limited like any other');
    }

    public function testRateLimiterResetAndCleanup(): void
    {
        $app = CoreTestKit::app();
        $rl = $app->rateLimiter();
        $rl->hit('login', 'a@x', 1, 900);
        $rl->hit('login', 'b@x', 1, 900);
        $rl->hit('other', 'a@x', 1, 900);
        $rl->reset('login', 'a@x');
        $this->assertTrue($rl->hit('login', 'a@x', 1, 900), 'reset frees the subject');
        $this->assertFalse($rl->hit('login', 'b@x', 1, 900), 'other subjects keep their count');
        $this->assertFalse($rl->hit('other', 'a@x', 1, 900), 'other names keep their count');
        // expired rows are purged on a random 1 in 100 of the hits
        $app->db()->run("INSERT INTO rate_limits (bucket, hits, expires_at) VALUES ('old:x:1', 3, '2020-01-01 00:00:00')");
        $app->db()->run("INSERT INTO rate_limits (bucket, hits, expires_at) VALUES ('fresh:x:1', 3, '2099-01-01 00:00:00')");
        for ($i = 0; $i < 1500 && $app->db()->val("SELECT 1 FROM rate_limits WHERE bucket = 'old:x:1'") !== null; $i++) {
            $rl->hit('spin', "s$i", 5, 60);
        }
        $this->assertNull($app->db()->val("SELECT 1 FROM rate_limits WHERE bucket = 'old:x:1'"), 'expired rows are eventually removed');
        $this->assertNotNull($app->db()->val("SELECT 1 FROM rate_limits WHERE bucket = 'fresh:x:1'"), 'live rows are kept');
    }

    // ------------------------------------------------------------ Totp

    public function testTotpRfc6238Vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037'] as $t => $code) {
            $this->assertSame($code, Totp::code($secret, Totp::step($t)), "T=$t");
        }
        $this->assertSame(1, Totp::step(59));
        $this->assertSame(2, Totp::step(60));
        $this->assertSame(0, Totp::step(29));
    }

    public function testTotpMatchWindowAndFormat(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $now = 1111111111;
        $step = Totp::step($now);
        $this->assertSame($step, Totp::match($secret, Totp::code($secret, $step), $now));
        $this->assertSame($step - 1, Totp::match($secret, Totp::code($secret, $step - 1), $now));
        $this->assertSame($step + 1, Totp::match($secret, Totp::code($secret, $step + 1), $now));
        $this->assertNull(Totp::match($secret, Totp::code($secret, $step - 2), $now));
        $this->assertNull(Totp::match($secret, Totp::code($secret, $step + 2), $now));
        $this->assertSame($step + 2, Totp::match($secret, Totp::code($secret, $step + 2), $now, 2));
        $this->assertSame($step - 2, Totp::match($secret, Totp::code($secret, $step - 2), $now, 2));
        $this->assertNull(Totp::match($secret, Totp::code($secret, $step + 1), $now, 0));
        $this->assertSame($step, Totp::match($secret, Totp::code($secret, $step), $now, 0));
        $good = Totp::code($secret, $step);
        foreach ([$good . "\n", "\n" . $good, ' ' . $good, $good . '0', substr($good, 1), '', 'abcdef', substr($good, 0, 5) . 'x'] as $bad) {
            $this->assertNull(Totp::match($secret, $bad, $now), json_encode($bad));
        }
    }

    public function testBase32AndSecrets(): void
    {
        $this->assertSame('MZXW6YTBOI', Totp::base32Encode('foobar'));
        $this->assertSame('MZXW6', Totp::base32Encode('foo'));
        $this->assertSame('', Totp::base32Encode(''));
        $this->assertSame('foobar', Totp::base32Decode('MZXW6YTBOI'));
        $this->assertSame('foobar', Totp::base32Decode('mzxw 6ytb-oi=='));
        $this->assertSame('foobar', Totp::base32Decode("MZXW\n6YTB\tOI"));
        $this->assertSame('', Totp::base32Decode('MZXW6!YTBOI'));
        $this->assertSame('', Totp::base32Decode('0189'));
        $bin = random_bytes(20);
        $this->assertSame($bin, Totp::base32Decode(Totp::base32Encode($bin)));
        $this->assertSame(32, strlen(Totp::generateSecret()));
        $this->assertSame(32, strlen(Totp::generateSecret(20)));
        $this->assertSame(16, strlen(Totp::generateSecret(10)));
        $this->assertSame(Totp::base32Encode("\x00\x00\x00\x00\x01"), 'AAAAAAAB', 'the last 5-bit group is padded on the right');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/D', Totp::generateSecret());
        $this->assertSame('otpauth://totp/The%20Stretch%20Effect%3Aivan%40example.com?secret=ABC&issuer=The%20Stretch%20Effect&algorithm=SHA1&digits=6&period=30',
            Totp::otpauthUri('ABC', 'ivan@example.com'));
        $this->assertSame('otpauth://totp/Acme%3Aa?secret=S&issuer=Acme&algorithm=SHA1&digits=6&period=30', Totp::otpauthUri('S', 'a', 'Acme'));
    }

    // ------------------------------------------------------------ AuthService

    public function testPasswordLoginThrottles(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app, 'pw@example.com', 0, 'Pw', 'right-password-1');
        $auth = $app->auth();
        $ip = '10.1.1.1';
        $this->assertNull($auth->verifyPassword('pw@example.com', 'wrong', $ip));
        $this->assertNull($auth->verifyPassword('nobody@example.com', 'wrong', $ip));
        $u = $auth->verifyPassword('  PW@example.com ', 'right-password-1', $ip);
        $this->assertSame($c['id'], $u['id']);
        $this->assertArrayNotHasKey('password_hash', $u);
        $this->assertSame(['id', 'role', 'name', 'email', 'phone', 'totp_enabled', 'created_at'], array_keys($u));
        // 5 attempts per address + IP in 15 minutes (a success resets that counter)
        for ($i = 1; $i <= 5; $i++) {
            $this->assertNull($auth->verifyPassword('pw@example.com', 'wrong', '10.2.2.2'), "attempt $i");
        }
        $this->assertSame('rate_limited: Too many sign-in attempts. Please wait a few minutes and try again.', $this->code(fn() => $auth->verifyPassword('pw@example.com', 'right-password-1', '10.2.2.2')));
        $app->db()->freeze('2026-11-08T08:14:59Z');
        $this->assertStringStartsWith('rate_limited', $this->code(fn() => $auth->verifyPassword('pw@example.com', 'right-password-1', '10.2.2.2')));
        $app->db()->freeze('2026-11-08T08:15:00Z');
        $this->assertNotNull($auth->verifyPassword('pw@example.com', 'right-password-1', '10.2.2.2'), 'a new 15-minute window');
        // success resets the per-pair counter
        for ($round = 0; $round < 3; $round++) {
            for ($i = 0; $i < 4; $i++) {
                $this->assertNull($auth->verifyPassword('pw@example.com', 'wrong', '10.3.3.3'));
            }
            $this->assertNotNull($auth->verifyPassword('pw@example.com', 'right-password-1', '10.3.3.3'));
        }
    }

    public function testPerIpAndPerEmailLimits(): void
    {
        $app = CoreTestKit::app();
        CoreTestKit::client($app, 'pw@example.com', 0, 'Pw', 'right-password-1');
        $auth = $app->auth();
        // 20 per IP
        for ($i = 1; $i <= 20; $i++) {
            $this->assertNull($auth->verifyPassword("u$i@example.com", 'x', '10.9.9.9'), "ip attempt $i");
        }
        $this->assertStringStartsWith('rate_limited', $this->code(fn() => $auth->verifyPassword('u21@example.com', 'x', '10.9.9.9')));
        // 30 per email across addresses
        for ($i = 1; $i <= 30; $i++) {
            $this->assertNull($auth->verifyPassword('victim@example.com', 'x', "10.8.8.$i"), "email attempt $i");
        }
        $this->assertStringStartsWith('rate_limited', $this->code(fn() => $auth->verifyPassword('victim@example.com', 'x', '10.8.8.200')));
        $this->assertNotNull($auth->verifyPassword('pw@example.com', 'right-password-1', '10.8.8.201'), 'other emails are unaffected');
    }

    public function testOldHashesAreUpgradedOnLogin(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app, 'old@example.com', 0, 'Old', 'legacy-password-1');
        $app->db()->run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash('legacy-password-1', PASSWORD_BCRYPT, ['cost' => 4]), $c['id']]);
        $this->assertNotNull($app->auth()->verifyPassword('old@example.com', 'legacy-password-1', '10.4.4.4'));
        $hash = (string) $app->db()->val('SELECT password_hash FROM users WHERE id = ?', [$c['id']]);
        if (defined('PASSWORD_ARGON2ID')) {
            $this->assertStringStartsWith('$argon2id$', $hash);
        }
        $this->assertTrue(password_verify('legacy-password-1', $hash));
        $this->assertNull($app->auth()->verifyPassword('old@example.com', 'legacy-password-2', '10.4.4.4'));
        $this->assertSame($hash, (string) $app->db()->val('SELECT password_hash FROM users WHERE id = ?', [$c['id']]), 'a failed login never rewrites the hash');
    }

    public function testTotpVerificationWindowAndReuse(): void
    {
        $app = CoreTestKit::app();
        $o = CoreTestKit::owner($app);
        $auth = $app->auth();
        $t = $app->db()->now()->getTimestamp();
        $step = Totp::step($t);
        $code = fn(int $s) => Totp::code($o['secret'], $s);
        $this->assertFalse($auth->verifyTotp($o['id'], $code($step - 2), '10.5.0.2'), 'two steps back');
        $this->assertFalse($auth->verifyTotp($o['id'], $code($step + 2), '10.5.0.3'), 'two steps ahead');
        $this->assertFalse($auth->verifyTotp($o['id'], '000000', '10.5.0.4'));
        $this->assertFalse($auth->verifyTotp($o['id'], substr($code($step), 0, 5), '10.5.0.5'));
        $this->assertTrue($auth->verifyTotp($o['id'], ' ' . $code($step) . " \n", '10.5.0.6'), 'surrounding whitespace is trimmed');
        $this->assertSame($step, (int) $app->db()->val('SELECT totp_last_step FROM users WHERE id = ?', [$o['id']]));
        $this->assertFalse($auth->verifyTotp($o['id'], $code($step), '10.5.0.7'), 'the same step cannot be used twice');
        $this->assertFalse($auth->verifyTotp($o['id'], $code($step - 1), '10.5.0.8'), 'an older step than the last used is a replay');
        $this->assertTrue($auth->verifyTotp($o['id'], $code($step + 1), '10.5.0.9'), 'the next step is fine (clock drift +1)');
        $this->assertFalse($auth->verifyTotp($o['id'], $code($step + 1), '10.5.0.10'));
        // drift of one step behind works for a fresh user
        $o2 = CoreTestKit::owner($app, 'o2@example.com');
        $this->assertTrue($auth->verifyTotp($o2['id'], Totp::code($o2['secret'], $step - 1), '10.5.0.11'));
        // unknown or not enrolled
        $this->assertFalse($auth->verifyTotp('ghost', '123456', '10.5.0.12'));
        $c = CoreTestKit::client($app);
        $this->assertFalse($auth->verifyTotp($c['id'], '123456', '10.5.0.13'));
        $app->db()->run('UPDATE users SET totp_enabled = 0 WHERE id = ?', [$o2['id']]);
        $this->assertFalse($auth->verifyTotp($o2['id'], Totp::code($o2['secret'], $step + 1), '10.5.0.14'));
        $app->db()->run("UPDATE users SET totp_enabled = 1, totp_secret_enc = '' WHERE id = ?", [$o2['id']]);
        $this->assertFalse($auth->verifyTotp($o2['id'], '123456', '10.5.0.15'));
    }

    public function testTotpThrottles(): void
    {
        $app = CoreTestKit::app();
        $o = CoreTestKit::owner($app);
        $auth = $app->auth();
        for ($i = 1; $i <= 8; $i++) {
            $this->assertFalse($auth->verifyTotp($o['id'], '000000', '10.6.6.6'), "attempt $i");
        }
        $this->assertSame('rate_limited: Too many attempts. Please wait a few minutes and try again.', $this->code(fn() => $auth->verifyTotp($o['id'], '000000', '10.6.6.6')));
        $this->assertFalse($auth->verifyTotp($o['id'], '000000', '10.6.6.7'), 'another address has its own per-IP budget');
        // the per-user budget is 30: 8 (above) + 1 + 21 more from fresh addresses
        for ($i = 0; $i < 21; $i++) {
            $this->assertFalse($auth->verifyTotp($o['id'], '000000', "10.7.7.$i"), "user attempt $i");
        }
        $this->assertStringStartsWith('rate_limited', $this->code(fn() => $auth->verifyTotp($o['id'], '000000', '10.7.7.99')));
        $app->db()->freeze('2026-11-08T08:15:00Z');
        $this->assertFalse($auth->verifyTotp($o['id'], '000000', '10.6.6.6'), 'the window has passed');
        // an empty IP is its own bucket
        $this->assertFalse($auth->verifyTotp($o['id'], '000000'));
    }

    public function testTotpSecondFactorSessionLifecycle(): void
    {
        $app = CoreTestKit::app();
        $o = CoreTestKit::owner($app);
        $auth = $app->auth();
        $codeNow = fn() => Totp::code($o['secret'], Totp::step($app->db()->now()->getTimestamp()));
        $this->assertSame('unauthorized: Sign in with your password first.', $this->code(fn() => $auth->completeTotp('123456')));
        $auth->beginTotp($o['id']);
        $app->db()->freeze('2026-11-08T08:10:00Z'); // exactly 600 s later
        $u = $auth->completeTotp($codeNow());
        $this->assertSame($o['id'], $u['id'], 'exactly 600 s is still allowed');
        $this->assertSame($o['id'], $auth->currentUser()['id']);
        $auth->logout();
        $this->assertNull($auth->currentUser());
        $app->db()->freeze('2026-11-08T08:20:00Z');
        $auth->beginTotp($o['id']);
        $app->db()->freeze('2026-11-08T08:30:01Z'); // 601 s
        $this->assertSame('unauthorized: Sign in with your password first.', $this->code(fn() => $auth->completeTotp($codeNow())));
        $this->assertNull($auth->session()->get('pending_totp'));
        // five wrong codes kill the pending login; four do not
        $app->db()->freeze('2026-11-08T09:00:00Z');
        $auth->beginTotp($o['id']);
        for ($i = 1; $i <= 4; $i++) {
            $this->assertNull($auth->completeTotp('000000', "10.1.0.$i"));
            $this->assertNotNull($auth->session()->get('pending_totp'), "after $i failures");
        }
        $this->assertNull($auth->completeTotp('000000', '10.1.0.5'));
        $this->assertNull($auth->session()->get('pending_totp'));
        $this->assertSame('unauthorized: Sign in with your password first.', $this->code(fn() => $auth->completeTotp($codeNow())));
        $this->assertSame(5, $auth->session()->get('totp_failures'));
        // a successful login clears the failure counter and the pending state
        $auth->beginTotp($o['id']);
        $app->db()->freeze('2026-11-08T09:01:00Z');
        $this->assertNotNull($auth->completeTotp($codeNow()));
        $this->assertNull($auth->session()->get('pending_totp'));
        $this->assertNull($auth->session()->get('totp_failures'));
    }

    public function testCsrfTokenBehaviour(): void
    {
        $app = CoreTestKit::app();
        $auth = $app->auth();
        $t = $auth->csrfToken();
        $this->assertSame(43, strlen($t));
        $this->assertSame($t, $auth->csrfToken(), 'stable within a session');
        $this->assertTrue($auth->checkCsrf($t));
        foreach ([null, '', 'x', $t . 'x', substr($t, 1)] as $bad) {
            $this->assertFalse($auth->checkCsrf($bad), json_encode($bad));
        }
        $fresh = CoreTestKit::app();
        $this->assertFalse($fresh->auth()->checkCsrf(''), 'no token issued yet');
        $this->assertFalse($fresh->auth()->checkCsrf('anything'));
        $this->assertNull($fresh->auth()->currentUser());
        $fresh->auth()->session()->set('uid', 'ghost');
        $this->assertNull($fresh->auth()->currentUser());
        $this->assertNull($fresh->auth()->session()->get('uid'), 'a stale session user id is dropped');
    }

    // ------------------------------------------------------------ GrantService

    private function grant(App $app, string $id, string $userId, string $created, array $over = []): void
    {
        $row = $over + ['id' => $id, 'client_id' => 'c-' . $id, 'user_id' => $userId, 'scopes' => 'bookings:read credits:write', 'resource' => 'https://studio.test/mcp',
            'created_at' => $created, 'last_used_at' => null, 'revoked_at' => null, 'revoke_reason' => null];
        $cols = ['id', 'client_id', 'user_id', 'scopes', 'resource', 'created_at', 'last_used_at', 'revoked_at', 'revoke_reason'];
        $app->db()->run('INSERT INTO oauth_grants (' . implode(',', $cols) . ') VALUES (?,?,?,?,?,?,?,?,?)', array_map(fn($c) => $row[$c], $cols));
        $app->db()->run("INSERT INTO oauth_tokens (token_hash, grant_id, kind, expires_at, created_at) VALUES (?,?,'access','2099-01-01 00:00:00',?)", [hash('sha256', 'a' . $id), $id, $created]);
        $app->db()->run("INSERT INTO oauth_tokens (token_hash, grant_id, kind, expires_at, created_at) VALUES (?,?,'refresh','2099-01-01 00:00:00',?)", [hash('sha256', 'r' . $id), $id, $created]);
    }

    public function testGrantListShape(): void
    {
        $app = CoreTestKit::app();
        $o = CoreTestKit::owner($app);
        $app->db()->run("INSERT INTO oauth_clients (client_id, client_name, redirect_uris, registration, created_at) VALUES ('c-g1', 'Named App', '[]', 'dcr', '2026-11-01 00:00:00')");
        $this->grant($app, 'g1', $o['id'], '2026-11-01 08:00:00', ['last_used_at' => '2026-11-02 09:00:00', 'scopes' => 'bookings:read,clients:read  credits:write']);
        $this->grant($app, 'g2', $o['id'], '2026-11-03 08:00:00', ['revoked_at' => '2026-11-04 10:00:00', 'scopes' => '']);
        $list = $app->grants()->list();
        $this->assertSame(['g2', 'g1'], array_column($list, 'id'), 'newest first');
        $this->assertSame(['id' => 'g1', 'clientName' => 'Named App', 'scopes' => ['bookings:read', 'clients:read', 'credits:write'],
            'createdAt' => '2026-11-01T08:00:00Z', 'lastUsedAt' => '2026-11-02T09:00:00Z'], $list[1]);
        $this->assertSame(['id' => 'g2', 'clientName' => 'ChatGPT', 'scopes' => [], 'createdAt' => '2026-11-03T08:00:00Z', 'revokedAt' => '2026-11-04T10:00:00Z'], $list[0]);
    }

    public function testGrantRevocation(): void
    {
        $app = CoreTestKit::app();
        $o = CoreTestKit::owner($app);
        $o2 = CoreTestKit::owner($app, 'second@example.com');
        $this->grant($app, 'g1', $o['id'], '2026-11-01 08:00:00');
        $this->grant($app, 'g2', $o['id'], '2026-11-01 09:00:00');
        $this->grant($app, 'g3', $o2['id'], '2026-11-01 10:00:00');
        $gs = $app->grants();
        $this->assertSame('not_found: Grant not found', $this->code(fn() => $gs->revoke('ghost')));
        $gs->revoke('g1', str_repeat('r', 100));
        $g = $app->db()->one("SELECT * FROM oauth_grants WHERE id = 'g1'");
        $this->assertSame('2026-11-08 08:00:00', $g['revoked_at']);
        $this->assertSame(str_repeat('r', 80), $g['revoke_reason']);
        $this->assertSame(2, (int) $app->db()->val("SELECT COUNT(*) FROM oauth_tokens WHERE grant_id = 'g1' AND revoked_at = '2026-11-08 08:00:00'"));
        $this->assertSame(0, (int) $app->db()->val("SELECT COUNT(*) FROM oauth_tokens WHERE grant_id <> 'g1' AND revoked_at IS NOT NULL"));
        $app->db()->freeze('2026-11-09T08:00:00Z');
        $gs->revoke('g1', 'again');
        $g = $app->db()->one("SELECT * FROM oauth_grants WHERE id = 'g1'");
        $this->assertSame('2026-11-08 08:00:00', $g['revoked_at'], 'the first revocation time is kept');
        $this->assertSame(str_repeat('r', 80), $g['revoke_reason']);
        $gs->revoke('g2');
        $this->assertSame('owner', $app->db()->val("SELECT revoke_reason FROM oauth_grants WHERE id = 'g2'"));
        // revoke everything for one user
        $this->grant($app, 'g4', $o['id'], '2026-11-02 08:00:00');
        $n = $gs->revokeAllForUser($o['id'], str_repeat('c', 100));
        $this->assertSame(1, $n, 'only the still-active grant counts');
        $this->assertSame(str_repeat('c', 80), $app->db()->val("SELECT revoke_reason FROM oauth_grants WHERE id = 'g4'"));
        $this->assertSame('owner', $app->db()->val("SELECT revoke_reason FROM oauth_grants WHERE id = 'g2'"), 'already revoked grants are untouched');
        $this->assertNull($app->db()->val("SELECT revoked_at FROM oauth_grants WHERE id = 'g3'"));
        $this->assertSame(2, (int) $app->db()->val("SELECT COUNT(*) FROM oauth_tokens WHERE grant_id = 'g4' AND revoked_at IS NOT NULL"));
        $this->assertSame(0, (int) $app->db()->val("SELECT COUNT(*) FROM oauth_tokens WHERE grant_id = 'g3' AND revoked_at IS NOT NULL"));
        $this->assertSame(1, $gs->revokeAllForUser($o2['id']));
        $this->assertSame('credentials_changed', $app->db()->val("SELECT revoke_reason FROM oauth_grants WHERE id = 'g3'"));
        $this->assertSame(0, $gs->revokeAllForUser($o2['id']));
        $this->assertSame(0, $gs->revokeAllForUser('nobody'));
    }

    // ------------------------------------------------------------ BookingService: availability

    public function testSlotGridHoursAndBuffer(): void
    {
        $app = CoreTestKit::app([], ['now' => '2026-11-01T00:00:00Z']);
        $b = $app->bookings();
        $tue = array_column($b->availability('2026-11-10', '2026-11-10'), 'start');
        $this->assertCount(25, $tue, '06:00 to 18:00 local in 30 minute steps');
        $this->assertSame('2026-11-10T04:00:00Z', $tue[0]);
        $this->assertSame('2026-11-10T16:00:00Z', end($tue), 'the last session starts at 18:00 so it ends at 19:00');
        $this->assertSame('2026-11-10T05:00:00Z', $b->availability('2026-11-10', '2026-11-10')[0]['end']);
        $sat = array_column($b->availability('2026-11-14', '2026-11-14'), 'start');
        $this->assertCount(9, $sat);
        $this->assertSame('2026-11-14T05:00:00Z', $sat[0]);
        $this->assertSame('2026-11-14T09:00:00Z', end($sat));
        $this->assertSame([], $b->availability('2026-11-15', '2026-11-15'), 'Sunday is closed');
        $this->assertSame([], $b->availability('2026-11-11', '2026-11-10'), 'reversed range');
        $this->assertCount(25 + 25 + 9, $b->availability('2026-11-10', '2026-11-14') ? array_merge($b->availability('2026-11-10', '2026-11-11'), $b->availability('2026-11-14', '2026-11-14')) : []);
        $this->assertCount(25 * 4 + 9, $b->availability('2026-11-10', '2026-11-15'));
        $this->assertSame('validation: Dates must be YYYY-MM-DD', $this->code(fn() => $b->availability('nope', '2026-11-10')));
        $this->assertSame('validation: Dates must be YYYY-MM-DD', $this->code(fn() => $b->availability('2026-11-10', '2026-13-10')));
        // buffer: a booking 08:00-09:00Z blocks starts that would touch [08:00, 09:15)
        $c = CoreTestKit::client($app, credits: 3);
        $b->create($c['id'], self::START, 'client', $c['id']);
        $free = array_column($b->availability('2026-11-10', '2026-11-10'), 'start');
        $this->assertContains('2026-11-10T06:30:00Z', $free, 'a session ending 07:30 + 15 min buffer fits before 08:00');
        $this->assertNotContains('2026-11-10T07:00:00Z', $free, '07:00 would end at 08:00 and leave no buffer');
        $this->assertNotContains('2026-11-10T07:30:00Z', $free);
        $this->assertNotContains('2026-11-10T08:00:00Z', $free);
        $this->assertNotContains('2026-11-10T09:00:00Z', $free, 'inside the 15 minute buffer after');
        $this->assertContains('2026-11-10T09:30:00Z', $free);
        // completed and no-show sessions keep the slot, cancelled ones free it
        $booking = $app->db()->one('SELECT id FROM bookings');
        $b->setStatus($booking['id'], 'completed', 'owner');
        $this->assertNotContains('2026-11-10T08:00:00Z', array_column($b->availability('2026-11-10', '2026-11-10'), 'start'));
        $b->setStatus($booking['id'], 'no_show', 'owner');
        $this->assertNotContains('2026-11-10T08:00:00Z', array_column($b->availability('2026-11-10', '2026-11-10'), 'start'));
        $app->db()->run("UPDATE bookings SET status = 'cancelled'");
        $this->assertContains('2026-11-10T08:00:00Z', array_column($b->availability('2026-11-10', '2026-11-10'), 'start'));
    }

    public function testMinimumNoticeIsTwelveHoursExactly(): void
    {
        $app = CoreTestKit::app([], ['now' => '2026-11-09T18:00:00Z']); // Monday 20:00 SAST
        $c = CoreTestKit::client($app, credits: 5);
        $b = $app->bookings();
        // earliest = Tuesday 06:00Z (08:00 local)
        $this->assertSame('conflict: That time is no longer available. Please pick another slot.', $this->code(fn() => $b->create($c['id'], '2026-11-10T05:30:00Z', 'client', $c['id'])));
        $this->assertSame('none', $this->code(fn() => $b->create($c['id'], '2026-11-10T06:00:00Z', 'client', $c['id'])), 'exactly 12 h is allowed');
        $this->assertSame('none', $this->code(fn() => $b->create($c['id'], '2026-11-10T04:00:00Z', 'owner', $c['id'])), 'the owner is not bound by the notice');
        $this->assertSame(5 - 2, $app->credits()->balance($c['id']));
        $this->assertNotContains('2026-11-10T05:30:00Z', array_column($b->availability('2026-11-10', '2026-11-10'), 'start'));
        $this->assertSame('none', $this->code(fn() => $b->create($c['id'], '2026-11-10T07:30:00Z', 'chatgpt', 'chatgpt:g')), 'chatgpt follows the client rule');
        $this->assertStringStartsWith('conflict', $this->code(fn() => $b->create($c['id'], '2026-11-10T05:30:00Z', 'chatgpt', 'chatgpt:g')));
    }

    public function testBookingWindowIsTwentyEightDaysExactly(): void
    {
        $app = CoreTestKit::app([], ['now' => '2026-11-09T04:00:00Z']); // Monday 06:00 SAST
        $c = CoreTestKit::client($app, credits: 5);
        $b = $app->bookings();
        // latest = 2026-12-07T04:00:00Z (Monday 06:00 local): the very last bookable start
        $this->assertSame(['2026-12-07T04:00:00Z'], array_column($b->availability('2026-12-07', '2026-12-07'), 'start'));
        $this->assertSame('none', $this->code(fn() => $b->create($c['id'], '2026-12-07T04:00:00Z', 'client', $c['id'])), 'exactly 28 days is allowed');
        $this->assertStringStartsWith('conflict', $this->code(fn() => $b->create($c['id'], '2026-12-07T04:30:00Z', 'client', $c['id'])), '28 days and 30 minutes is not');
        $this->assertSame([], $b->availability('2026-12-07', '2026-12-07'), 'the only slot is now taken');
    }

    public function testCreateRulesAndStoredValues(): void
    {
        $app = CoreTestKit::app();
        $b = $app->bookings();
        $c = CoreTestKit::client($app, credits: 2);
        $o = CoreTestKit::owner($app);
        $this->assertSame('validation: start must be an ISO 8601 time such as 2026-11-09T07:00:00Z', $this->code(fn() => $b->create($c['id'], 'tomorrow', 'client', $c['id'])));
        $this->assertSame('validation: start must be an ISO 8601 time such as 2026-11-09T07:00:00Z', $this->code(fn() => $b->create($c['id'], self::START . "\n", 'client', $c['id'])));
        $this->assertSame('validation: Invalid createdBy', $this->code(fn() => $b->create($c['id'], self::START, 'hacker', $c['id'])));
        $this->assertSame('not_found: Client not found', $this->code(fn() => $b->create('ghost', self::START, 'client', 'x')));
        $this->assertSame('not_found: Client not found', $this->code(fn() => $b->create($o['id'], self::START, 'owner', 'x')), 'only clients can hold bookings');
        $r = $b->create($c['id'], '2026-11-10T10:00:00+02:00', 'owner', 'owner-9');
        $this->assertSame('2026-11-10T08:00:00Z', $r['start'], 'offsets are converted to UTC');
        $this->assertSame('2026-11-10T09:00:00Z', $r['end']);
        $this->assertSame('owner', $r['createdBy']);
        $row = $app->db()->one('SELECT * FROM bookings');
        $this->assertEquals(['status' => 'confirmed', 'refunded' => 0, 'created_by' => 'owner', 'created_at' => '2026-11-08 08:00:00'], array_intersect_key($row, array_flip(['status', 'refunded', 'created_by', 'created_at'])));
        $l = $app->db()->one("SELECT * FROM credit_ledger WHERE reason = 'booking'");
        $this->assertSame(-1, (int) $l['delta']);
        $this->assertSame('Session booked for Tue 10 Nov 10:00', $l['note']);
        $this->assertSame('owner-9', $l['actor']);
        $this->assertSame($row['id'], $l['booking_id']);
        $a = $app->db()->one("SELECT * FROM audit_log WHERE action = 'booking.create'");
        $this->assertSame('owner-9', $a['actor']);
        $this->assertSame('Booking created', $a['summary']);
        $this->assertSame(['bookingId' => $row['id'], 'start' => '2026-11-10T08:00:00Z', 'createdBy' => 'owner'], json_decode($a['args_json'], true));
        // conflict is reported before no_credits
        $broke = CoreTestKit::client($app, 'broke@example.com', 0);
        $this->assertStringStartsWith('conflict', $this->code(fn() => $b->create($broke['id'], self::START, 'client', $broke['id'])));
        $this->assertSame('no_credits: No session credits left. Please buy a pack first.', $this->code(fn() => $b->create($broke['id'], '2026-11-10T12:00:00Z', 'client', $broke['id'])));
    }

    public function testCancelRefundBoundaryAt24Hours(): void
    {
        $cancel = function (string $nowUtc, ?bool $refund): array {
            $app = CoreTestKit::app();
            $c = CoreTestKit::client($app, credits: 1);
            $bk = $app->bookings()->create($c['id'], self::START, 'client', $c['id']);
            $app->db()->freeze($nowUtc);
            $r = $app->bookings()->cancel($bk['id'], $refund, '  too tired  ', $c['id']);
            $row = $app->db()->one('SELECT * FROM bookings WHERE id = ?', [$bk['id']]);
            return [$r, $row, $app->credits()->balance($c['id']), $app];
        };
        [$r, $row, $bal] = $cancel('2026-11-09T07:59:59Z', null); // 24 h 0 min 1 s before
        $this->assertTrue($r['refunded'], 'more than 24 h: refunded by default');
        $this->assertSame(1, $bal);
        $this->assertSame(1, (int) $row['refunded']);
        $this->assertSame('too tired', $row['cancel_reason']);
        $this->assertSame('2026-11-09 07:59:59', $row['cancelled_at']);
        [$r, , $bal] = $cancel('2026-11-09T08:00:00Z', null); // exactly 24 h
        $this->assertFalse($r['refunded'], 'exactly 24 h is already inside the window');
        $this->assertSame(0, $bal);
        [$r, , $bal] = $cancel('2026-11-09T08:00:01Z', null);
        $this->assertFalse($r['refunded']);
        [$r, , $bal] = $cancel('2026-11-09T08:00:00Z', true);
        $this->assertTrue($r['refunded'], 'the owner may refund inside the window');
        $this->assertSame(1, $bal);
        [$r, , $bal] = $cancel('2026-11-09T08:00:00Z', false);
        $this->assertFalse($r['refunded']);
        [$r, , $bal] = $cancel('2026-11-08T08:00:00Z', false);
        $this->assertFalse($r['refunded'], 'the owner may refuse a refund outside the window');
        $this->assertSame(0, $bal);
        [$r, , $bal] = $cancel('2026-11-08T08:00:00Z', true);
        $this->assertTrue($r['refunded']);
        // errors and side effects
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app, credits: 1);
        $bk = $app->bookings()->create($c['id'], self::START, 'client', $c['id']);
        $this->assertSame('not_found: Booking not found', $this->code(fn() => $app->bookings()->cancel('ghost', null, '', 'x')));
        $res = $app->bookings()->cancel($bk['id'], null, '   ', 'client-1');
        $this->assertNull($app->db()->val('SELECT cancel_reason FROM bookings WHERE id = ?', [$bk['id']]), 'a blank reason is stored as NULL');
        $this->assertSame('conflict: Only confirmed bookings can be cancelled', $this->code(fn() => $app->bookings()->cancel($bk['id'], null, '', 'x')));
        $a = $app->db()->one("SELECT * FROM audit_log WHERE action = 'booking.cancel'");
        $this->assertSame(['bookingId' => $bk['id'], 'refunded' => true], json_decode($a['args_json'], true));
        $this->assertSame('Booking cancelled', $a['summary']);
        $this->assertSame('client-1', $a['actor']);
        $this->assertSame(str_repeat('é', 255), (function () {
            $app = CoreTestKit::app();
            $c = CoreTestKit::client($app, credits: 1);
            $bk = $app->bookings()->create($c['id'], self::START, 'client', $c['id']);
            $app->bookings()->cancel($bk['id'], null, str_repeat('é', 300), 'x');
            return $app->db()->val('SELECT cancel_reason FROM bookings WHERE id = ?', [$bk['id']]);
        })());
        $this->assertSame($bk['id'], $res['booking']['id']);
    }

    public function testRescheduleRules(): void
    {
        $app = CoreTestKit::app();
        $b = $app->bookings();
        $c = CoreTestKit::client($app, credits: 3);
        $one = $b->create($c['id'], self::START, 'client', $c['id']);
        $two = $b->create($c['id'], '2026-11-10T11:00:00Z', 'client', $c['id']);
        $this->assertSame('not_found: Booking not found', $this->code(fn() => $b->reschedule('ghost', '2026-11-10T13:00:00Z', 'x')));
        $this->assertSame('validation: start must be an ISO 8601 time such as 2026-11-09T07:00:00Z', $this->code(fn() => $b->reschedule($one['id'], 'soon', 'x')));
        $this->assertSame('conflict: That time is not available.', $this->code(fn() => $b->reschedule($one['id'], '2026-11-10T11:00:00Z', 'x')));
        $this->assertSame('conflict: That time is not available.', $this->code(fn() => $b->reschedule($one['id'], '2026-11-10T11:30:00Z', 'x')), 'overlaps the other booking and its buffer');
        $moved = $b->reschedule($one['id'], '2026-11-10T08:30:00Z', 'owner-2');
        $this->assertSame('2026-11-10T08:30:00Z', $moved['start'], 'a booking may move into its own old slot');
        $this->assertSame('2026-11-10T09:30:00Z', $moved['end']);
        $this->assertSame(1, $app->credits()->balance($c['id']), 'moving costs nothing');
        $a = $app->db()->one("SELECT * FROM audit_log WHERE action = 'booking.reschedule'");
        $this->assertSame(['bookingId' => $one['id'], 'start' => '2026-11-10T08:30:00Z'], json_decode($a['args_json'], true));
        $this->assertSame('owner-2', $a['actor']);
        $this->assertSame('Booking rescheduled', $a['summary']);
        // no notice rule when moving: a booking can be moved to a time that is only an hour away
        $app->db()->freeze('2026-11-10T09:00:00Z');
        $this->assertSame('none', $this->code(fn() => $b->reschedule($two['id'], '2026-11-10T10:30:00Z', 'x')));
        $b->cancel($two['id'], true, 'x', 'x');
        $this->assertSame('conflict: Only confirmed bookings can be moved', $this->code(fn() => $b->reschedule($two['id'], '2026-11-10T13:00:00Z', 'x')));
    }

    public function testSetStatusRules(): void
    {
        $app = CoreTestKit::app();
        $b = $app->bookings();
        $c = CoreTestKit::client($app, credits: 2);
        $one = $b->create($c['id'], self::START, 'client', $c['id']);
        $this->assertSame('validation: status must be completed or no_show', $this->code(fn() => $b->setStatus($one['id'], 'cancelled', 'x')));
        $this->assertSame('validation: status must be completed or no_show', $this->code(fn() => $b->setStatus($one['id'], 'confirmed', 'x')));
        $this->assertSame('not_found: Booking not found', $this->code(fn() => $b->setStatus('ghost', 'completed', 'x')));
        $this->assertSame('completed', $b->setStatus($one['id'], 'completed', 'owner-3')['status']);
        $this->assertSame('completed', $app->db()->val('SELECT status FROM bookings'));
        $a = $app->db()->one("SELECT * FROM audit_log WHERE action = 'booking.status'");
        $this->assertSame(['bookingId' => $one['id'], 'status' => 'completed'], json_decode($a['args_json'], true));
        $this->assertSame('Booking marked completed', $a['summary']);
        $this->assertSame('no_show', $b->setStatus($one['id'], 'no_show', 'owner-3')['status']);
        $two = $b->create($c['id'], '2026-11-10T11:00:00Z', 'client', $c['id']);
        $b->cancel($two['id'], null, '', 'x');
        $this->assertSame('conflict: A cancelled booking cannot be marked no_show', $this->code(fn() => $b->setStatus($two['id'], 'no_show', 'x')));
    }

    public function testLocalToUtcParsing(): void
    {
        $app = CoreTestKit::app();
        $b = $app->bookings();
        $this->assertSame('2026-11-10T08:00:00Z', $b->localToUtc('2026-11-10T10:00'));
        $this->assertSame('2026-11-10T08:00:30Z', $b->localToUtc('2026-11-10T10:00:30'));
        $this->assertSame('2026-12-31T22:00:00Z', $b->localToUtc('2027-01-01T00:00'));
        $msg = 'validation: Time must look like 2026-11-09T09:30';
        foreach (['', 'tomorrow', "2026-11-10T10:00\0", '2026-11-10 10:00', '2026-11-10T10', '2026-13-10T10:00', '2026-11-10T10:00Z', "2026-11-10T10:00\n"] as $bad) {
            $this->assertSame($msg, $this->code(fn() => $b->localToUtc($bad)), json_encode($bad));
        }
    }

    public function testListingsAndBusyBlocks(): void
    {
        $app = CoreTestKit::app();
        $b = $app->bookings();
        $c = CoreTestKit::client($app, credits: 5);
        $in = $app->db()->pdo()->prepare("INSERT INTO bookings (id,user_id,start_at,end_at,status,created_by,created_at) VALUES (?,?,?,?,?,?,'2026-11-01 00:00:00')");
        foreach ([['a', '2026-11-08 21:59:00', 'confirmed'], ['b', '2026-11-08 22:00:00', 'confirmed'], ['c', '2026-11-09 21:59:00', 'cancelled'], ['d', '2026-11-09 22:00:00', 'confirmed'], ['e', '2026-11-09 12:00:00', 'completed']] as [$id, $st, $status]) {
            $in->execute([$id, $c['id'], $st, gmdate('Y-m-d H:i:s', strtotime($st . ' UTC') + 3600), $status, 'client']);
        }
        $this->assertSame(['b', 'e', 'c'], array_column($b->list('2026-11-09', '2026-11-09'), 'id'), 'the local day 9 Nov, ordered by start');
        $this->assertSame(['c'], array_column($b->list('2026-11-09', '2026-11-09', 'cancelled'), 'id'));
        $this->assertSame(['a'], array_column($b->list('2026-11-08', '2026-11-08'), 'id'));
        $this->assertSame(['a', 'b', 'e', 'c', 'd'], array_column($b->list('2026-11-08', '2026-11-10'), 'id'));
        $this->assertSame('validation: Dates must be YYYY-MM-DD', $this->code(fn() => $b->list('x', '2026-11-09')));
        $this->assertSame('validation: Dates must be YYYY-MM-DD', $this->code(fn() => $b->list('2026-11-09', "2026-11-10\n")));
        $this->assertSame('Test Client', $b->list('2026-11-09', '2026-11-09')[0]['clientName']);
        $this->assertSame(['d', 'c', 'e', 'b', 'a'], array_column($b->forUser($c['id']), 'id'), 'newest first');
        $this->assertNull($b->get('ghost'));
        $this->assertSame('e', $b->get('e')['id']);
        // owner busy view
        $app->db()->run("INSERT INTO calendar_busy (start_at, end_at, synced_at) VALUES ('2026-11-09 22:00:00', '2026-11-09 23:00:00', '2026-11-08 00:00:00')"); // echo of booking d
        $app->db()->run("INSERT INTO calendar_busy (start_at, end_at, synced_at) VALUES ('2026-11-09 05:00:00', '2026-11-09 06:00:00', '2026-11-08 00:00:00')");
        $busy = $b->busyBlocks('2026-11-09', '2026-11-10');
        $this->assertSame([
            ['start' => '2026-11-08T21:59:00Z', 'end' => '2026-11-08T22:59:00Z', 'source' => 'booking', 'label' => 'Test Client'],
            ['start' => '2026-11-08T22:00:00Z', 'end' => '2026-11-08T23:00:00Z', 'source' => 'booking', 'label' => 'Test Client'],
            ['start' => '2026-11-09T05:00:00Z', 'end' => '2026-11-09T06:00:00Z', 'source' => 'google', 'label' => 'Busy (Google Calendar)'],
            ['start' => '2026-11-09T22:00:00Z', 'end' => '2026-11-09T23:00:00Z', 'source' => 'booking', 'label' => 'Test Client'],
        ], $busy, 'sorted by start; our own echo is not shown twice');
        $this->assertSame('validation: Dates must be YYYY-MM-DD', $this->code(fn() => $b->busyBlocks('nope', '2026-11-10')));
    }

    public function testSummaryPeriodsAndCounts(): void
    {
        $app = CoreTestKit::app([], ['now' => '2026-11-10T08:00:00Z']); // Tuesday
        $b = $app->bookings();
        $c = CoreTestKit::client($app, credits: 0);
        $in = $app->db()->pdo()->prepare("INSERT INTO bookings (id,user_id,start_at,end_at,status,created_by,created_at) VALUES (?,?,?,?,?,'client','2026-11-01 00:00:00')");
        $rows = [['w0', '2026-11-08 21:59:00', 'confirmed'], ['w1', '2026-11-08 22:00:00', 'confirmed'], ['w2', '2026-11-12 08:00:00', 'completed'], ['w3', '2026-11-13 08:00:00', 'no_show'],
            ['w4', '2026-11-14 08:00:00', 'cancelled'], ['w5', '2026-11-15 21:59:00', 'confirmed'], ['w6', '2026-11-15 22:00:00', 'confirmed']];
        foreach ($rows as [$id, $st, $status]) {
            $in->execute([$id, $c['id'], $st, gmdate('Y-m-d H:i:s', strtotime($st . ' UTC') + 3600), $status]);
        }
        $w = $b->summary('week');
        $this->assertSame('week', $w['period']);
        $this->assertSame('2026-11-09', $w['from']);
        $this->assertSame('2026-11-15', $w['to']);
        $this->assertSame(['bookedSessions' => 4, 'completedSessions' => 1, 'cancelledSessions' => 1], array_intersect_key($w, array_flip(['bookedSessions', 'completedSessions', 'cancelledSessions'])));
        $this->assertIsFloat($w['freeSlotHours']);
        $this->assertGreaterThan(0.0, $w['freeSlotHours']);
        $this->assertSame(0.0, fmod($w['freeSlotHours'] * 2, 1.0), 'whole or half hours');
        $m = $b->summary('month');
        $this->assertSame('2026-11-01', $m['from']);
        $this->assertSame('2026-11-30', $m['to']);
        $this->assertSame(6, $m['bookedSessions'], 'all but the cancelled one');
        $this->assertSame('validation: period must be week or month', $this->code(fn() => $b->summary('year')));
        $this->assertSame('validation: period must be week or month', $this->code(fn() => $b->summary('')));
        // credits sold and new clients inside the period only
        $app->credits()->adjust($c['id'], 5, 'purchase', null, 'a');
        $app->db()->freeze('2026-10-20T08:00:00Z');
        $c2 = CoreTestKit::client($app, 'early@example.com', 0);
        $app->credits()->adjust($c2['id'], 3, 'purchase', null, 'a');
        $app->db()->freeze('2026-11-10T08:00:00Z');
        $m = $b->summary('month');
        $this->assertSame(5, $m['creditsSold']);
        $this->assertSame(1, $m['newClients']);
        $this->assertSame(1, $b->summary('week')['newClients']);
        // the last day of the month: nothing future left, so no free hours
        $app->db()->freeze('2026-11-30T21:00:00Z'); // Monday 23:00 local
        $this->assertSame(0.0, $b->summary('month')['freeSlotHours']);
        $this->assertSame('2026-11-30', $b->summary('month')['to']);
    }
}
