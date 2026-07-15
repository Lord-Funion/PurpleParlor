<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** @param array<string, string|list<string>> $headers */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {
    }

    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return new self($json, $status, ['Content-Type' => 'application/json; charset=utf-8'] + $headers);
    }

    public static function redirect(string $url, int $status = 303): self
    {
        if (preg_match('/[\r\n]/', $url)) {
            $url = '/';
        }
        if (!in_array($status, [301, 302, 303, 307, 308], true)) {
            $status = 303;
        }
        return new self('', $status, ['Location' => $url]);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->body, $this->status, array_replace($this->headers, [$name => $value]));
    }

    public function withAddedHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        if (!array_key_exists($name, $headers)) {
            $headers[$name] = $value;
        } else {
            $existing = $headers[$name];
            $headers[$name] = is_array($existing) ? [...$existing, $value] : [$existing, $value];
        }
        return new self($this->body, $this->status, $headers);
    }

    public function send(): never
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    header($name . ': ' . $item, false);
                }
                continue;
            }
            header($name . ': ' . $value, true);
        }
        echo $this->body;
        exit;
    }
}
