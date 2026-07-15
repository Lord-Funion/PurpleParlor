<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/** Writes only allow-listed runtime values to the private .env file atomically. */
final class EnvWriter
{
    /** @var list<string> */
    private const ALLOWED = [
        'APP_KEY', 'APP_ENV', 'APP_DEBUG', 'APP_NAME', 'APP_BRAND', 'APP_CREATOR_NAME', 'APP_TAGLINE',
        'APP_URL', 'APP_SUPPORT_EMAIL', 'APP_TIMEZONE', 'APP_MINIMUM_AGE', 'APP_LEGAL_POLICY_VERSION',
        'APP_AGE_EXIT_URL', 'APP_INDEXING_ENABLED',
        'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_CHARSET',
        'SESSION_SECURE', 'SESSION_SAMESITE', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD',
        'MAIL_ENCRYPTION', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME', 'PAYMENTS_ENABLED', 'PAYMENT_MODE',
        'PAYMENT_PROVIDER', 'ADULT_OWNER_CONFIRMED', 'LIVE_PAYMENT_ACTIVATION_LOCK', 'INSTALL_TOKEN',
    ];

    public function __construct(private readonly string $path)
    {
    }

    /** @param array<string, scalar|null> $updates */
    public function update(array $updates): void
    {
        foreach ($updates as $key => $value) {
            if (!in_array($key, self::ALLOWED, true)) {
                throw new RuntimeException("Environment key {$key} is not writable through this workflow.");
            }
            if (is_string($value) && (str_contains($value, "\0") || str_contains($value, "\r") || str_contains($value, "\n"))) {
                throw new RuntimeException("Environment value {$key} contains an invalid line break.");
            }
        }
        $contents = is_file($this->path) ? file_get_contents($this->path) : '';
        if ($contents === false) {
            throw new RuntimeException('The private environment file could not be read.');
        }
        foreach ($updates as $key => $value) {
            $line = $key . '=' . $this->encode($value);
            $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
            if (preg_match($pattern, $contents) === 1) {
                $contents = (string) preg_replace($pattern, $line, $contents, 1);
            } else {
                $contents = rtrim($contents) . PHP_EOL . $line . PHP_EOL;
            }
        }
        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            throw new RuntimeException('The private environment directory is unavailable.');
        }
        $temporary = $this->path . '.tmp.' . bin2hex(random_bytes(8));
        // The exclusive temporary-file create below is the authoritative
        // permission check. is_writable() has false negatives on some cPanel
        // ACLs and Windows/OneDrive directories.
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException('A temporary environment file could not be written.');
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $this->path)) {
            if (PHP_OS_FAMILY !== 'Windows' || file_put_contents($this->path, $contents, LOCK_EX) === false) {
                @unlink($temporary);
                throw new RuntimeException('The private environment update could not be committed atomically.');
            }
            @unlink($temporary);
        }
        @chmod($this->path, 0600);
    }

    private function encode(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        $string = (string) $value;
        if ($string === '' || preg_match('/\s/', $string)
            || str_contains($string, '#') || str_contains($string, '=')
            || str_contains($string, '"') || str_contains($string, "'") || str_contains($string, '\\')) {
            return '"' . addcslashes($string, "\\\"") . '"';
        }
        return $string;
    }
}
