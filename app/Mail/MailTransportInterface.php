<?php

declare(strict_types=1);

namespace App\Mail;

interface MailTransportInterface
{
    public function send(MailMessage $message): void;
}
