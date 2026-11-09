<?php
declare(strict_types=1);

namespace StretchEffect\Api;

use StretchEffect\App;
use StretchEffect\Contracts\DomainError;
use StretchEffect\Contracts\HttpRequest;
use StretchEffect\Contracts\HttpResponse;
use StretchEffect\Contracts\RouteHandler;
use StretchEffect\Contracts\Services;
use StretchEffect\OAuth\Pairing;
use StretchEffect\Services\Totp;
use StretchEffect\Support\Presenter;
use StretchEffect\Support\Time;

/** The /api/* endpoints. See server/README.md for the list. */
final class Router implements RouteHandler
{
    private HttpRequest $req;

    public function __construct(private readonly App $app)
    {
    }

    /** @return list<array{0:string,1:string,2:string}> */
    private function routes(): array
    {
        return [
            ['GET', '/api/csrf', 'csrf'],
            ['GET', '/api/me', 'me'],
            ['POST', '/api/auth/signup', 'signup'],
            ['POST', '/api/auth/login', 'login'],
            ['POST', '/api/auth/totp', 'totp'],
            ['POST', '/api/auth/logout', 'logout'],
            ['GET', '/api/packages', 'packages'],
            ['GET', '/api/settings', 'settings'],
            ['GET', '/api/availability', 'availability'],
            ['GET', '/api/me/balance', 'myBalance'],
            ['GET', '/api/me/ledger', 'myLedger'],
            ['GET', '/api/me/bookings', 'myBookings'],
            ['GET', '/api/me/packs', 'myPacks'],
            ['POST', '/api/me/packs', 'requestPack'],
            ['POST', '/api/me/bookings', 'book'],
            ['POST', '#^/api/me/bookings/([0-9a-f]{16})/cancel$#', 'cancelMine'],
            ['GET', '/api/admin/clients', 'adminClients'],
            ['GET', '#^/api/admin/clients/([0-9a-f]{16})$#', 'adminClient'],
            ['GET', '/api/admin/bookings', 'adminBookings'],
            ['GET', '/api/admin/busy', 'adminBusy'],
            ['POST', '#^/api/admin/clients/([0-9a-f]{16})/credits$#', 'adminCredits'],
            ['POST', '#^/api/admin/packs/([0-9a-f]{16})/paid$#', 'adminPackPaid'],
            ['POST', '/api/admin/bookings', 'adminBook'],
            ['POST', '#^/api/admin/bookings/([0-9a-f]{16})/cancel$#', 'adminCancel'],
            ['POST', '#^/api/admin/bookings/([0-9a-f]{16})/status$#', 'adminStatus'],
            ['PATCH', '/api/admin/settings', 'adminSettings'],
            ['GET', '/api/admin/calendar', 'adminCalendar'],
            ['POST', '/api/admin/calendar/connect', 'adminConnect'],
            ['GET', '/api/admin/calendar/callback', 'adminCallback'],
            ['POST', '/api/admin/calendar/disconnect', 'adminDisconnect'],
            ['GET', '/api/admin/summary', 'adminSummary'],
            ['GET', '/api/admin/grants', 'adminGrants'],
            ['POST', '#^/api/admin/grants/([0-9a-f]{16})/revoke$#', 'adminRevoke'],
            ['GET', '/api/admin/audit', 'adminAudit'],
            ['GET', '/api/admin/connector/pairing', 'adminPairing'],
            ['POST', '/api/admin/connector/pairing', 'adminPairingOpen'],
            ['POST', '/api/admin/connector/pairing/close', 'adminPairingClose'],
            ['POST', '/api/cron/sync', 'cronSync'],
        ];
    }

