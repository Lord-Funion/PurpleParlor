<?php

declare(strict_types=1);

namespace PurpleParlor\Games\Fairness;

final class SeedVerifier
{
    public static function commitmentMatches(string $publishedHash, string $revealedServerSeed): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $publishedHash) === 1
            && hash_equals($publishedHash, SeedCommitment::commit($revealedServerSeed));
    }

    /**
     * Generates the documented integer sequence after a seed has been revealed.
     * This is suitable for an offline verifier page.
     *
     * @return list<int>
     */
    public static function reproduce(
        string $publishedHash,
        string $revealedServerSeed,
        string $clientSeed,
        int $nonce,
        int $minimum,
        int $maximum,
        int $count,
    ): array {
        if (!self::commitmentMatches($publishedHash, $revealedServerSeed)) {
            throw new \InvalidArgumentException('The revealed seed does not match the published commitment.');
        }
        if ($count < 1 || $count > 10000) {
            throw new \InvalidArgumentException('Verification count is outside the supported range.');
        }

        $rng = new VerifiableRandomSource($revealedServerSeed, $clientSeed, $nonce);
        $values = [];
        for ($index = 0; $index < $count; ++$index) {
            $values[] = $rng->int($minimum, $maximum);
        }

        return $values;
    }

    /** @return array<string, mixed> */
    public static function testVector(): array
    {
        return [
            'algorithm' => SeedCommitment::ALGORITHM,
            'serverSeed' => 'purple-parlor-server-seed-0001',
            'serverSeedHash' => '490fdebd60d369723df396358e4449cc1979cb6e064cac06083347095f1636e2',
            'clientSeed' => 'guest-example-17',
            'nonce' => 42,
            'range' => [0, 36],
            'firstTen' => [0, 33, 7, 22, 15, 22, 34, 36, 1, 11],
        ];
    }
}
