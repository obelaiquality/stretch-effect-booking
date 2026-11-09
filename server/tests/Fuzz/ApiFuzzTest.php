<?php
declare(strict_types=1);

namespace StretchEffect\Tests\Fuzz;

use StretchEffect\App;
use StretchEffect\Contracts\HttpRequest;
use StretchEffect\Contracts\HttpResponse;
use StretchEffect\Http\Kernel;
use StretchEffect\Services\Totp;
use StretchEffect\Session\ArraySession;

/**
 * Fuzzes every /api/* endpoint with an anonymous visitor, two clients and the owner, each in their own session.
 * Invariants: never 500 or leaked internals; CSRF is enforced on every write; a client never reads or changes another
 * client's data and never reaches /api/admin; balances equal SUM(ledger) and never go negative; bookings never overlap.
 */
final class ApiFuzzTest extends FuzzCase
{
    private FuzzWorld $w;
    /** @var array<string,App> */
    private array $actors = [];
    private int $ipSeq = 0;
    private int $emailSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = FuzzWorld::create(3);
        $this->actors = [
            'anon' => $this->w->app->withSession(new ArraySession()),
            'clientA' => $this->w->clientSession(0),
            'clientB' => $this->w->clientSession(1),
            'owner' => $this->w->ownerSession(),
        ];
        // some history: pack requests and bookings from both clients
        $this->w->app->credits()->requestPack($this->w->clients[0]['id'], 'five');
        $this->w->app->credits()->requestPack($this->w->clients[1]['id'], 'single');
        foreach ([0, 1] as $i) {
            $d = $this->w->now()->setTimezone(new \DateTimeZone('Africa/Johannesburg'))->modify('+' . (2 + $i) . ' days')->format('Y-m-d');
            $slots = $this->w->app->bookings()->availability($d, $d);
            $this->w->app->bookings()->create($this->w->clients[$i]['id'], $slots[2 + $i]['start'], 'client', $this->w->clients[$i]['id']);
        }
    }

    /** A new App instance (its own settings cache), as every real request gets. */
    private function fresh(): App
    {
        return $this->w->app->withSession(new ArraySession());
    }

    private function ip(): string
    {
        $n = ++$this->ipSeq;
        return '172.16.' . (($n >> 8) & 255) . '.' . ($n & 255);
    }

    private function day(int $off): string
    {
        return $this->w->now()->setTimezone(new \DateTimeZone('Africa/Johannesburg'))->modify(($off >= 0 ? '+' : '') . $off . ' days')->format('Y-m-d');
    }

    private function realId(string $table): string
    {
        $ids = array_column($this->w->app->db()->all("SELECT id FROM $table ORDER BY rowid"), 'id'); // fixed order: ids are random, replays must not depend on them
        return $ids === [] ? $this->rng->hex(8) : $this->rng->pick($ids);
    }

    /** Path parameter: usually a real id, sometimes a hostile one. */
    private function pid(string $table): string
    {
        return match ($this->rng->weighted(['real' => 70, 'rand' => 12, 'other' => 18])) {
            'real' => $this->realId($table),
            'rand' => $this->rng->hex(8),
            default => $this->rng->pick([strtoupper($this->rng->hex(8)), $this->rng->hex(7), $this->rng->hex(9), Hostile::string($this->rng), '..', '%2e%2e', $this->realId($table) . '/', $this->realId($table) . '%00', '../admin/clients']),
        };
    }

    private function futureSlot(): string
    {
        for ($t = 0; $t < 4; $t++) {
            $d = $this->day($this->rng->int(0, 14));
            $slots = $this->w->app->bookings()->availability($d, $d);
            if ($slots !== []) {
                return $this->rng->pick($slots)['start'];
            }
        }
        return '2026-11-12T07:00:00Z';
    }

    /**
     * One request: [method, path, query, body|null, extra headers, csrf-applies]
     * @return array{0:string,1:string,2:array<string,string>,3:mixed,4:array<string,string>}
     */
    private function build(string $role): array
    {
        $cat = $this->rng->weighted([
            'public' => 12, 'auth' => 12, 'me_read' => 14, 'me_write' => 16, 'admin_read' => 14, 'admin_write' => 22, 'cron' => 3, 'odd' => 4, 'callback' => 3,
        ]);
        $r = $this->rng;
        switch ($cat) {
            case 'public':
                return $r->pick([
                    ['GET', '/api/csrf', [], null, []], ['GET', '/api/me', [], null, []], ['GET', '/api/packages', [], null, []], ['GET', '/api/settings', [], null, []],
                    ['GET', '/api/availability', ['from' => $this->day($r->int(-2, 5)), 'to' => $this->day($r->int(0, 40))], null, []],
                ]);
            case 'auth':
                $email = $this->w->clients[$r->int(0, 1)]['email'];
                return $r->pick([
                    ['POST', '/api/auth/signup', [], ['name' => 'Fuzz Person', 'email' => 'new' . $this->emailSeq++ . '@example.com', 'phone' => '+27 82 555 0100', 'password' => 'long enough pw 1', 'consent' => true], []],
                    ['POST', '/api/auth/login', [], ['email' => $r->chance(0.5) ? $email : $this->w->owner['email'], 'password' => $r->chance(0.5) ? 'correct-horse-battery' : $this->w->owner['password']], []],
                    ['POST', '/api/auth/login', [], ['email' => 'nobody@example.com', 'password' => 'whatever-password'], []],
                    ['POST', '/api/auth/totp', [], ['code' => Totp::code($this->w->owner['secret'], Totp::step($this->w->now()->getTimestamp()))], []],
                    ['POST', '/api/auth/totp', [], ['code' => '000000'], []],
                    ['POST', '/api/auth/logout', [], null, []],
                ]);
            case 'me_read':
                return [$r->pick(['GET', 'GET', 'HEAD']), '/api/me/' . $r->pick(['balance', 'ledger', 'bookings', 'packs']), [], null, []];
            case 'me_write':
                return $r->pick([
                    ['POST', '/api/me/packs', [], ['packageId' => $r->pick(['single', 'five', 'ten'])], []],
                    ['POST', '/api/me/bookings', [], ['start' => $this->futureSlot()], []],
                    ['POST', '/api/me/bookings', [], ['start' => $this->futureSlot(), 'userId' => $this->w->clients[$r->int(0, 2)]['id']], []],
                    ['POST', '/api/me/bookings/' . $this->pid('bookings') . '/cancel', [], null, []],
                    ['POST', '/api/me/bookings/' . $this->pid('bookings') . '/cancel', [], ['refund' => true], []],
                ]);
            case 'admin_read':
                return $r->pick([
                    ['GET', '/api/admin/clients', ['q' => $r->pick(['', 'Fuzz', 'zanele', '%', '_', "' OR 1=1--", 'example.com', '!'])], null, []],
                    ['GET', '/api/admin/clients/' . $this->pid('users'), [], null, []],
                    ['GET', '/api/admin/bookings', ['from' => $this->day($r->int(-10, 0)), 'to' => $this->day($r->int(0, 60))], null, []],
                    ['GET', '/api/admin/busy', ['from' => $this->day($r->int(-3, 0)), 'to' => $this->day($r->int(0, 30))], null, []],
                    ['GET', '/api/admin/summary', ['period' => $r->pick(['week', 'month', 'year', ''])], null, []],
                    ['GET', '/api/admin/grants', [], null, []], ['GET', '/api/admin/audit', ['limit' => (string) $r->pick([1, 50, 500, 501, 0, -1])], null, []],
                    ['GET', '/api/admin/calendar', [], null, []], ['GET', '/api/admin/connector/pairing', [], null, []],
                ]);
            case 'admin_write':
                return $r->pick([
                    ['POST', '/api/admin/clients/' . $this->pid('users') . '/credits', [], ['delta' => $r->int(-8, 8), 'note' => 'fuzz adjust'], []],
                    ['POST', '/api/admin/packs/' . $this->pid('pack_requests') . '/paid', [], ['paymentReference' => $r->pick(['PAY-1', 'pay-1 ', 'EFT ' . $r->int(1, 9), 'ref  2'])], []],
                    ['POST', '/api/admin/packs/' . $this->pid('pack_requests') . '/paid', [], [], []],
                    ['POST', '/api/admin/bookings', [], ['userId' => $r->pick($this->w->clients)['id'], 'start' => $this->futureSlot()], []],
                    ['POST', '/api/admin/bookings', [], ['userId' => $this->w->owner['id'], 'start' => $this->futureSlot()], []],
                    ['POST', '/api/admin/bookings/' . $this->pid('bookings') . '/cancel', [], ['refund' => $r->chance(0.5), 'reason' => 'fuzz'], []],
                    ['POST', '/api/admin/bookings/' . $this->pid('bookings') . '/status', [], ['status' => $r->pick(['completed', 'no_show', 'confirmed', 'cancelled'])], []],
                    ['POST', '/api/admin/grants/' . $this->pid('oauth_grants') . '/revoke', [], null, []],
                    ['POST', '/api/admin/calendar/connect', [], null, []], ['POST', '/api/admin/calendar/disconnect', [], null, []],
                    ['POST', '/api/admin/connector/pairing', [], null, []], ['POST', '/api/admin/connector/pairing/close', [], null, []],
                ]);
            case 'cron':
                return ['POST', '/api/cron/sync', [], null, $r->pick([['x-cron-token' => 'cron-secret-token'], ['x-cron-token' => 'wrong'], [], ['x-cron-token' => ''], ['x-cron-token' => 'cron-secret-token ']])];
            case 'callback':
                $st = (string) ($this->actors[$role === 'none' ? 'anon' : $role]->session()->get('google_state') ?? 'none');
                return ['GET', '/api/admin/calendar/callback', $r->pick([['state' => $st, 'code' => 'abc'], ['state' => $st], ['state' => 'x', 'code' => 'abc'], ['error' => 'access_denied', 'state' => $st], []]), null, []];
            default:
                $path = $r->pick(['/api/me/balance/', '/API/me/balance', '/api//me/balance', '/api/me/../admin/clients', '/api/admin/clients/', '/api', '/api/', '/api/nope', '/api/admin', '/api/me/bookings/x/cancel', '/api/me%2Fbalance']);
                return [$r->pick(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD', 'TRACE', 'get', '']), $path, [], null, []];
        }
    }

    private function mutateRequest(array $spec): array
    {
        [$method, $path, $query, $body, $headers] = $spec;
        $how = $this->rng->weighted(['none' => 38, 'body' => 36, 'query' => 14, 'both' => 6, 'raw' => 6]);
        $raw = null;
        if (in_array($how, ['body', 'both'], true) && is_array($body)) {
            $body = Hostile::finish(Hostile::mutate($this->rng, $body));
        } elseif (in_array($how, ['body', 'both'], true) && $body === null && $method !== 'GET') {
            $body = Hostile::finish(Hostile::mutate($this->rng, ['a' => 1]));
        }
        if (in_array($how, ['query', 'both'], true) && $query !== []) {
            foreach ($query as $k => $v) {
                if ($this->rng->chance(0.6)) {
                    $query[$k] = Hostile::string($this->rng);
                }
            }
            if ($this->rng->chance(0.3)) {
                $query[Hostile::key($this->rng)] = Hostile::string($this->rng);
            }
        }
        if ($how === 'raw') {
            $raw = Hostile::brokenBody($this->rng);
        }
        $b = $raw ?? ($body === null ? '' : Json::encode($body));
        return [$method, $path, $query, $b, $headers];
    }

    public function testEveryEndpointWithHostileInput(): void
    {
        $others = array_merge(array_map(static fn($c) => $c['id'], $this->w->clients));
        for ($i = 0; $i < $this->iterations; $i++) {
            $this->caseNo = $i;
            $this->w->advance($this->rng->weighted(['0' => 40, '5' => 30, '120' => 30]) === '0' ? 0 : (int) $this->rng->pick([5, 120]));
            $actorName = $this->rng->weighted(['anon' => 10, 'clientA' => 28, 'clientB' => 22, 'owner' => 40]);
            // keep each actor signed in most of the time (a successful logout/login mutation may have changed them)
            $cur = $this->actors[$actorName]->auth()->currentUser();
            if ($actorName === 'clientA' && ($cur === null || $cur['id'] !== $this->w->clients[0]['id'])) {
                $this->actors[$actorName] = $this->w->clientSession(0, $this->ip());
            } elseif ($actorName === 'clientB' && ($cur === null || $cur['id'] !== $this->w->clients[1]['id'])) {
                $this->actors[$actorName] = $this->w->clientSession(1, $this->ip());
            } elseif ($actorName === 'owner' && ($cur === null || ($cur['role'] ?? '') !== 'owner')) {
                $this->actors[$actorName] = $this->w->ownerSession($this->ip());
            }
            $app = $this->actors[$actorName];
            $user = $app->auth()->currentUser();
            $role = $user === null ? 'anon' : (string) $user['role'];
            $uid = $user['id'] ?? null;

            [$method, $path, $query, $body, $extra] = $this->mutateRequest($this->build($actorName));
            // CSRF handling
            $csrfMode = $this->rng->weighted(['valid' => 80, 'missing' => 7, 'wrong' => 5, 'empty' => 3, 'foreign' => 5]);
            $headers = $extra + ['content-type' => $this->rng->pick(['application/json', 'application/json', 'application/json; charset=utf-8', 'text/plain', ''])];
            $token = $app->auth()->csrfToken();
            switch ($csrfMode) {
                case 'valid':
                    $headers['x-csrf-token'] = $token;
                    break;
                case 'wrong':
                    $headers['x-csrf-token'] = $this->rng->pick([$token . 'x', substr($token, 1), strtoupper($token), Hostile::string($this->rng)]);
                    break;
                case 'empty':
                    $headers['x-csrf-token'] = '';
                    break;
                case 'foreign':
                    $headers['x-csrf-token'] = $this->actors[$actorName === 'owner' ? 'clientA' : 'owner']->auth()->csrfToken();
                    break;
            }
            $csrfOk = $csrfMode === 'valid';
            $isCron = $path === '/api/cron/sync';
            $this->describe($method, $path, $body, $headers, $query);
            $this->inFlight .= " as=$role($actorName) csrf=$csrfMode";

            $before = ['sig' => Invariants::stateSig($this->w->app), 'bal' => Invariants::balances($this->w->app),
                'bk' => $this->w->app->db()->all('SELECT id, user_id, start_at, status, refunded FROM bookings ORDER BY id'), 'users' => (int) $this->w->app->db()->val('SELECT COUNT(*) FROM users')];
            $req = new HttpRequest($method, $path, array_change_key_case($headers, CASE_LOWER), $query, $body, [], $this->ip());
            $res = (new Kernel($app))->handle($req);
            $this->verify($res, $method, $path, $role, $uid, $csrfOk, $isCron, $before, $others);
            $this->tally($actorName . ':' . intdiv($res->status, 100) . 'xx');
            $this->endCase();
        }
    }

    /** @param array<string,mixed> $before @param list<string> $clientIds */
    private function verify(HttpResponse $res, string $method, string $path, string $role, ?string $uid, bool $csrfOk, bool $isCron, array $before, array $clientIds): void
    {
        $this->ensure($res->status < 500, "HTTP {$res->status}: " . substr($res->body, 0, 300));
        $this->assertNoLeak($res->body, 'API response');
        foreach ($res->headers as $k => $v) {
            $this->ensure(preg_match('/[\r\n]/', $k . $v) !== 1, "header $k contains a line break");
        }
        $this->ensure(mb_check_encoding($res->body, 'UTF-8'), 'response is not valid UTF-8');
        $this->ensure(isset($res->headers['Content-Security-Policy'], $res->headers['X-Content-Type-Options']), 'security headers missing');
        $isRedirect = isset($res->headers['Location']);
        if ($isRedirect) {
            $this->ensure(str_starts_with($res->headers['Location'], 'http://localhost:8090/#/admin/calendar'), 'unexpected redirect: ' . $res->headers['Location']);
        } elseif ($res->body !== '' && $res->status !== 204) {
            $this->ensure(json_decode($res->body) !== null || $res->body === 'null', 'body is not JSON: ' . substr($res->body, 0, 200));
        }
        $changed = Invariants::stateSig($this->w->app) !== $before['sig'];
        $upper = strtoupper($method);
        $writes = !in_array($upper, ['GET', 'HEAD', 'OPTIONS'], true);
        $preflight = $upper === 'OPTIONS'; // answers 204 with an Allow header for everybody; no data
        $matched = !in_array($res->status, [404, 405], true);
        $ok = $res->status >= 200 && $res->status < 300;

        // CSRF: a state-changing request that reached a route without a valid token must be refused and change nothing
        if ($writes && !$isCron && !$csrfOk) {
            $this->ensure(!$ok, "a write succeeded without a valid CSRF token (status {$res->status})");
            $this->ensure(!$changed, 'a write without a valid CSRF token changed the database');
        }
        // Authorization
        $isAdmin = str_starts_with(strtolower($path), '/api/admin') && !str_contains($path, '..');
        if (str_starts_with($path, '/api/admin') && $role !== 'owner' && $path !== '/api/admin/calendar/callback' && !$preflight) {
            $this->ensure(!$ok, "a non-owner ($role) got a {$res->status} from $path");
            $this->ensure(!$changed, "a non-owner ($role) changed the database through $path");
        }
        if (str_starts_with($path, '/api/me/') && $role === 'anon' && !$preflight) {
            $this->ensure(!$ok, 'an anonymous visitor reached ' . $path);
        }
        if ($role === 'client') {
            // only the client's own balance and bookings may move
            $after = Invariants::balances($this->w->app);
            foreach ($before['bal'] as $id => $b) {
                if ($id !== $uid) {
                    $this->ensure(($after[$id] ?? $b) === $b, "client $uid changed the balance of $id");
                }
            }
            $beforeBk = array_column($before['bk'], null, 'id');
            foreach ($this->w->app->db()->all('SELECT id, user_id, start_at, status, refunded FROM bookings') as $row) {
                if (($beforeBk[$row['id']] ?? null) !== $row) {
                    $this->ensure($row['user_id'] === $uid, "client $uid created or changed a booking of {$row['user_id']}");
                }
            }
            // no other client's id/email in what this client reads
            foreach ($this->w->clients as $c) {
                if ($c['id'] !== $uid && !str_starts_with($path, '/api/auth/')) { // login echoes the account whose password the caller typed
                    $this->ensure(!str_contains($res->body, $c['email']) && !str_contains($res->body, $c['id']) || str_contains($path, $c['id']), "client $uid saw data of {$c['email']}");
                }
            }
            $this->ensure(!str_contains($res->body, $this->w->owner['email']), 'client saw the owner email');
        }
        if ($role !== 'owner' && $ok) {
            $this->ensure(!str_contains($res->body, 'totpEnabled') || $role === 'owner', 'totp info shown to a non-owner');
        }
        // secrets never in any response
        $this->ensure(!str_contains($res->body, (string) $this->w->owner['secret']), 'TOTP secret in a response');
        $this->ensure(!preg_match('/"password_hash"|"passwordHash"|"totp_secret/i', $res->body), 'password hash or TOTP secret field in a response');

        $v = Invariants::all($this->w->app);
        $v = array_merge($v, $this->packInvariants());
        $this->ensure($v === [], 'database invariant broken: ' . implode('; ', $v));
    }

    /** @return list<string> */
    private function packInvariants(): array
    {
        $db = $this->w->app->db();
        $v = [];
        $dupe = $db->val('SELECT COUNT(*) FROM (SELECT UPPER(payment_reference) FROM pack_requests WHERE payment_reference IS NOT NULL GROUP BY 1 HAVING COUNT(*) > 1)');
        if ((int) $dupe > 0) {
            $v[] = 'a payment reference was used on two packs';
        }
        $paid = (int) $db->val("SELECT COUNT(*) FROM pack_requests WHERE status = 'paid'");
        $credited = (int) $db->val("SELECT COUNT(*) FROM credit_ledger WHERE reason = 'purchase' AND note LIKE 'Pack: %'");
        if ($paid !== $credited) {
            $v[] = "$paid packs are paid but $credited pack purchase rows exist";
        }
        $sumPaid = (int) $db->val("SELECT COALESCE(SUM(sessions),0) FROM pack_requests WHERE status = 'paid'");
        $sumLedger = (int) $db->val("SELECT COALESCE(SUM(delta),0) FROM credit_ledger WHERE reason = 'purchase' AND note LIKE 'Pack: %'");
        if ($sumPaid !== $sumLedger) {
            $v[] = "paid pack sessions ($sumPaid) differ from ledger purchases ($sumLedger)";
        }
        return $v;
    }

    /** Hostile PATCH /api/admin/settings, then availability and a booking on whatever settings result. */
    public function testSettingsPatchAndAvailabilityOnHostileSettings(): void
    {
        $owner = $this->actors['owner'];
        $n = min($this->iterations, 800);
        for ($i = 0; $i < $n; $i++) {
            $this->caseNo = $i;
            $this->w->advance(5);
            $valid = ['sessionMinutes' => 60, 'bufferMinutes' => 15, 'slotStepMinutes' => 30, 'bookingWindowDays' => 28, 'minNoticeHours' => 12, 'cancelFreeHours' => 24,
                'workingHours' => array_map(static fn($d) => ['weekday' => $d, 'open' => $d !== 0, 'start' => '06:00', 'end' => '19:00'], range(0, 6))];
            $patch = Hostile::finish(Hostile::mutate($this->rng, $this->rng->chance(0.5) ? ['workingHours' => $valid['workingHours']] : array_intersect_key($valid, array_flip([$this->rng->pick(array_keys($valid))]))));
            if ($this->rng->chance(0.3) && is_array($patch) && isset($patch['workingHours']) && is_array($patch['workingHours'])) {
                $patch['workingHours'][$this->rng->int(0, 6)] = Hostile::mutate($this->rng, ['weekday' => 2, 'open' => true, 'start' => '06:00', 'end' => '19:00']);
            }
            $body = Json::encode($patch);
            $this->describe('PATCH', '/api/admin/settings', $body);
            $res = (new Kernel($owner))->handle(new HttpRequest('PATCH', '/api/admin/settings', ['x-csrf-token' => $owner->auth()->csrfToken(), 'content-type' => 'application/json'], [], $body, [], $this->ip()));
            $this->ensure($res->status < 500, "PATCH settings: HTTP {$res->status} " . substr($res->body, 0, 300));
            $this->assertNoLeak($res->body, 'settings response');
            // whatever was stored, the public endpoints must still work and slots must stay inside the (new) hours
            foreach ([['/api/settings', []], ['/api/availability', ['from' => $this->day(0), 'to' => $this->day(30)]]] as [$p, $q]) {
                $r = (new Kernel($this->fresh()))->handle(new HttpRequest('GET', $p, [], $q, '', [], $this->ip()));
                $this->ensure($r->status === 200, "GET $p after PATCH settings: {$r->status} " . substr($r->body, 0, 300));
            }
            $s = $this->fresh()->settings()->get();
            $this->ensure($s['sessionMinutes'] >= 15 && $s['sessionMinutes'] <= 240 && $s['slotStepMinutes'] >= 5 && $s['bufferMinutes'] >= 0 && $s['bookingWindowDays'] >= 1, 'settings left their allowed ranges: ' . json_encode($s));
            $slots = json_decode((new Kernel($this->fresh()))->handle(new HttpRequest('GET', '/api/availability', [], ['from' => $this->day(0), 'to' => $this->day(30)], '', [], $this->ip()))->body, true);
            foreach ($slots as $sl) {
                $a = new \DateTimeImmutable($sl['start']);
                $e = new \DateTimeImmutable($sl['end']);
                $this->ensure(($e->getTimestamp() - $a->getTimestamp()) === $s['sessionMinutes'] * 60, 'slot length differs from sessionMinutes');
                $loc = $a->setTimezone(new \DateTimeZone('Africa/Johannesburg'));
                $locEnd = $e->setTimezone(new \DateTimeZone('Africa/Johannesburg'));
                $wh = $s['workingHours'][(int) $loc->format('w')];
                $this->ensure($wh['open'] && $loc->format('H:i') >= $wh['start'] && ($locEnd->format('H:i') <= $wh['end'] || $locEnd->format('H:i') === '00:00') && $loc->format('Y-m-d') === $locEnd->format('Y-m-d'), 'slot outside the stored working hours: ' . json_encode($sl) . ' ' . json_encode($wh));
            }
            $this->tally(intdiv($res->status, 100) . 'xx');
            $this->endCase();
        }
    }
}