    public function handle(HttpRequest $request, Services $services): ?HttpResponse
    {
        $this->req = $request;
        $path = rtrim($request->path, '/');
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        if ($method === 'OPTIONS') {
            return new HttpResponse(204, ['Allow' => 'GET, POST, PATCH, OPTIONS']);
        }
        foreach ($this->routes() as [$m, $pattern, $handler]) {
            $args = [];
            if ($pattern[0] === '#') {
                if (!preg_match($pattern, $path, $mm)) {
                    continue;
                }
                $args = array_slice($mm, 1);
            } elseif ($pattern !== $path) {
                continue;
            }
            if ($m !== $method) {
                continue;
            }
            if ($method !== 'GET' && $handler !== 'cronSync' && !$this->app->auth()->checkCsrf($request->header('x-csrf-token'))) {
                throw new DomainError('forbidden', 'Missing or invalid CSRF token. Reload the page and try again.');
            }
            return $this->{$handler}(...$args);
        }
        throw new DomainError('not_found', 'Not found');
    }

    // ---------- helpers ----------

    private function ok(mixed $data, int $status = 200): HttpResponse
    {
        return HttpResponse::json($data, $status);
    }

    /** @return array<string,mixed> */
    private function body(): array
    {
        if ($this->req->rawBody === '') {
            return [];
        }
        $d = $this->req->json();
        if ($d === null || ($d !== [] && array_is_list($d))) {
            throw new DomainError('validation', 'Request body must be a JSON object');
        }
        return $d;
    }

    private function in(): Input
    {
        return new Input($this->body());
    }

    private function q(): Input
    {
        return new Input($this->req->query);
    }

    /** @return array<string,mixed> */
    private function user(): array
    {
        $u = $this->app->auth()->currentUser();
        if ($u === null) {
            throw new DomainError('unauthorized', 'Please sign in.');
        }
        return $u;
    }

    /** @return array<string,mixed> */
    private function owner(): array
    {
        $u = $this->user(); // currentUser() is only set after TOTP for owners
        if ($u['role'] !== 'owner') {
            throw new DomainError('forbidden', 'Owner access only.');
        }
        return $u;
    }

    /** @return array{0:string,1:string} */
    private function dateRange(Input $in, int $maxDays = 31): array
    {
        $from = $in->date('from');
        $to = $in->date('to');
        $days = Input::daysBetween($from, $to);
        if ($days < 1 || $days > $maxDays) {
            throw new DomainError('validation', "Date range must be 1 to $maxDays days");
        }
        return [$from, $to];
    }

    private function adminAudit_(string $actor, string $action, array $args, string $summary): void
    {
        $this->app->audit()->write(null, $actor, $action, $args, 'ok', $summary, $this->req->ip);
    }

    // ---------- public / auth ----------

    private function csrf(): HttpResponse
    {
        if (!$this->app->rateLimiter()->hit('csrf_ip', $this->req->ip, 30, 60)) {
            throw new DomainError('rate_limited', 'Too many requests. Please wait a moment and try again.');
        }
        return $this->ok(['token' => $this->app->auth()->csrfToken()]);
    }

    private function me(): HttpResponse
    {
        $u = $this->app->auth()->currentUser();
        return $this->ok(['user' => $u === null ? null : Presenter::user($u)]);
    }

    private function signup(): HttpResponse
    {
        $in = $this->in();
        $db = $this->app->db();
        if (!$this->app->rateLimiter()->hit('signup_ip', $this->req->ip, 10, 3600)) {
            throw new DomainError('rate_limited', 'Too many sign-ups from this address. Please try again later.');
        }
        $name = $in->str('name', 1, 80);
        // Letters (any script), spaces, apostrophe, hyphen, full stop. No newlines, digits, symbols or markup: names are shown to
        // the owner's ChatGPT, so free-form text here would be a prompt-injection channel.
        if (preg_match('/^\p{L}[\p{L}\p{M} \'\x{2019}.\-]*\z/u', (string) $name) !== 1) {
            throw new DomainError('validation', "name may only contain letters, spaces and ' - .");
        }
        $email = $in->email();
        $phone = $in->str('phone', 5, 40, false);
        if ($phone !== null && !preg_match('/^[0-9 +()\-]{5,40}$/', $phone)) {
            throw new DomainError('validation', 'phone may only contain digits, spaces and + - ( )');
        }
        $password = $in->secret('password', 10);
        if ($in->bool('consent') !== true) {
            throw new DomainError('validation', 'Please accept the privacy terms to create an account.');
        }
        if ($db->val('SELECT 1 FROM users WHERE email = ?', [$email]) !== null) {
            $this->app->auth()::hashPassword($password); // same work as a real sign-up, so timing does not reveal the account
            throw new DomainError('validation', 'We could not create that account. If you already have one, please sign in.');
        }
        $id = $db->newId();
        try {
            $db->run(
                "INSERT INTO users (id, role, name, email, phone, password_hash, totp_enabled, created_at) VALUES (?, 'client', ?, ?, ?, ?, 0, ?)",
                [$id, $name, $email, $phone, $this->app->auth()::hashPassword($password), Time::toDb($db->now())]
            );
        } catch (\PDOException) {
            throw new DomainError('validation', 'We could not create that account. If you already have one, please sign in.');
        }
        $this->app->auth()->startSession($id);
        $this->adminAudit_($id, 'auth.signup', ['userId' => $id], 'Client signed up');
        return $this->ok(['user' => Presenter::user($this->app->auth()->userById($id) ?? [])], 201);
    }

