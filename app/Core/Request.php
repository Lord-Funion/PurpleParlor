<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @param array<string, string|array<string>> $headers */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $cookies = [],
        public readonly array $files = [],
        public readonly array $server = [],
        public readonly array $headers = [],
        public readonly string $rawBody = '',
        private array $attributes = [],
    ) {
    }

    public static function capture(): self
    {
        $server = $_SERVER;
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($server[$key])) {
                $headers[$name] = (string) $server[$key];
            }
        }
        $raw = file_get_contents('php://input') ?: '';
        $body = $_POST;
        if (str_contains(strtolower((string) ($headers['content-type'] ?? '')), 'application/json') && $raw !== '') {
            $decoded = json_decode($raw, true);
            $body = is_array($decoded) ? $decoded : [];
        }
        $uri = parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        return new self(
            strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')),
            '/' . ltrim($uri, '/'),
            $_GET,
            $body,
            $_COOKIE,
            $_FILES,
            $server,
            $headers,
            $raw,
        );
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $value = $this->headers[strtolower($name)] ?? $default;
        return is_array($value) ? ($value[0] ?? $default) : $value;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;
        return $clone;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function clientIp(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function expectsJson(): bool
    {
        return str_contains(strtolower((string) $this->header('accept', '')), 'application/json')
            || str_starts_with($this->uri, '/api/');
    }
}
