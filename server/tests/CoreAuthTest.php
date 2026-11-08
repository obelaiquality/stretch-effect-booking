<?php
declare(strict_types=1);

namespace StretchEffect\Tests;

use PHPUnit\Framework\TestCase;
use StretchEffect\Contracts\DomainError;
use StretchEffect\Services\Totp;

final class CoreAuthTest extends TestCase
{
    public function testTotpMatchesRfc6238Vector(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        $this->assertSame('287082', Totp::code($secret, Totp::step(59)));
        $this->assertSame('081804', Totp::code($secret, Totp::step(1111111109)));
        $this->assertSame('12345678901234567890', Totp::base32Decode($secret));
    }

    public function testTotpVerifyAndReuseRejection(): void
    {
        $app = CoreTestKit::app();
        $o = CoreTestKit::owner($app);
        $now = $app->db()->now()->getTimestamp();
        $step = Totp::step($now);
        $code = Totp::code($o['secret'], $step);
        $this->assertFalse($app->auth()->verifyTotp($o['id'], '000000'));
        $this->assertTrue($app->auth()->verifyTotp($o['id'], $code));
        $this->assertFalse($app->auth()->verifyTotp($o['id'], $code), 'same code cannot be used twice');
        // The previous step's code is older than the last accepted step.
        $this->assertFalse($app->auth()->verifyTotp($o['id'], Totp::code($o['secret'], $step - 1)));
        // The next step works (clock moves on).
        $app->db()->freeze(new \DateTimeImmutable('@' . ($now + 30)));
        $this->assertTrue($app->auth()->verifyTotp($o['id'], Totp::code($o['secret'], $step + 1)));
    }

    public function testTotpToleratesOneStepOfDrift(): void
    {
        $app = CoreTestKit::app();
        $o = CoreTestKit::owner($app);
        $step = Totp::step($app->db()->now()->getTimestamp());
        $this->assertTrue($app->auth()->verifyTotp($o['id'], Totp::code($o['secret'], $step - 1)));
        $o2 = CoreTestKit::owner($app, 'o2@example.com');
        $this->assertFalse($app->auth()->verifyTotp($o2['id'], Totp::code($o2['secret'], $step + 2)), 'two steps away is rejected');
    }

    public function testLoginRateLimitPerEmail(): void
    {
        $app = CoreTestKit::app();
        CoreTestKit::client($app);
        for ($i = 0; $i < 5; $i++) {
            $this->assertNull($app->auth()->verifyPassword('client@example.com', 'wrong-password', '10.0.0.1'));
        }
        $this->expectException(DomainError::class);
        $this->expectExceptionMessage('Too many');
        $app->auth()->verifyPassword('client@example.com', 'correct-horse-battery', '10.0.0.1');
    }

    public function testLoginRateLimitPerIpAndWindowReset(): void
    {
        $app = CoreTestKit::app();
        for ($i = 0; $i < 20; $i++) {
            $this->assertNull($app->auth()->verifyPassword("u$i@example.com", 'x', '10.9.9.9'));
        }
        try {
            $app->auth()->verifyPassword('another@example.com', 'x', '10.9.9.9');
            $this->fail('expected rate limit');
        } catch (DomainError $e) {
            $this->assertSame('rate_limited', $e->errorCode);
            $this->assertSame(429, $e->httpStatus());
        }
        $app->db()->freeze(new \DateTimeImmutable('@' . ($app->db()->now()->getTimestamp() + 901)));
        $this->assertNull($app->auth()->verifyPassword('another@example.com', 'x', '10.9.9.9'));
    }

    public function testSuccessfulLoginClearsEmailCounter(): void
    {
        $app = CoreTestKit::app();
        $c = CoreTestKit::client($app);
        for ($i = 0; $i < 4; $i++) {
            $app->auth()->verifyPassword($c['email'], 'bad', '10.0.0.1');
        }
        $this->assertNotNull($app->auth()->verifyPassword($c['email'], $c['password'], '10.0.0.1'));
        for ($i = 0; $i < 4; $i++) {
            $this->assertNull($app->auth()->verifyPassword($c['email'], 'bad', '10.0.0.1'));
        }
    }

    public function testPasswordsAreHashedNotStored(): void
    {
        $app = CoreTestKit::app();
        CoreTestKit::client($app);
        $hash = (string) $app->db()->val('SELECT password_hash FROM users');
        $this->assertStringStartsWith('$', $hash);
        $this->assertStringNotContainsString('correct-horse', $hash);
    }

    public function testCsrfRequiredOnStateChangingRequests(): void
    {
        $app = CoreTestKit::app();
        $r = CoreTestKit::call($app, 'POST', '/api/auth/logout', [], csrf: false);
        $this->assertSame(403, $r['status']);
        $this->assertSame('forbidden', $r['json']['error']['code']);
        $r = CoreTestKit::call($app, 'POST', '/api/auth/logout', [], csrf: false, headers: ['X-CSRF-Token' => 'wrong']);
        $this->assertSame(403, $r['status']);
        $token = CoreTestKit::call($app, 'GET', '/api/csrf')['json']['token'];
        $r = CoreTestKit::call($app, 'POST', '/api/auth/logout', [], csrf: false, headers: ['X-CSRF-Token' => $token]);
        $this->assertSame(200, $r['status']);
    }

    public function testOwnerLoginNeedsTotpBeforeAnyAdminAccess(): void
    {
        $app = CoreTestKit::app();
        $o = CoreTestKit::owner($app);
        $r = CoreTestKit::call($app, 'POST', '/api/auth/login', ['email' => $o['email'], 'password' => $o['password']]);
        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['json']['needsTotp']);
        $this->assertArrayNotHasKey('user', $r['json']);
        $this->assertNull(CoreTestKit::call($app, 'GET', '/api/me')['json']['user'], 'not signed in yet');
        $this->assertSame(401, CoreTestKit::call($app, 'GET', '/api/admin/clients')['status']);
        $this->assertSame(401, CoreTestKit::call($app, 'POST', '/api/auth/totp', ['code' => '000000'])['status']);
        $code = Totp::code($o['secret'], Totp::step($app->db()->now()->getTimestamp()));
        $r = CoreTestKit::call($app, 'POST', '/api/auth/totp', ['code' => $code]);
        $this->assertSame(200, $r['status']);
        $this->assertSame('owner', $r['json']['user']['role']);
        $this->assertSame(1, $app->session()->regenerations - 1, 'session id regenerated at password step and at login');
        $this->assertSame(200, CoreTestKit::call($app, 'GET', '/api/admin/clients')['status']);
    }

    public function testOwnerWithoutTotpCannotLogin(): void
    {
        $app = CoreTestKit::app();
        $o = CoreTestKit::owner($app);
        $app->db()->run('UPDATE users SET totp_enabled = 0 WHERE id = ?', [$o['id']]);
        $r = CoreTestKit::call($app, 'POST', '/api/auth/login', ['email' => $o['email'], 'password' => $o['password']]);
        $this->assertSame(401, $r['status']);
    }

    public function testGenericLoginError(): void
    {
        $app = CoreTestKit::app();
        CoreTestKit::client($app);
        $a = CoreTestKit::call($app, 'POST', '/api/auth/login', ['email' => 'client@example.com', 'password' => 'wrong-wrong-wrong']);
        $b = CoreTestKit::call($app, 'POST', '/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong-wrong-wrong']);
        $this->assertSame(401, $a['status']);
        $this->assertSame($a['json'], $b['json'], 'same message for unknown email and wrong password');
    }
}
