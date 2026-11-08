<?php
declare(strict_types=1);

namespace StretchEffect\Tests;

use PHPUnit\Framework\TestCase;
use StretchEffect\Session\ArraySession;

final class CoreApiTest extends TestCase
{
    private function assertNoSecrets(string $body): void
    {
        foreach (['password_hash', 'passwordHash', 'totp_secret', 'totpSecret', '$argon2', '$2y$', 'refresh_token'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "response leaks $needle");
        }
    }

    public function testSignupLoginMeAndNoLeaks(): void
    {
        $app = CoreTestKit::app();
        $r = CoreTestKit::call($app, 'POST', '/api/auth/signup', ['name' => 'Lerato Demo', 'email' => 'Lerato@Example.com', 'phone' => '082 000 0000', 'password' => 'a-long-password', 'consent' => true]);
        $this->assertSame(201, $r['status'], $r['body']);
        $this->assertSame('lerato@example.com', $r['json']['user']['email']);
        $this->assertSame('client', $r['json']['user']['role']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $r['json']['user']['createdAt']);
        $this->assertNoSecrets($r['body']);
        $me = CoreTestKit::call($app, 'GET', '/api/me');
        $this->assertSame('Lerato Demo', $me['json']['user']['name']);
        $this->assertNoSecrets($me['body']);
        CoreTestKit::call($app, 'POST', '/api/auth/logout', []);
        $this->assertNull(CoreTestKit::call($app, 'GET', '/api/me')['json']['user']);
        $login = CoreTestKit::call($app, 'POST', '/api/auth/login', ['email' => 'lerato@example.com', 'password' => 'a-long-password']);
        $this->assertSame(200, $login['status']);
        $this->assertNoSecrets($login['body']);
    }

    public function testSignupValidation(): void
    {
        $app = CoreTestKit::app();
        $ok = ['name' => 'A B', 'email' => 'ab@example.com', 'password' => 'a-long-password', 'consent' => true];
        foreach ([
            ['password' => 'short'], ['email' => 'not-an-email'], ['consent' => false], ['name' => ''], ['phone' => '<script>'],
        ] as $bad) {
            $r = CoreTestKit::call($app, 'POST', '/api/auth/signup', $bad + $ok);
            $this->assertSame(422, $r['status'], json_encode($bad));
            $this->assertSame('validation', $r['json']['error']['code']);
        }
        $this->assertSame(201, CoreTestKit::call($app, 'POST', '/api/auth/signup', $ok)['status']);
        $this->assertSame(422, CoreTestKit::call($app->withSession(new ArraySession()), 'POST', '/api/auth/signup', $ok)['status'], 'duplicate email');
    }

