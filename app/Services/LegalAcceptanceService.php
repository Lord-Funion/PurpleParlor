<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Database\Database;
use DomainException;

final class LegalAcceptanceService
{
    /** @var list<string> */
    private const DOCUMENTS = [
        'terms', 'privacy', 'subscription-terms', 'refund', 'virtual-currency',
        'cookies', 'advertising', 'acceptable-use',
    ];

    public function __construct(
        private readonly Database $database,
        private readonly Config $config,
    ) {
    }

    /** @param list<string> $documents */
    public function accept(int $userId, array $documents, ?string $ipHash = null): void
    {
        if ($userId <= 0 || $documents === []) {
            throw new DomainException('A valid account and policy selection are required.');
        }
        $documents = array_values(array_unique($documents));
        foreach ($documents as $document) {
            if (!in_array($document, self::DOCUMENTS, true)) {
                throw new DomainException('The requested policy acceptance is invalid.');
            }
        }

        $version = (string) $this->config->get('app.legal_policy_version', '1');
        if ($version === '' || strlen($version) > 32 || preg_match('/^[A-Za-z0-9._-]+$/', $version) !== 1) {
            throw new DomainException('The active policy version is invalid.');
        }
        $this->database->transaction(function (Database $database) use ($userId, $documents, $version, $ipHash): void {
            $sql = $database->driver() === 'mysql'
                ? 'INSERT IGNORE INTO legal_acceptances (user_id, document_key, document_version, ip_hash, accepted_at) VALUES (:user, :document, :version, :ip, :accepted)'
                : 'INSERT OR IGNORE INTO legal_acceptances (user_id, document_key, document_version, ip_hash, accepted_at) VALUES (:user, :document, :version, :ip, :accepted)';
            $acceptedAt = gmdate('Y-m-d H:i:s');
            foreach ($documents as $document) {
                $database->execute($sql, [
                    'user' => $userId,
                    'document' => $document,
                    'version' => $version,
                    'ip' => $ipHash,
                    'accepted' => $acceptedAt,
                ]);
            }
        });
    }
}