    private function login(): HttpResponse
    {
        $in = $this->in();
        $email = $in->email(); // same validator as signup: ASCII only, so look-alike spellings never reach the limiters
        $password = $in->secret('password', 1);
        $auth = $this->app->auth();
        $user = $auth->verifyPassword($email, $password, $this->req->ip);
        if ($user === null) {
            throw new DomainError('unauthorized', 'Incorrect email or password.');
        }
        if ($user['role'] === 'owner') {
            if (!(int) $user['totp_enabled']) {
                throw new DomainError('unauthorized', 'Incorrect email or password.');
            }
            $auth->beginTotp((string) $user['id']);
            return $this->ok(['needsTotp' => true]);
        }
        $auth->startSession((string) $user['id']);
        return $this->ok(['user' => Presenter::user($user)]);
    }

    private function totp(): HttpResponse
    {
        $code = $this->in()->str('code', 6, 8);
        $user = $this->app->auth()->completeTotp((string) $code, $this->req->ip);
        if ($user === null) {
            throw new DomainError('unauthorized', 'That code is not valid. Try the next code from your authenticator app.');
        }
        $this->adminAudit_((string) $user['id'], 'auth.owner_login', [], 'Owner signed in');
        return $this->ok(['user' => Presenter::user($user)]);
    }

    private function logout(): HttpResponse
    {
        $this->app->auth()->logout();
        return $this->ok(['ok' => true]);
    }

    private function packages(): HttpResponse
    {
        return $this->ok($this->app->credits()->packages());
    }

    private function settings(): HttpResponse
    {
        return $this->ok($this->app->settings()->get());
    }

    private function availability(): HttpResponse
    {
        [$from, $to] = $this->dateRange($this->q());
        $s = $this->app->settings()->get();
        $this->app->google()->syncIfStale(120, (int) $s['bookingWindowDays']);
        return $this->ok($this->app->bookings()->availability($from, $to));
    }

    // ---------- client ----------

    private function myBalance(): HttpResponse
    {
        return $this->ok(['balance' => $this->app->credits()->balance((string) $this->user()['id'])]);
    }

    private function myLedger(): HttpResponse
    {
        return $this->ok($this->app->credits()->ledger((string) $this->user()['id']));
    }

    private function myBookings(): HttpResponse
    {
        return $this->ok($this->app->bookings()->forUser((string) $this->user()['id']));
    }

    private function myPacks(): HttpResponse
    {
        return $this->ok($this->app->credits()->packs((string) $this->user()['id']));
    }

    private function requestPack(): HttpResponse
    {
        $u = $this->user();
        $pack = $this->app->credits()->requestPack((string) $u['id'], (string) $this->in()->str('packageId', 1, 20));
        return $this->ok($pack, 201);
    }

    private function book(): HttpResponse
    {
        $u = $this->user();
        $start = $this->in()->instant('start');
        if (!$this->app->rateLimiter()->hit('book_user', (string) $u['id'], 30, 3600)) {
            throw new DomainError('rate_limited', 'Too many booking attempts. Please try again later.');
        }
        return $this->ok($this->app->bookings()->create((string) $u['id'], $start, 'client', (string) $u['id']), 201);
    }

