<?php
declare(strict_types=1);

namespace StretchEffect\Tests\Fuzz;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use StretchEffect\Support\Log;

/**
 * Base class of every fuzz test.
 *
 *   FUZZ_SEED=12345 FUZZ_ITERATIONS=500 vendor/bin/phpunit --testsuite fuzz
 *
 * The seed is random unless FUZZ_SEED is set; it is printed with every failure and in the summary line of every test,
 * so any failure can be replayed exactly. Every case runs with a PHP error handler that records warnings/notices/deprecations
 * raised by server/src, and with the server error logs redirected to files that must stay empty.
 */
abstract class FuzzCase extends TestCase
{
    protected int $baseSeed = 0;
    protected int $iterations = 2000;
    protected Rng $rng;
    protected int $caseNo = 0;
    /** Human-readable description of the request in flight (shown on failure). */
    protected string $inFlight = '';
    /** @var list<string> */
    private array $phpErrors = [];
    private string $errorLog = '';
    private string $appLog = '';
    private string|false $prevErrorLog = false;
    private ?string $prevAppLog = null;
    /** @var array<string,int> cases per label, for the summary line */
    protected array $ran = [];

    protected function setUp(): void
    {
        $env = getenv('FUZZ_SEED');
        $this->baseSeed = $env !== false && $env !== '' ? (int) $env : random_int(1, 2147483646);
        $it = getenv('FUZZ_ITERATIONS');
        $this->iterations = $it !== false && (int) $it > 0 ? (int) $it : 2000;
        $this->rng = new Rng(($this->baseSeed + crc32(static::class . '::' . $this->name())) & 0x7fffffff);

        $this->errorLog = tempnam(sys_get_temp_dir(), 'se-fuzz-php-') ?: sys_get_temp_dir() . '/se-fuzz-php.log';
        $this->appLog = tempnam(sys_get_temp_dir(), 'se-fuzz-app-') ?: sys_get_temp_dir() . '/se-fuzz-app.log';
        $this->prevErrorLog = ini_get('error_log');
        ini_set('error_log', $this->errorLog);
        $this->prevAppLog = Log::$path;
        Log::$path = $this->appLog;

        set_error_handler(function (int $no, string $str, string $file, int $line): bool {
            if (str_contains($file, '/server/src/') || str_contains($file, '/tests/Fuzz/')) {
                $this->phpErrors[] = "PHP error $no: $str at " . basename(dirname($file)) . '/' . basename($file) . ":$line";
            }
            return true;
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        Log::$path = $this->prevAppLog;
        @unlink($this->errorLog);
        @unlink($this->appLog);
        $parts = [];
        foreach ($this->ran as $k => $n) {
            $parts[] = "$k=$n";
        }
        fwrite(STDERR, sprintf("\n[fuzz] %s::%s seed=%d cases=%d %s\n", (new \ReflectionClass($this))->getShortName(), $this->name(), $this->baseSeed, $this->caseNo, implode(' ', $parts)));
    }

    protected function tally(string $label): void
    {
        $this->ran[$label] = ($this->ran[$label] ?? 0) + 1;
    }

    /** Fail with the seed, the case number and the request that was in flight. */
    protected function bad(string $message): never
    {
        $logs = '';
        foreach (['php error_log' => $this->errorLog, 'app log' => $this->appLog] as $name => $f) {
            $t = is_file($f) ? trim((string) file_get_contents($f)) : '';
            if ($t !== '') {
                $logs .= "\n--- $name ---\n" . substr($t, -1500);
            }
        }
        throw new AssertionFailedError(sprintf(
            "FUZZ FAILURE %s::%s\n  replay: FUZZ_SEED=%d FUZZ_ITERATIONS=%d vendor/bin/phpunit --testsuite fuzz --filter %s\n  case #%d\n  %s\n  request: %s%s",
            (new \ReflectionClass($this))->getShortName(), $this->name(), $this->baseSeed, $this->iterations, $this->name(),
            $this->caseNo, $message, substr($this->inFlight, 0, 1200), $logs,
        ));
    }

    protected function ensure(bool $ok, string $message): void
    {
        $this->addToAssertionCount(1);
        if (!$ok) {
            $this->bad($message);
        }
    }

    /** Call after every case: PHP warnings and server error logs must be empty. */
    protected function endCase(): void
    {
        if ($this->phpErrors !== []) {
            $e = implode("\n  ", array_slice($this->phpErrors, 0, 5));
            $this->phpErrors = [];
            $this->bad("PHP raised a warning/notice/deprecation:\n  $e");
        }
        foreach ([$this->errorLog, $this->appLog] as $f) {
            clearstatcache(true, $f);
            if (is_file($f) && filesize($f) > 0) {
                $this->bad('the server wrote to its error log (an unexpected exception was caught somewhere)');
            }
        }
    }

    /** A response body must never carry a stack trace, SQL, a file path, a password hash or a secret. */
    protected function assertNoLeak(string $body, string $what): void
    {
        $re = '/Stack trace|#\d+ [\/\\\\]|PDOException|SQLSTATE|Fatal error|Warning:|Notice:|Deprecated:|Uncaught|\.php\b|\/Users\/|\/tmp\/|vendor\/|\bsrc\/(Api|Mcp|OAuth|Services|Support)|TypeError|ValueError|ArgumentCountError|UnhandledMatchError|DivisionByZeroError|\$argon2|\$2y\$|totp_secret|You have an error in your SQL|no such (table|column)|syntax error, unexpected/';
        if (preg_match($re, $body, $m) === 1) {
            $this->bad("$what leaked internals ('{$m[0]}'): " . substr($body, 0, 300));
        }
    }

    /** Remove the effect of the clock for tests that need a fresh start. */
    protected function describe(string $method, string $path, string $body = '', array $headers = [], array $query = []): void
    {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . (strlen((string) $v) > 80 ? substr((string) $v, 0, 80) . '...' : $v);
        }
        $this->inFlight = trim("$method $path" . ($query !== [] ? '?' . http_build_query($query) : '') . ' [' . implode('; ', $h) . '] body=' . json_encode(substr($body, 0, 600), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
