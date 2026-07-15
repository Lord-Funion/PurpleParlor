<?php

declare(strict_types=1);

namespace App\Mail;

use RuntimeException;

final class LogMailTransport implements MailTransportInterface
{
    public function __construct(private readonly string $directory)
    {
    }

    public function send(MailMessage $message): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create local mail directory.');
        }
        $payload = json_encode([
            'timestamp' => gmdate('c'), 'to' => $message->toEmail, 'subject' => $message->subject,
            'text' => $message->textBody,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($this->directory . '/mail-' . gmdate('Y-m-d') . '.log', $payload . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Unable to write local mail log.');
        }
    }
}
