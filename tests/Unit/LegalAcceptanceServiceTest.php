<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LegalAcceptanceService;
use DomainException;
use Tests\Support\TestCase;

final class LegalAcceptanceServiceTest extends TestCase
{
    public function testAcceptancesAreVersionedServerOwnedAndIdempotent(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $userId = $this->database->insert('INSERT INTO users (email, email_normalized, username, username_normalized, password_hash, status, email_verified_at, created_at, updated_at)
            VALUES (:email, :email, :username, :username, :password, :status, :verified, :created, :updated)', [
            'email' => 'policy@example.test', 'username' => 'policy_user', 'password' => password_hash('Test passphrase 123!', PASSWORD_DEFAULT),
            'status' => 'active', 'verified' => $now, 'created' => $now, 'updated' => $now,
        ]);
        $service = new LegalAcceptanceService($this->database, $this->config);
        $service->accept($userId, ['terms', 'privacy', 'virtual-currency']);
        $service->accept($userId, ['terms', 'privacy']);

        $rows = $this->database->fetchAll('SELECT document_key, document_version FROM legal_acceptances WHERE user_id = :user ORDER BY document_key', ['user' => $userId]);
        $this->assertSame(3, count($rows));
        foreach ($rows as $row) {
            $this->assertSame('1', (string) $row['document_version']);
        }
    }

    public function testUnknownPolicyKeyFailsWithoutPartialAcceptance(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $userId = $this->database->insert('INSERT INTO users (email, email_normalized, username, username_normalized, password_hash, status, email_verified_at, created_at, updated_at)
            VALUES (:email, :email, :username, :username, :password, :status, :verified, :created, :updated)', [
            'email' => 'invalid-policy@example.test', 'username' => 'invalid_policy', 'password' => password_hash('Test passphrase 123!', PASSWORD_DEFAULT),
            'status' => 'active', 'verified' => $now, 'created' => $now, 'updated' => $now,
        ]);
        $service = new LegalAcceptanceService($this->database, $this->config);
        $this->expectException(DomainException::class, fn () => $service->accept($userId, ['terms', 'invented-policy']));
        $this->assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) AS aggregate FROM legal_acceptances WHERE user_id = :user', ['user' => $userId])['aggregate']);
    }
}