    private function cancelMine(string $id): HttpResponse
    {
        $u = $this->user();
        $b = $this->app->bookings()->get($id);
        if ($b === null || $b['userId'] !== $u['id']) {
            throw new DomainError('not_found', 'Booking not found');
        }
        return $this->ok($this->app->bookings()->cancel($id, null, 'Cancelled by client', (string) $u['id']));
    }

    // ---------- owner ----------

    private function adminClients(): HttpResponse
    {
        $this->owner();
        $q = $this->q()->str('q', 1, 100, false) ?? '';
        return $this->ok($this->app->clients()->search($q, 100));
    }

    private function adminClient(string $id): HttpResponse
    {
        $this->owner();
        $c = $this->app->clients()->get($id);
        if ($c === null) {
            throw new DomainError('not_found', 'Client not found');
        }
        return $this->ok($c);
    }

    private function adminBookings(): HttpResponse
    {
        $this->owner();
        [$from, $to] = $this->dateRange($this->q(), 93);
        return $this->ok($this->app->bookings()->list($from, $to));
    }

    private function adminBusy(): HttpResponse
    {
        $this->owner();
        [$from, $to] = $this->dateRange($this->q(), 93);
        $this->app->google()->syncIfStale(120, (int) $this->app->settings()->get()['bookingWindowDays']);
        return $this->ok($this->app->bookings()->busyBlocks($from, $to));
    }

    private function adminCredits(string $id): HttpResponse
    {
        $o = $this->owner();
        $in = $this->in();
        $delta = (int) $in->int('delta', -100, 100);
        if ($delta === 0) {
            throw new DomainError('validation', 'delta cannot be zero');
        }
        $note = (string) $in->str('note', 1, 255);
        if ($this->app->clients()->get($id) === null) {
            throw new DomainError('not_found', 'Client not found');
        }
        $entry = $this->app->credits()->adjust($id, $delta, 'owner_adjust', $note, (string) $o['id']);
        $this->adminAudit_((string) $o['id'], 'credits.adjust', ['clientId' => $id, 'delta' => $delta], 'Credits adjusted by ' . $delta);
        return $this->ok($entry, 201);
    }

    private function adminPackPaid(string $id): HttpResponse
    {
        $o = $this->owner();
        $ref = $this->in()->str('paymentReference', 1, 80, false);
        $pack = $this->app->credits()->markPackPaid($id, $ref, (string) $o['id']);
        $this->adminAudit_((string) $o['id'], 'pack.paid', ['packId' => $id], 'Pack marked paid');
        return $this->ok($pack);
    }

    private function adminBook(): HttpResponse
    {
        $o = $this->owner();
        $in = $this->in();
        $b = $this->app->bookings()->create((string) $in->str('userId', 16, 16), $in->instant('start'), 'owner', (string) $o['id']);
        return $this->ok($b, 201);
    }

    private function adminCancel(string $id): HttpResponse
    {
        $o = $this->owner();
        $in = $this->in();
        $refund = $in->bool('refund');
        $reason = $in->str('reason', 1, 255, false) ?? 'Cancelled by owner';
        $r = $this->app->bookings()->cancel($id, $refund, $reason, (string) $o['id']);
        return $this->ok($r['booking']);
    }

    private function adminStatus(string $id): HttpResponse
    {
        $o = $this->owner();
        $status = $this->in()->enum('status', ['completed', 'no_show']);
        return $this->ok($this->app->bookings()->setStatus($id, $status, (string) $o['id']));
    }

    private function adminSettings(): HttpResponse
    {
        $o = $this->owner();
        $patch = $this->body();
        if ($patch === []) {
            throw new DomainError('validation', 'Nothing to update');
        }
        $s = $this->app->settings()->update($patch);
        $this->adminAudit_((string) $o['id'], 'settings.update', ['keys' => array_keys($patch)], 'Settings updated');
        return $this->ok($s);
    }

    private function adminCalendar(): HttpResponse
    {
        $this->owner();
        return $this->ok($this->app->google()->connection());
    }