    public function testOwnerEndpointsRefuseClientsAndAnonymous(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app, credits: 1);
        $anon = $app->withSession(new ArraySession());
        $gets = ['/api/admin/clients', '/api/admin/clients/' . $c['id'], '/api/admin/busy', '/api/admin/bookings', '/api/admin/calendar', '/api/admin/summary', '/api/admin/grants', '/api/admin/audit'];
        $posts = ['/api/admin/clients/' . $c['id'] . '/credits', '/api/admin/packs/0123456789abcdef/paid', '/api/admin/bookings', '/api/admin/bookings/0123456789abcdef/cancel',
            '/api/admin/bookings/0123456789abcdef/status', '/api/admin/calendar/connect', '/api/admin/calendar/disconnect', '/api/admin/grants/0123456789abcdef/revoke'];
        $q = ['from' => '2026-11-09', 'to' => '2026-11-10'];
        foreach ($gets as $p) {
            $this->assertSame(401, CoreTestKit::call($anon, 'GET', $p, null, $q)['status'], "anon GET $p");
        }
        foreach ($posts as $p) {
            $this->assertSame(401, CoreTestKit::call($anon, 'POST', $p, [])['status'], "anon POST $p");
        }
        $this->assertSame(401, CoreTestKit::call($anon, 'PATCH', '/api/admin/settings', ['bufferMinutes' => 10])['status']);
        CoreTestKit::loginClient($app, $c);
        foreach ($gets as $p) {
            $this->assertSame(403, CoreTestKit::call($app, 'GET', $p, null, $q)['status'], "client GET $p");
        }
        foreach ($posts as $p) {
            $this->assertSame(403, CoreTestKit::call($app, 'POST', $p, [])['status'], "client POST $p");
        }
        $this->assertSame(403, CoreTestKit::call($app, 'PATCH', '/api/admin/settings', ['bufferMinutes' => 10])['status']);
    }

    public function testFullClientJourney(): void
    {
        $app = CoreTestKit::app();
        $client = $app->withSession(new ArraySession());
        $ownerApp = $app->withSession(new ArraySession());
        $o = CoreTestKit::owner($app);

        CoreTestKit::call($client, 'POST', '/api/auth/signup', ['name' => 'Journey Client', 'email' => 'j@example.com', 'password' => 'a-long-password', 'consent' => true]);
        $pk = CoreTestKit::call($client, 'GET', '/api/packages');
        $this->assertSame(['single', 'five', 'ten'], array_column($pk['json'], 'id'));
        $this->assertSame(2500, $pk['json'][1]['priceZar']);

        // no credits yet
        $slots = CoreTestKit::call($client, 'GET', '/api/availability', null, ['from' => '2026-11-10', 'to' => '2026-11-10']);
        $this->assertSame(200, $slots['status']);
        $start = $slots['json'][4]['start'];
        $this->assertSame(409, CoreTestKit::call($client, 'POST', '/api/me/bookings', ['start' => $start])['status']);

        // request a pack, owner confirms payment
        $req = CoreTestKit::call($client, 'POST', '/api/me/packs', ['packageId' => 'five']);
        $this->assertSame(201, $req['status']);
        $this->assertSame('pending', $req['json']['status']);
        CoreTestKit::loginOwner($ownerApp, $o);
        $paid = CoreTestKit::call($ownerApp, 'POST', '/api/admin/packs/' . $req['json']['id'] . '/paid', ['paymentReference' => 'EFT-1']);
        $this->assertSame('paid', $paid['json']['status']);
        $this->assertSame(409, CoreTestKit::call($ownerApp, 'POST', '/api/admin/packs/' . $req['json']['id'] . '/paid', [])['status'], 'cannot pay twice');
        $this->assertSame(5, CoreTestKit::call($client, 'GET', '/api/me/balance')['json']['balance']);

        // book, see it, cancel with refund
        $b = CoreTestKit::call($client, 'POST', '/api/me/bookings', ['start' => $start]);
        $this->assertSame(201, $b['status'], $b['body']);
        $this->assertSame('confirmed', $b['json']['status']);
        $this->assertSame(4, CoreTestKit::call($client, 'GET', '/api/me/balance')['json']['balance']);
        $this->assertCount(1, CoreTestKit::call($client, 'GET', '/api/me/bookings')['json']);
        $this->assertCount(2, CoreTestKit::call($client, 'GET', '/api/me/ledger')['json']);
        $this->assertNotContains($start, array_column(CoreTestKit::call($client, 'GET', '/api/availability', null, ['from' => '2026-11-10', 'to' => '2026-11-10'])['json'], 'start'));

        // owner sees it
        $list = CoreTestKit::call($ownerApp, 'GET', '/api/admin/bookings', null, ['from' => '2026-11-10', 'to' => '2026-11-10']);
        $this->assertSame('Journey Client', $list['json'][0]['clientName']);
        $busy = CoreTestKit::call($ownerApp, 'GET', '/api/admin/busy', null, ['from' => '2026-11-10', 'to' => '2026-11-10']);
        $this->assertSame('booking', $busy['json'][0]['source']);
        $found = CoreTestKit::call($ownerApp, 'GET', '/api/admin/clients', null, ['q' => 'journey']);
        $this->assertCount(1, $found['json']);
        $this->assertSame(4, $found['json'][0]['balance']);
        $this->assertNoSecrets($found['body']);
        $detail = CoreTestKit::call($ownerApp, 'GET', '/api/admin/clients/' . $b['json']['userId']);
        $this->assertSame(['summary', 'ledger', 'bookings', 'packs'], array_keys($detail['json']));
        $this->assertNoSecrets($detail['body']);

        $other = CoreTestKit::client($app, 'other@example.com', 1);
        $otherApp = $app->withSession(new ArraySession());
        CoreTestKit::loginClient($otherApp, $other);
        $this->assertSame(404, CoreTestKit::call($otherApp, 'POST', '/api/me/bookings/' . $b['json']['id'] . '/cancel', [])['status'], 'cannot cancel another client\'s booking');

        $c = CoreTestKit::call($client, 'POST', '/api/me/bookings/' . $b['json']['id'] . '/cancel', []);
        $this->assertSame(200, $c['status']);
        $this->assertTrue($c['json']['refunded']);
        $this->assertSame('cancelled', $c['json']['booking']['status']);
        $this->assertSame(5, CoreTestKit::call($client, 'GET', '/api/me/balance')['json']['balance']);

        // owner actions
        $adj = CoreTestKit::call($ownerApp, 'POST', '/api/admin/clients/' . $b['json']['userId'] . '/credits', ['delta' => 2, 'note' => 'goodwill']);
        $this->assertSame(201, $adj['status']);
        $this->assertSame('owner_adjust', $adj['json']['reason']);
        $this->assertSame(409, CoreTestKit::call($ownerApp, 'POST', '/api/admin/clients/' . $b['json']['userId'] . '/credits', ['delta' => -50, 'note' => 'too much'])['status']);
        $ob = CoreTestKit::call($ownerApp, 'POST', '/api/admin/bookings', ['userId' => $b['json']['userId'], 'start' => $start]);
        $this->assertSame(201, $ob['status'], $ob['body']);
        $this->assertSame('owner', $ob['json']['createdBy']);
        $st = CoreTestKit::call($ownerApp, 'POST', '/api/admin/bookings/' . $ob['json']['id'] . '/status', ['status' => 'completed']);
        $this->assertSame('completed', $st['json']['status']);
        $sum = CoreTestKit::call($ownerApp, 'GET', '/api/admin/summary', null, ['period' => 'month']);
        $this->assertSame('month', $sum['json']['period']);
        $audit = CoreTestKit::call($ownerApp, 'GET', '/api/admin/audit', null, ['limit' => '20']);
        $this->assertNotEmpty($audit['json']);
        $this->assertNoSecrets($audit['body']);
    }

    public function testAvailabilityValidation(): void
    {
        $app = CoreTestKit::app();
        $this->assertSame(422, CoreTestKit::call($app, 'GET', '/api/availability', null, ['from' => '2026-11-01', 'to' => '2026-12-05'])['status'], '35 days');
        $this->assertSame(422, CoreTestKit::call($app, 'GET', '/api/availability', null, ['from' => 'x', 'to' => '2026-12-05'])['status']);
        $this->assertSame(422, CoreTestKit::call($app, 'GET', '/api/availability', null, ['from' => '2026-11-05', 'to' => '2026-11-01'])['status']);
        $this->assertSame(200, CoreTestKit::call($app, 'GET', '/api/availability', null, ['from' => '2026-11-09', 'to' => '2026-12-09'])['status'], '31 days inclusive');
    }

    public function testSettingsReadAndOwnerPatch(): void
    {
        $app = CoreTestKit::app();
        $s = CoreTestKit::call($app, 'GET', '/api/settings')['json'];
        $this->assertSame('Africa/Johannesburg', $s['timezone']);
        $this->assertSame(60, $s['sessionMinutes']);
        $this->assertCount(7, $s['workingHours']);
        $o = CoreTestKit::owner($app);
        CoreTestKit::loginOwner($app, $o);
        $r = CoreTestKit::call($app, 'PATCH', '/api/admin/settings', ['bufferMinutes' => 30, 'minNoticeHours' => 2]);
        $this->assertSame(30, $r['json']['bufferMinutes']);
        $this->assertSame(422, CoreTestKit::call($app, 'PATCH', '/api/admin/settings', ['bufferMinutes' => -1])['status']);
        $this->assertSame(422, CoreTestKit::call($app, 'PATCH', '/api/admin/settings', ['nonsense' => 1])['status']);
    }

    public function testUnknownRouteAndErrorShape(): void
    {
        $app = CoreTestKit::app();
        $r = CoreTestKit::call($app, 'GET', '/api/nope');
        $this->assertSame(404, $r['status']);
        $this->assertSame(['error' => ['code' => 'not_found', 'message' => 'Not found']], $r['json']);
        $r = CoreTestKit::call($app, 'GET', '/somewhere-else');
        $this->assertSame(404, $r['status']);
    }

    public function testSecurityHeadersAndNoStore(): void
    {
        $app = CoreTestKit::app();
        $h = CoreTestKit::call($app, 'GET', '/api/packages')['headers'];
        $this->assertStringContainsString("default-src 'self'", $h['Content-Security-Policy']);
        $this->assertStringContainsString("frame-ancestors 'none'", $h['Content-Security-Policy']);
        $this->assertStringContainsString('fonts.googleapis.com', $h['Content-Security-Policy']);
        $this->assertSame('nosniff', $h['X-Content-Type-Options']);
        $this->assertSame('strict-origin-when-cross-origin', $h['Referrer-Policy']);
        $this->assertSame('no-store', $h['Cache-Control']);
        $this->assertArrayNotHasKey('Strict-Transport-Security', $h, 'dev');
        $live = CoreTestKit::app(['app' => ['env' => 'live', 'base_url' => 'https://example.co.za']]);
        $this->assertArrayHasKey('Strict-Transport-Security', CoreTestKit::call($live, 'GET', '/api/packages')['headers']);
    }

    public function testCronEndpointNeedsToken(): void
    {
        $app = CoreTestKit::app();
        $this->assertSame(403, CoreTestKit::call($app, 'POST', '/api/cron/sync', [], csrf: false)['status']);
        $this->assertSame(403, CoreTestKit::call($app, 'POST', '/api/cron/sync', [], csrf: false, headers: ['X-Cron-Token' => 'nope'])['status']);
        $r = CoreTestKit::call($app, 'POST', '/api/cron/sync', [], csrf: false, headers: ['X-Cron-Token' => 'cron-secret-token']);
        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['json']['ok']);
    }

    public function testCalendarConnectFlow(): void
    {
        $g = new FakeGoogleTransport();
        $app = CoreTestKit::app([], ['transport' => $g]);
        $o = CoreTestKit::owner($app);
        CoreTestKit::loginOwner($app, $o);
        $this->assertFalse(CoreTestKit::call($app, 'GET', '/api/admin/calendar')['json']['connected']);
        $r = CoreTestKit::call($app, 'POST', '/api/admin/calendar/connect', []);
        $url = $r['json']['redirectUrl'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('offline', $q['access_type']);
        $this->assertSame('consent', $q['prompt']);
        $this->assertSame('http://localhost:8090/api/admin/calendar/callback', $q['redirect_uri']);
        $this->assertStringContainsString('calendar.events.owned', $q['scope']);
        $this->assertStringContainsString('calendar.freebusy', $q['scope']);
        // wrong state is refused
        $bad = (new \StretchEffect\Http\Kernel($app))->handle(CoreTestKit::request('GET', '/api/admin/calendar/callback', null, ['code' => 'c', 'state' => 'wrong']));
        $this->assertSame(302, $bad->status);
        $this->assertStringContainsString('error=state', $bad->headers['Location']);
        // right state
        CoreTestKit::call($app, 'POST', '/api/admin/calendar/connect', []);
        $state = $app->session()->get('google_state');
        $ok = (new \StretchEffect\Http\Kernel($app))->handle(CoreTestKit::request('GET', '/api/admin/calendar/callback', null, ['code' => 'c', 'state' => $state]));
        $this->assertSame('http://localhost:8090/#/admin/calendar', $ok->headers['Location']);
        $this->assertTrue(CoreTestKit::call($app, 'GET', '/api/admin/calendar')['json']['connected']);
        $d = CoreTestKit::call($app, 'POST', '/api/admin/calendar/disconnect', []);
        $this->assertFalse($d['json']['connected']);
    }

    public function testRevokeGrantRevokesTokens(): void
    {
        $app = CoreTestKit::app();
        $o = CoreTestKit::owner($app);
        $db = $app->db();
        $db->run("INSERT INTO oauth_clients (client_id, client_name, redirect_uris, registration, created_at) VALUES ('c1', 'ChatGPT', '[]', 'dcr', '2026-11-08 08:00:00')");
        $db->run("INSERT INTO oauth_grants (id, client_id, user_id, scopes, resource, created_at) VALUES ('aaaaaaaaaaaaaaaa', 'c1', ?, 'bookings:read clients:read', 'http://localhost:8090/mcp', '2026-11-08 08:00:00')", [$o['id']]);
        $db->run("INSERT INTO oauth_tokens (token_hash, grant_id, kind, expires_at, created_at) VALUES (?, 'aaaaaaaaaaaaaaaa', 'access', '2026-11-08 09:00:00', '2026-11-08 08:00:00')", [str_repeat('a', 64)]);
        CoreTestKit::loginOwner($app, $o);
        $g = CoreTestKit::call($app, 'GET', '/api/admin/grants');
        $this->assertSame(['bookings:read', 'clients:read'], $g['json'][0]['scopes']);
        $this->assertSame('ChatGPT', $g['json'][0]['clientName']);
        $this->assertSame(200, CoreTestKit::call($app, 'POST', '/api/admin/grants/aaaaaaaaaaaaaaaa/revoke', [])['status']);
        $this->assertNotNull($db->val("SELECT revoked_at FROM oauth_grants WHERE id = 'aaaaaaaaaaaaaaaa'"));
        $this->assertNotNull($db->val("SELECT revoked_at FROM oauth_tokens WHERE grant_id = 'aaaaaaaaaaaaaaaa'"));
        $this->assertArrayHasKey('revokedAt', CoreTestKit::call($app, 'GET', '/api/admin/grants')['json'][0]);
    }
}
