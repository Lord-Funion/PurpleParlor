<?php

declare(strict_types=1);

namespace PurpleParlor\Games\Fairness;

use InvalidArgumentException;

final class SeedCommitment
{
    public const ALGORITHM = 'HMAC-SHA256-PP-V1';

    public static function generateServerSeed(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function commit(string $serverSeed): string
    {
        if ($serverSeed === '') {
            throw new InvalidArgumentException('Server seed cannot be empty.');
        }

        return hash('sha256', $serverSeed);
    }

    /** @return array{algorithm:string,serverSeedHash:string,clientSeed:string,nonce:int} */
    public static function publicEnvelope(string $serverSeedHash, string $clientSeed, int $nonce): array
    {
        return [
            'algorithm' => self::ALGORITHM,
            'serverSeedHash' => $serverSeedHash,
            'clientSeed' => $clientSeed,
            'nonce' => $nonce,
        ];
    }
}
