<?php

declare(strict_types=1);

namespace App\Mail;

use InvalidArgumentException;

final readonly class MailMessage
{
    public function __construct(
        public string $toEmail,
        public string $toName,
        public string $subject,
        public string $htmlBody,
        public string $textBody,
        public array $headers = [],
    ) {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL) || str_contains($toEmail, "\n") || str_contains($subject, "\n") || str_contains($subject, "\r")) {
            throw new InvalidArgumentException('Invalid email message recipient or subject.');
        }
    }
}
