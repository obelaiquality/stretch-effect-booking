<?php
declare(strict_types=1);

namespace StretchEffect\OAuth;

/**
 * Production CIMD fetcher with an SSRF guard:
 * https only, default port, no credentials in the URL, the host must be on the configured allow-list
 * (checked by ClientRegistry and again here), every resolved address must be public, the connection
 * is pinned to the address we checked (no second DNS lookup), no redirects, 5 s timeout, 64 KB cap.
 */
final class HttpCimdFetcher implements CimdFetcher
{
    public const MAX_BYTES = 65536;
    public const TIMEOUT = 5;

    /** @param list<string> $allowedHosts */
    public function __construct(private array $allowedHosts) {}

    public function fetch(string $url): array
    {
        $p = parse_url($url);
        if ($p === false || ($p['scheme'] ?? '') !== 'https' || !isset($p['host'])
            || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])
            || (isset($p['port']) && $p['port'] !== 443)) {
            throw new \RuntimeException('cimd: url not acceptable');
        }
        $host = strtolower($p['host']);
        if (!in_array($host, array_map('strtolower', $this->allowedHosts), true)) {
            throw new \RuntimeException('cimd: host not allowed');
        }
        $ips = self::resolve($host);
        if ($ips === []) {
            throw new \RuntimeException('cimd: dns failed');
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new \RuntimeException('cimd: non-public address');
            }
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cimd: curl missing');
        }
        $ip = $ips[0];
        $pinned = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE        => [$host . ':443:' . $pinned],
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'the-stretch-effect-oauth/1',
            CURLOPT_WRITEFUNCTION  => static function ($c, string $chunk) use (&$body): int {
                $body .= $chunk;
                return strlen($body) > HttpCimdFetcher::MAX_BYTES ? 0 : strlen($chunk); // 0 aborts the transfer
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($ok === false || $status !== 200 || strlen($body) > self::MAX_BYTES) {
            throw new \RuntimeException('cimd: fetch failed');
        }
        $doc = json_decode($body, true);
        if (!is_array($doc)) {
            throw new \RuntimeException('cimd: not json');
        }
        return $doc;
    }

    /** @return list<string> */
    public static function resolve(string $host): array
    {
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        foreach ($records as $r) {
            if (isset($r['ip'])) {
                $ips[] = $r['ip'];
            } elseif (isset($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        if ($ips === []) {
            $v4 = @gethostbynamel($host) ?: [];
            $ips = array_values($v4);
        }
        return array_values(array_unique($ips));
    }

    /** False for private, loopback, link-local, reserved and unparseable addresses. */
    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // IPv4-mapped IPv6 (::ffff:a.b.c.d): judge the embedded v4 address.
        if (stripos($ip, '::ffff:') === 0) {
            return self::isPublicIp(substr($ip, 7));
        }
        return true;
    }
}
