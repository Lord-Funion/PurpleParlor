<?php

declare(strict_types=1);

namespace App\Security;

final class SecretRedactor
{
    private const SENSITIVE = '/(?:password|passwd|secret|token|authorization|cookie|session|cvv|card(?:_?number)?|access[_-]?key|client[_-]?secret|smtp[_-]?password|bank|ssn|government[_-]?id)/i';

    public function redactArray(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE, $key)) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $result[$key] = $this->redactArray($value);
            } elseif (is_string($value)) {
                $result[$key] = $this->redactText($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    public function redactText(string $text): string
    {
        $text = str_replace(["\r", "\n"], ' ', $text);
        $text = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $text) ?? $text;
        $text = preg_replace(
            '/\b(password|passwd|secret|token|authorization|cookie|session|cvv|card(?:_?number)?|access[_-]?key|client[_-]?secret|smtp[_-]?password|bank|ssn|government[_-]?id)\b\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;&]+)/i',
            '$1=[REDACTED]',
            $text,
        ) ?? $text;
        $text = preg_replace('/\b(?:\d[ -]*?){13,19}\b/', '[REDACTED CARD-LIKE VALUE]', $text) ?? $text;
        $text = preg_replace('/([?&](?:token|secret|key|password)=)[^&\s]+/i', '$1[REDACTED]', $text) ?? $text;
        return function_exists('mb_substr') ? mb_substr($text, 0, 4000) : substr($text, 0, 4000);
    }
}
