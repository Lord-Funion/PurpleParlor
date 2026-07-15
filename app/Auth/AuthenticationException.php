<?php

declare(strict_types=1);

namespace App\Auth;

final class AuthenticationException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'authentication_failed')
    {
        parent::__construct($message);
    }
}
