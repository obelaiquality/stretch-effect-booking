<?php
declare(strict_types=1);

namespace StretchEffect\Http;

use StretchEffect\Contracts\HttpRequest;
use StretchEffect\Contracts\HttpResponse;

final class RequestFactory
{
    public static function fromGlobals(): HttpRequest
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (!is_string($v)) {
                continue;
            }
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            } elseif ($k === 'CONTENT_TYPE' || $k === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $k))] = $v;
            }
        }
        // Apache/CGI often hides Authorization from PHP: try every known place.
        if (!isset($headers['authorization'])) {
            $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
            if (!is_string($auth) && function_exists('apache_request_headers')) {
                foreach ((array) apache_request_headers() as $name => $value) {
                    if (strtolower((string) $name) === 'authorization') {
                        $auth = (string) $value;
                    }
                }
            }
            if (is_string($auth) && $auth !== '') {
                $headers['authorization'] = $auth;
            }
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        $path = '/' . ltrim(preg_replace('#/{2,}#', '/', $path) ?? $path, '/');
        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_string($v)) {
                $query[(string) $k] = $v;
            }
        }
        $ctype = strtolower($headers['content-type'] ?? '');
        $form = str_starts_with($ctype, 'application/x-www-form-urlencoded') ? $_POST : [];
        $body = (string) file_get_contents('php://input');
        if (strlen($body) > 1048576) {
            $body = '';
        }
        return new HttpRequest(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path, $headers, $query, $body, $form, (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
        );
    }

    public static function emit(HttpResponse $res, string $method = 'GET'): void
    {
        http_response_code($res->status);
        foreach ($res->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        if ($method !== 'HEAD' && $res->status !== 204) {
            echo $res->body;
        }
    }
}
