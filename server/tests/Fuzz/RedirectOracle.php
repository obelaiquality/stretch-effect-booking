<?php
declare(strict_types=1);

namespace StretchEffect\Tests\Fuzz;

/** Independent statement of the redirect_uri rule (BUILD-SPEC 4.5, SECURITY.md "Open redirect"): written differently from the server code on purpose. */
final class RedirectOracle
{
    public const OFFICIAL = 'https://chatgpt.com/connector_platform_oauth_redirect';
    public const PREFIX = 'https://chatgpt.com/connector/oauth/';

    /** The only strings that may ever be registered or redirected to (live config): the exact URI, or the prefix plus one plain path segment. */
    public static function allowed(string $uri): bool
    {
        if ($uri === self::OFFICIAL) {
            return true;
        }
        if (!str_starts_with($uri, self::PREFIX)) {
            return false;
        }
        $rest = substr($uri, strlen(self::PREFIX));
        return preg_match('/\A[A-Za-z0-9._~-]{1,128}\z/', $rest) === 1 && $rest !== '.' && $rest !== '..';
    }

    /** @return list<string> a pool of look-alikes and attacks built around $base (a legitimate URI) */
    public static function attacks(Rng $rng, string $base): array
    {
        $host = 'chatgpt.com';
        $path = substr($base, strlen('https://chatgpt.com'));
        $list = [
            $base, strtoupper($base), str_replace('chatgpt.com', 'ChatGPT.com', $base), str_replace('https', 'HTTPS', $base), str_replace('https', 'Https', $base),
            $base . '/', $base . '//', $base . '.', $base . '?', $base . '?x=1', $base . '&x=1', $base . '#', $base . '#frag', $base . '%23', $base . '%2f', $base . "\n", $base . "\r\n", $base . ' ', ' ' . $base, $base . "\t", $base . "\0", $base . '%00',
            str_replace('chatgpt.com', 'chatgpt.com.', $base), str_replace('chatgpt.com', 'chatgpt.com:443', $base), str_replace('chatgpt.com', 'chatgpt.com:8443', $base), str_replace('chatgpt.com', 'chatgpt.com:80', $base), str_replace('chatgpt.com', 'chatgpt.com:', $base), str_replace('chatgpt.com', 'chatgpt.com:0', $base),
            "https://evil.test@$host$path", "https://$host@evil.test$path", "https://$host:pw@evil.test$path", "https://$host:443@evil.test$path", "https://user:pw@$host$path", "https://$host\\@evil.test$path", "https://evil.test\\.$host$path", "https://evil.test\\@$host$path", "https://evil.test/@$host$path",
            "https://$host.evil.test$path", "https://evil$host$path", "https://$host-evil.test$path", "https://evil.test/$host$path", "https://$host%2eevil.test$path", "https://$host%40evil.test$path", "https://evil.test#@$host$path", "https://evil.test?@$host$path",
            "https://chatgpt\u{FF0E}com$path", "https://chatgpt\u{3002}com$path", "https://chatgpt\u{2024}com$path", "https://chatgp\u{0442}.com$path", "https://\u{FF43}hatgpt.com$path", "https://xn--chatgpt-com$path", "https://chatgpt.com\u{FF0F}evil$path",
            "https://[::1]$path", "https://[::1]:8090$path", "http://[::1]:8090$path", "http://localhost:8090$path", "http://127.0.0.1:8090$path", "http://127.1$path", "http://2130706433$path", "http://0x7f000001$path", "https://[::ffff:127.0.0.1]$path", "https://[2001:db8::1]$path",
            "http://$host$path", "ftp://$host$path", "//$host$path", "/$path", "$host$path", 'javascript:alert(1)', "javascript://$host$path%0aalert(1)", 'data:text/html,<script>alert(1)</script>', 'file:///etc/passwd', 'vbscript:x', 'about:blank', 'blob:https://chatgpt.com/x',
            str_replace('/connector', '/connector/../connector', $base), str_replace('/connector', '/./connector', $base), str_replace('/connector', '//connector', $base), str_replace('/connector', '/%2e%2e/connector', $base), str_replace('/connector', '/connector%2f', $base), str_replace('/connector', '/Connector', $base),
            self::PREFIX . '..', self::PREFIX . '.', self::PREFIX . '../x', self::PREFIX . '..%2f', self::PREFIX . '%2e%2e', self::PREFIX . '%2e', self::PREFIX . 'a/b', self::PREFIX . 'a/', self::PREFIX . 'a%2fb', self::PREFIX . 'a b', self::PREFIX . 'a\\b', self::PREFIX . 'a\\..\\b', self::PREFIX . '*', self::PREFIX, self::PREFIX . str_repeat('a', 128), self::PREFIX . str_repeat('a', 129), self::PREFIX . 'a.b-c_d~e', self::PREFIX . 'a;b', self::PREFIX . "a\u{FF0E}", self::PREFIX . 'a@evil.test', self::PREFIX . '%41', self::PREFIX . 'A..B',
            self::OFFICIAL . 'x', self::OFFICIAL . '/x', substr(self::OFFICIAL, 0, -1), 'https://chatgpt.com/connector/oauth', 'https://chatgpt.com/connector/oauth//a', 'https://chatgpt.com/connector/oauthx/a', 'https://chatgpt.com/connectors/oauth/a', 'https://chatgpt.com/connector/OAuth/a',
        ];
        $mut = [];
        for ($i = 0; $i < 6; $i++) {
            $s = $rng->pick([self::OFFICIAL, self::PREFIX . 'abc123']);
            $pos = $rng->int(0, strlen($s));
            $ch = $rng->pick(['.', '/', '\\', '@', '#', '?', '%', ':', ' ', "\0", "\n", 'a', '..', '%2e', "\u{FF0E}", '[', ']', '*']);
            $mut[] = match ($rng->int(0, 2)) {
                0 => substr($s, 0, $pos) . $ch . substr($s, $pos),
                1 => substr($s, 0, $pos) . substr($s, $pos + 1),
                default => substr($s, 0, $pos) . $ch . substr($s, $pos + 1),
            };
        }
        return array_merge($list, $mut);
    }
}
