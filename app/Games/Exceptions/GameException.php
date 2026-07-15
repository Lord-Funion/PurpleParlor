<?php

declare(strict_types=1);

namespace PurpleParlor\Games\Exceptions;

use RuntimeException;

final class GameException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'invalid_game_action',
        public readonly int $httpStatus = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
