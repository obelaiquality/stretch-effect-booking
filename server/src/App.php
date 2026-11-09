<?php
declare(strict_types=1);

namespace StretchEffect;

use DateTimeImmutable;
use StretchEffect\Contracts\Services;
use StretchEffect\Google\CurlTransport;
use StretchEffect\Google\GoogleCalendar;
use StretchEffect\Google\HttpTransport;
use StretchEffect\Mail\Mailer;
use StretchEffect\Mail\NativeMailer;
use StretchEffect\Mail\NullMailer;
use StretchEffect\Mail\Notifier;
use StretchEffect\Mail\SmtpMailer;
use StretchEffect\Services\AuditLog;
use StretchEffect\Services\AuthService;
use StretchEffect\Services\BookingService;
use StretchEffect\Services\ClientService;
use StretchEffect\Services\CreditService;
use StretchEffect\Services\GrantService;
use StretchEffect\Services\Maintenance;
use StretchEffect\Services\RateLimiter;
use StretchEffect\Services\SettingsService;
use StretchEffect\Session\NativeSession;
use StretchEffect\Session\SessionStore;

/**
 * Service container (lazy singletons). Build with App::fromConfig($array, $options).
 * Options (all optional, used by tests/CLI):
 *   'session'   SessionStore   default NativeSession (server/var/sessions)
 *   'transport' HttpTransport  default CurlTransport
 *   'mailer'    Mailer         default by config (NullMailer in dev without SMTP)
 *   'now'       DateTimeImmutable|string  freeze the clock
 *   'varDir'    string         default <server>/var
 *   'db'        Db             reuse an existing connection
 */
final class App implements Services
{
    /** @var array<string,object> */
    private array $instances = [];

    /** @param array<string,mixed> $options */
    public function __construct(private readonly Config $config, private readonly array $options = [])
    {
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $options */
    public static function fromConfig(array $config, array $options = []): self
    {
        $app = new self(new Config($config), $options);
        if (isset($options['now'])) {
            $app->db()->freeze($options['now']);
        }
        return $app;
    }

    public static function fromFile(string $path, array $options = []): self
    {
        $app = new self(Config::fromFile($path), $options);
        if (isset($options['now'])) {
            $app->db()->freeze($options['now']);
        }
        return $app;
    }

    /** @template T of object @param class-string<T> $key @param callable():T $make @return T */
    private function lazy(string $key, callable $make): object
    {
        return $this->instances[$key] ??= $make();
    }

    /** Another "browser" on the same database, clock, mailer and Google transport (tests, multi-user flows). */
    public function withSession(SessionStore $session): self
    {
        return new self($this->config, ['session' => $session, 'db' => $this->db(), 'mailer' => $this->mailer(), 'transport' => $this->transport()] + $this->options);
    }

    public function varDir(): string
    {
        return (string) ($this->options['varDir'] ?? (defined('SE_SERVER_DIR') ? SE_SERVER_DIR : dirname(__DIR__)) . '/var');
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function db(): Db
    {
        return $this->lazy(Db::class, fn () => $this->options['db'] ?? new Db(
            (string) $this->config->get('db.dsn', ''),
            $this->config->get('db.user'),
            $this->config->get('db.pass'),
        ));
    }

    public function crypto(): Crypto
    {
        return $this->lazy(Crypto::class, fn () => new Crypto((string) $this->config->get('app.key', '')));
    }

    public function session(): SessionStore
    {
        return $this->lazy(SessionStore::class, fn () => $this->options['session'] ?? new NativeSession(
            $this->varDir() . '/sessions',
            $this->config->isLive() || str_starts_with($this->config->baseUrl(), 'https://'),
        ));
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->lazy(RateLimiter::class, fn () => new RateLimiter($this->db()));
    }

    public function auth(): AuthService
    {
        return $this->lazy(AuthService::class, fn () => new AuthService($this->db(), $this->config, $this->crypto(), $this->session(), $this->rateLimiter()));
    }

    public function credits(): CreditService
    {
        return $this->lazy(CreditService::class, fn () => new CreditService($this->db()));
    }

    public function clients(): ClientService
    {
        return $this->lazy(ClientService::class, fn () => new ClientService($this->db(), $this->credits()));
    }

    public function audit(): AuditLog
    {
        return $this->lazy(AuditLog::class, fn () => new AuditLog($this->db()));
    }

    public function settings(): SettingsService
    {
        return $this->lazy(SettingsService::class, fn () => new SettingsService($this->db()));
    }

    public function transport(): HttpTransport
    {
        return $this->lazy(HttpTransport::class, fn () => $this->options['transport'] ?? new CurlTransport());
    }

    public function google(): GoogleCalendar
    {
        return $this->lazy(GoogleCalendar::class, fn () => new GoogleCalendar($this->db(), $this->config, $this->crypto(), $this->settings(), $this->transport()));
    }

    public function mailer(): Mailer
    {
        return $this->lazy(Mailer::class, function (): Mailer {
            if (isset($this->options['mailer'])) {
                return $this->options['mailer'];
            }
            $from = (string) $this->config->get('mail.from', 'The Stretch Effect <no-reply@localhost>');
            $host = (string) $this->config->get('mail.smtp_host', '');
            if ($host !== '') {
                return new SmtpMailer(
                    $from, $host, (int) $this->config->get('mail.smtp_port', 465),
                    (string) $this->config->get('mail.smtp_user', ''), (string) $this->config->get('mail.smtp_pass', ''),
                    (string) $this->config->get('mail.smtp_secure', 'ssl'),
                );
            }
            return $this->config->isLive() ? new NativeMailer($from) : new NullMailer($this->varDir() . '/mail.log');
        });
    }

    public function notifier(): Notifier
    {
        return $this->lazy(Notifier::class, fn () => new Notifier($this->mailer()));
    }

    public function bookings(): BookingService
    {
        return $this->lazy(BookingService::class, fn () => new BookingService(
            $this->db(), $this->settings(), $this->credits(), $this->google(), $this->notifier(), $this->audit(),
        ));
    }

    public function grants(): GrantService
    {
        return $this->lazy(GrantService::class, fn () => new GrantService($this->db()));
    }

    public function maintenance(): Maintenance
    {
        return $this->lazy(Maintenance::class, fn () => new Maintenance($this->db(), $this->settings(), $this->google(), $this->varDir() . '/sessions'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->db()->now();
    }
}
