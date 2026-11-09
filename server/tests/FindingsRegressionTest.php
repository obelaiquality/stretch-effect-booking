<?php
declare(strict_types=1);

namespace StretchEffect\Tests;

use PHPUnit\Framework\TestCase;
use StretchEffect\Contracts\DomainError;
use StretchEffect\Mcp\Redactor;
use StretchEffect\OAuth\ClientRegistry;
use StretchEffect\Support\Time;

/** One small deterministic test for each real defect the fuzz suite found (see docs/TESTING.md). */
final class FindingsRegressionTest extends TestCase
{
    /** OAuth DCR: nested arrays in grant_types / response_types raised "Array to string conversion". */
    public function testRegistrationWithNestedGrantAndResponseTypesIsRefusedCleanly(): void
    {
        $app = CoreTestKit::app();
        $reg = new ClientRegistry($app, new class implements \StretchEffect\OAuth\CimdFetcher {
            public function fetch(string $url): array { return []; }
        });
        $base = ['redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect'], 'client_name' => 'x'];
        foreach ([['grant_types' => [['authorization_code']]], ['grant_types' => [new \stdClass()]], ['response_types' => [['code']]], ['response_types' => [1]], ['grant_types' => 'authorization_code']] as $bad) {
            try {
                $reg->register($bad + $base);
                $this->fail('registration with ' . json_encode($bad) . ' was accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1); // refused, and PHPUnit (failOnWarning) proves no warning was raised
            }
        }
        $ok = $reg->register(['grant_types' => ['authorization_code'], 'response_types' => ['code']] + $base);
        $this->assertSame(['code'], $ok['response_types']);
    }

    /** '.' is a dot segment: https://chatgpt.com/connector/oauth/. is the prefix itself once a browser resolves it. */
    public function testRedirectUriDotSegmentsAreRefused(): void
    {
        $app = CoreTestKit::app();
        $reg = new ClientRegistry($app, new class implements \StretchEffect\OAuth\CimdFetcher {
            public function fetch(string $url): array { return []; }
        });
        foreach (['.', '..', 'a/..'] as $seg) {
            $this->assertFalse($reg->redirectAllowed('https://chatgpt.com/connector/oauth/' . $seg), $seg);
        }
        foreach (['a.b', '...', 'abc123', 'a-b_c~d'] as $seg) {
            $this->assertTrue($reg->redirectAllowed('https://chatgpt.com/connector/oauth/' . $seg), $seg);
        }
    }

    /** A NUL byte in a date made DateTimeImmutable::createFromFormat throw a ValueError, which the kernel turned into HTTP 500. */
    public function testNulByteInADateIsA422NotA500(): void
    {
        $this->assertNull(Time::localDate("2026-11-09\0"));
        $this->assertNull(Time::localDate("\n2026-11-09"));
        $this->assertNull(Time::localDate("2026-11-09\n"));
        $app = CoreTestKit::app();
        foreach ([['from' => "2026-11-09\0", 'to' => '2026-11-10'], ['from' => '2026-11-09', 'to' => "2026-11-1\0"]] as $q) {
            $r = CoreTestKit::call($app, 'GET', '/api/availability', null, $q);
            $this->assertSame(422, $r["status"], $r["body"]);
            $this->assertSame('validation', $r['json']['error']['code']);
        }
        try {
            $app->bookings()->localToUtc("2026-11-09T09:30\0");
            $this->fail('NUL accepted');
        } catch (DomainError $e) {
            $this->assertSame('validation', $e->errorCode);
        }
    }

    /** An owner who marks a future booking completed or no_show used to reopen that slot, so a second client could take the same hour. */
    public function testCompletedAndNoShowBookingsStillBlockTheirSlot(): void
    {
        foreach (['completed', 'no_show'] as $status) {
            $app = CoreTestKit::app();
            $a = CoreTestKit::client($app, 'a@example.com', 2);
            $b = CoreTestKit::client($app, 'b@example.com', 2);
            $start = '2026-11-10T08:00:00Z';
            $booking = $app->bookings()->create($a['id'], $start, 'client', $a['id']);
            $app->bookings()->setStatus($booking['id'], $status, 'owner');
            $starts = array_column($app->bookings()->availability('2026-11-10', '2026-11-10'), 'start');
            $this->assertNotContains($start, $starts, "$status booking reopened its slot");
            $this->assertNotContains('2026-11-10T07:30:00Z', $starts, 'the 15 minute buffer before it must hold too');
            $this->assertNotContains('2026-11-10T09:00:00Z', $starts, 'the 15 minute buffer after it must hold too');
            try {
                $app->bookings()->create($b['id'], $start, 'client', $b['id']);
                $this->fail('a second booking was created in the same hour');
            } catch (DomainError $e) {
                $this->assertSame('conflict', $e->errorCode);
            }
            $this->assertSame(2, $app->credits()->balance($b['id']));
        }
    }

    /** Argument names in the audit log are attacker-chosen: short, plain, and at most 20 of them. */
    public function testAuditArgumentNamesAreBounded(): void
    {
        $args = [str_repeat('k', 70000) => 1, "evil\n<script>" => 'x'];
        for ($i = 0; $i < 40; $i++) {
            $args["extra$i"] = $i;
        }
        $out = Redactor::redact($args);
        $this->assertLessThanOrEqual(Redactor::MAX_KEYS + 1, count($out));
        foreach (array_keys($out) as $k) {
            $this->assertLessThanOrEqual(40, mb_strlen((string) $k));
            $this->assertMatchesRegularExpression('/^[\w.?-]+$/u', (string) $k);
        }
        $this->assertArrayHasKey('_args_hash', $out);
        $this->assertLessThan(2000, strlen(json_encode($out)));
    }
}
