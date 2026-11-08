<?php
declare(strict_types=1);

namespace StretchEffect\Google;

interface HttpTransport
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string}
     * @throws \RuntimeException on network failure
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array;
}
