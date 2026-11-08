<?php
declare(strict_types=1);

namespace StretchEffect\Contracts;

/** Minimal request object built once by public/index.php. */
final class HttpRequest
{
    /** @param array<string,string> $headers lower-case names
     *  @param array<string,string> $query
     *  @param array<string,mixed>  $form    parsed application/x-www-form-urlencoded body */
    public function __construct(
        public readonly string $method,
        public readonly string $path,      // without query string, e.g. "/oauth/token"
        public readonly array $headers,
        public readonly array $query,
        public readonly string $rawBody,
        public readonly array $form,
        public readonly string $ip,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string,mixed>|null decoded JSON body, null if not JSON / invalid */
    public function json(): ?array
    {
        $d = json_decode($this->rawBody, true);
        return is_array($d) ? $d : null;
    }
}

final class HttpResponse
{
    /** @param array<string,string> $headers */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string $body = '',
    ) {}

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        return new self($status, ['Content-Type' => 'application/json; charset=utf-8'] + $headers,
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function html(string $html, int $status = 200, array $headers = []): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'] + $headers, $html);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self($status, ['Location' => $url, 'Cache-Control' => 'no-store']);
    }
}
