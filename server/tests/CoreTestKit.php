<?php
declare(strict_types=1);

namespace StretchEffect\Tests;

use StretchEffect\App;
use StretchEffect\Contracts\HttpRequest;
use StretchEffect\Http\Kernel;
use StretchEffect\Mail\NullMailer;
use StretchEffect\Services\AuthService;
use StretchEffect\Services\Totp;
use StretchEffect\Session\ArraySession;
use StretchEffect\Support\Time;

/** Shared helpers for server tests (also usable from the OAuth and Mcp tests). */
final class CoreTestKit
{
    /** Sunday 10:00 in South Africa. Monday 2026-11-09 is the first bookable day (12 h notice). */
    public const NOW = '2026-11-08T08:00:00Z';

    /** @param array<string,mixed> $override @return array<string,mixed> */
    public static function config(array $override = []): array
    {
        return array_replace_recursive([
            'app' => ['base_url' => 'http://localhost:8090', 'env' => 'dev', 'key' => base64_encode(str_repeat('k', 32))],
            'db' => ['dsn' => 'sqlite::memory:'],
            'google' => ['client_id' => 'test-client', 'client_secret' => 'test-secret', 'calendar_id' => 'ivan@example.com'],
            'mail' => ['from' => 'Test <test@example.com>'],
            'cron' => ['token' => 'cron-secret-token-0123456789-abcdefghij'],
        ], $override);
    }

    /**
     * Exact factory call for other agents' tests:
     *   $app = \StretchEffect\Tests\CoreTestKit::app();   // migrated in-memory SQLite, frozen clock, ArraySession, NullMailer
     * @param array<string,mixed> $configOverride @param array<string,mixed> $options
     */
    public static function app(array $configOverride = [], array $options = []): App
    {
        $options += ['now' => self::NOW, 'session' => new ArraySession(), 'mailer' => new NullMailer(), 'varDir' => sys_get_temp_dir() . '/se-test-var'];
        $app = App::fromConfig(self::config($configOverride), $options);
        self::migrate($app);
        return $app;
    }

    public static function migrate(App $app): void
    {
        $dir = dirname(__DIR__) . '/migrations/' . $app->db()->driver();
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
            foreach (preg_split('/;\s*(?:\r?\n|$)/', (string) $sql) ?: [] as $stmt) {
                if (trim($stmt) !== '') {
                    $app->db()->pdo()->exec($stmt);
                }
            }
        }
    }

    /** @return array<string,mixed> user row incl. 'password' */
    public static function client(App $app, string $email = 'client@example.com', int $credits = 0, string $name = 'Test Client', string $password = 'correct-horse-battery'): array
    {
        $id = $app->db()->newId();
        $app->db()->run("INSERT INTO users (id, role, name, email, phone, password_hash, totp_enabled, created_at) VALUES (?, 'client', ?, ?, NULL, ?, 0, ?)",
            [$id, $name, $email, AuthService::hashPassword($password), Time::toDb($app->db()->now())]);
        if ($credits > 0) {
            $app->credits()->adjust($id, $credits, 'purchase', 'test', 'test');
        }
        return ['id' => $id, 'email' => $email, 'name' => $name, 'password' => $password];
    }

    /** @return array<string,mixed> incl. 'secret' (base32 TOTP) and 'password' */
    public static function owner(App $app, string $email = 'owner@example.com', string $password = 'owner-password-123'): array
    {
        $id = $app->db()->newId();
        $secret = Totp::generateSecret();
        $app->db()->run("INSERT INTO users (id, role, name, email, phone, password_hash, totp_secret_enc, totp_enabled, created_at) VALUES (?, 'owner', 'Test Owner', ?, NULL, ?, ?, 1, ?)",
            [$id, $email, AuthService::hashPassword($password), $app->crypto()->encrypt($secret), Time::toDb($app->db()->now())]);
        return ['id' => $id, 'email' => $email, 'secret' => $secret, 'password' => $password];
    }

    /** @param array<string,mixed>|null $body @param array<string,string> $query @param array<string,string> $headers */
    public static function request(string $method, string $path, ?array $body = null, array $query = [], array $headers = [], string $ip = '10.0.0.1'): HttpRequest
    {
        return new HttpRequest($method, $path, array_change_key_case($headers, CASE_LOWER), $query,
            $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR), [], $ip);
    }

    /**
     * Run a request through the real Kernel (security headers, error mapping).
     * Non-GET requests automatically carry this browser's CSRF token unless $csrf is false.
     * @param array<string,mixed>|null $body @param array<string,string> $query
     * @return array{status:int, json:mixed, body:string, headers:array<string,string>}
     */
    public static function call(App $app, string $method, string $path, ?array $body = null, array $query = [], bool $csrf = true, array $headers = [], string $ip = '10.0.0.1'): array
    {
        if ($csrf && $method !== 'GET') {
            $headers['X-CSRF-Token'] = $app->auth()->csrfToken();
        }
        $res = (new Kernel($app))->handle(self::request($method, $path, $body, $query, $headers, $ip));
        return ['status' => $res->status, 'json' => json_decode($res->body, true), 'body' => $res->body, 'headers' => $res->headers];
    }

    /** Complete an owner login (password + TOTP) in this browser. */
    public static function loginOwner(App $app, array $owner): void
    {
        $r = self::call($app, 'POST', '/api/auth/login', ['email' => $owner['email'], 'password' => $owner['password']]);
        if (($r['json']['needsTotp'] ?? false) !== true) {
            throw new \RuntimeException('Owner login did not ask for TOTP: ' . $r['body']);
        }
        $code = Totp::code($owner['secret'], Totp::step($app->db()->now()->getTimestamp()));
        $r = self::call($app, 'POST', '/api/auth/totp', ['code' => $code]);
        if ($r['status'] !== 200) {
            throw new \RuntimeException('TOTP failed: ' . $r['body']);
        }
    }

    public static function loginClient(App $app, array $client): void
    {
        $r = self::call($app, 'POST', '/api/auth/login', ['email' => $client['email'], 'password' => $client['password']]);
        if ($r['status'] !== 200) {
            throw new \RuntimeException('Client login failed: ' . $r['body']);
        }
    }
}
