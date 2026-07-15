<?php

declare(strict_types=1);

namespace App\Core;

use App\Security\SecretRedactor;
use RuntimeException;
use Throwable;

final class Logger
{
    private const LEVELS = ['debug' => 10, 'info' => 20, 'notice' => 30, 'warning' => 40, 'error' => 50, 'critical' => 60];

    public function __construct(
        private readonly string $directory,
        private readonly string $minimumLevel = 'warning',
        private readonly int $maxFiles = 14,
        private readonly ?SecretRedactor $redactor = null,
    ) {
    }

    public function debug(string $message, array $context = []): void { $this->log('debug', $message, $context); }
    public function info(string $message, array $context = []): void { $this->log('info', $message, $context); }
    public function warning(string $message, array $context = []): void { $this->log('warning', $message, $context); }
    public function error(string $message, array $context = []): void { $this->log('error', $message, $context); }
    public function critical(string $message, array $context = []): void { $this->log('critical', $message, $context); }

    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);
        if (!isset(self::LEVELS[$level])) {
            $level = 'error';
        }
        $minimum = self::LEVELS[strtolower($this->minimumLevel)] ?? self::LEVELS['warning'];
        if (self::LEVELS[$level] < $minimum) {
            return;
        }
        if (!is_dir($this->directory) && !mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create the private log directory.');
        }
        $context = $this->normalize($context);
        $payload = [
            'timestamp' => gmdate('c'),
            'level' => $level,
            'message' => str_replace(["\r", "\n"], ' ', $message),
            'request_id' => RequestContext::id(),
            'context' => $context,
        ];
        $line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false || file_put_contents($this->directory . '/app-' . gmdate('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            error_log('Purple Parlor logger failed to write an application event.');
        }
        $this->rotate();
    }

    private function normalize(array $context): array
    {
        array_walk_recursive($context, static function (&$value): void {
            if ($value instanceof Throwable) {
                $value = ['class' => $value::class, 'message' => $value->getMessage(), 'code' => $value->getCode()];
            } elseif (is_resource($value)) {
                $value = '[resource]';
            } elseif (is_object($value)) {
                $value = '[object ' . $value::class . ']';
            }
        });
        return ($this->redactor ?? new SecretRedactor())->redactArray($context);
    }

    private function rotate(): void
    {
        if (random_int(1, 100) !== 1) {
            return;
        }
        $files = glob($this->directory . '/app-*.log') ?: [];
        rsort($files, SORT_STRING);
        foreach (array_slice($files, max(1, $this->maxFiles)) as $file) {
            @unlink($file);
        }
    }
}