    private function adminConnect(): HttpResponse
    {
        $this->owner();
        $state = $this->app->crypto()->randomToken(24);
        $this->app->session()->set('google_state', $state);
        return $this->ok(['redirectUrl' => $this->app->google()->authorizationUrl($state), 'connection' => $this->app->google()->connection()]);
    }

    private function adminCallback(): HttpResponse
    {
        $base = $this->app->config()->baseUrl() . '/#/admin/calendar';
        $u = $this->app->auth()->currentUser();
        $state = $this->app->session()->get('google_state');
        $this->app->session()->remove('google_state');
        $q = $this->req->query;
        if ($u === null || $u['role'] !== 'owner' || !is_string($state) || !isset($q['state']) || !hash_equals($state, (string) $q['state'])) {
            return HttpResponse::redirect($base . '?error=state');
        }
        if (isset($q['error']) || empty($q['code'])) {
            return HttpResponse::redirect($base . '?error=denied');
        }
        try {
            $this->app->google()->handleCallback((string) $q['code']);
        } catch (DomainError) {
            return HttpResponse::redirect($base . '?error=google');
        }
        $this->adminAudit_((string) $u['id'], 'calendar.connect', [], 'Google Calendar connected');
        try {
            $this->app->maintenance()->run();
        } catch (\Throwable) {
        }
        return HttpResponse::redirect($base);
    }

    private function adminDisconnect(): HttpResponse
    {
        $o = $this->owner();
        $this->app->google()->disconnect();
        $this->adminAudit_((string) $o['id'], 'calendar.disconnect', [], 'Google Calendar disconnected');
        return $this->ok($this->app->google()->connection());
    }

    private function adminSummary(): HttpResponse
    {
        $this->owner();
        $period = $this->q()->enum('period', ['week', 'month'], 'week');
        return $this->ok($this->app->bookings()->summary($period));
    }

    private function adminGrants(): HttpResponse
    {
        $this->owner();
        return $this->ok($this->app->grants()->list());
    }

    private function adminRevoke(string $id): HttpResponse
    {
        $o = $this->owner();
        $this->app->grants()->revoke($id);
        $this->adminAudit_((string) $o['id'], 'grant.revoke', ['grantId' => $id], 'Connector grant revoked');
        return $this->ok(['ok' => true]);
    }

    private function adminPairing(): HttpResponse
    {
        $this->owner();
        return $this->ok((new Pairing($this->app))->status());
    }

    private function adminPairingOpen(): HttpResponse
    {
        $o = $this->owner();
        $st = (new Pairing($this->app))->open();
        $this->adminAudit_((string) $o['id'], 'connector_pairing_open', ['until' => $st['until']], 'Connector pairing window opened for ' . Pairing::MINUTES . ' minutes');
        return $this->ok($st);
    }

    private function adminPairingClose(): HttpResponse
    {
        $o = $this->owner();
        $st = (new Pairing($this->app))->close();
        $this->adminAudit_((string) $o['id'], 'connector_pairing_close', [], 'Connector pairing window closed by owner');
        return $this->ok($st);
    }

    private function adminAudit(): HttpResponse
    {
        $this->owner();
        $limit = $this->q()->has('limit') ? (int) $this->req->query['limit'] : 100;
        return $this->ok($this->app->audit()->recent(max(1, min(500, $limit))));
    }

    private function cronSync(): HttpResponse
    {
        $expected = (string) $this->app->config()->get('cron.token', '');
        if (strlen($expected) < 32 || stripos($expected, 'CHANGE_ME') !== false) {
            // A missing, short or placeholder token means the HTTP cron is switched off (CLI cron needs no token).
            return HttpResponse::json(['error' => ['code' => 'unavailable', 'message' => 'The HTTP cron endpoint is not configured.']], 503);
        }
        if (!$this->app->rateLimiter()->hit('cron_ip', $this->req->ip, 10, 60)) {
            throw new DomainError('rate_limited', 'Too many requests.');
        }
        $given = (string) ($this->req->header('x-cron-token') ?? '');
        if (!hash_equals($expected, $given)) {
            throw new DomainError('forbidden', 'Invalid cron token.');
        }
        return $this->ok(['ok' => true] + $this->app->maintenance()->run());
    }
}
