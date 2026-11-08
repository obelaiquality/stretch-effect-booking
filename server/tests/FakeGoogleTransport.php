<?php
declare(strict_types=1);

namespace StretchEffect\Tests;

use StretchEffect\Google\HttpTransport;

/** Fake Google API: records calls and answers token, freeBusy and event requests. */
final class FakeGoogleTransport implements HttpTransport
{
    /** @var list<array{method:string,url:string,body:?string}> */
    public array $calls = [];
    /** @var list<array{start:string,end:string}> */
    public array $busy = [];
    public bool $failEvents = false;

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body];
        if (str_contains($url, 'oauth2.googleapis.com/token')) {
            return ['status' => 200, 'body' => json_encode(['access_token' => 'at-' . count($this->calls), 'expires_in' => 3600, 'refresh_token' => 'rt-secret', 'scope' => 'x'])];
        }
        if (str_contains($url, '/freeBusy')) {
            return ['status' => 200, 'body' => json_encode(['calendars' => ['ivan@example.com' => ['busy' => $this->busy]]])];
        }
        if (str_contains($url, '/events')) {
            if ($this->failEvents) {
                return ['status' => 500, 'body' => '{}'];
            }
            return ['status' => $method === 'DELETE' ? 204 : 200, 'body' => $method === 'DELETE' ? '' : '{}'];
        }
        return ['status' => 200, 'body' => '{}'];
    }

    /** @return list<array{method:string,url:string,body:?string}> */
    public function callsTo(string $needle, ?string $method = null): array
    {
        return array_values(array_filter($this->calls, static fn (array $c): bool => str_contains($c['url'], $needle) && ($method === null || $c['method'] === $method)));
    }
}
