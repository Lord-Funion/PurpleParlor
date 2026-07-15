<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Services\InstallerAccountService;
use DomainException;
use PDOException;
use Tests\Support\TestCase;

final class InstallerAccountServiceTest extends TestCase
{
    public function testCreatesBothAdministratorsWithDistinctNormalizedIdentities(): void
    {
        $result = $this->service()->createAdministrators($this->validInput());

        $this->assertNotSame($result['owner_id'], $result['developer_id']);
        $users = $this->database->fetchAll(
            'SELECT id, email_normalized, username_normalized, email_verified_at FROM users ORDER BY id',
        );
        $this->assertSame(2, count($users));
        $this->assertSame('owner@example.test', $users[0]['email_normalized']);
        $this->assertSame('developer@example.test', $users[1]['email_normalized']);
        $this->assertNotSame($users[0]['username_normalized'], $users[1]['username_normalized']);
        $this->assertTrue($users[0]['email_verified_at'] !== null && $users[1]['email_verified_at'] !== null);

        $roles = $this->database->fetchAll(
            'SELECT ur.user_id, r.name, ur.granted_by FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id ORDER BY ur.user_id',
        );
        $this->assertSame('adult_owner', $roles[0]['name']);
        $this->assertSame('developer_admin', $roles[1]['name']);
        $this->assertSame($result['owner_id'], (int) $roles[1]['granted_by']);

        $wallets = $this->database->fetchAll('SELECT user_id, currency, balance FROM virtual_wallets ORDER BY user_id, currency');
        $this->assertSame(4, count($wallets));
        $this->assertSame(20_200, array_sum(array_map(static fn (array $row): int => (int) $row['balance'], $wallets)));
    }

    public function testPrevalidationRejectsDuplicateNormalizedEmailsBeforeCreatingEitherAccount(): void
    {
        $input = $this->validInput();
        $input['developer_email'] = ' OWNER@EXAMPLE.TEST ';

        $this->expectException(DomainException::class, fn () => $this->service()->createAdministrators($input));
        $this->assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) AS count FROM users')['count']);
    }

    public function testPrevalidationChecksTheSecondPasswordBeforeCreatingEitherAccount(): void
    {
        $input = $this->validInput();
        $input['developer_password'] = 'too-short';
        $input['developer_password_confirmation'] = 'too-short';

        $this->expectException(\RuntimeException::class, fn () => $this->service()->createAdministrators($input));
        $this->assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) AS count FROM users')['count']);
    }

    public function testLateRoleFailureRollsBackBothAccountsAndAllRelatedRecords(): void
    {
        $developerRole = $this->database->fetchOne("SELECT id FROM roles WHERE name = 'developer_admin'");
        $this->database->execute(
            "CREATE TRIGGER installer_test_fail_developer_role BEFORE INSERT ON user_roles
             WHEN NEW.role_id = {$developerRole['id']}
             BEGIN SELECT RAISE(ABORT, 'simulated developer role failure'); END",
        );

        $this->expectException(PDOException::class, fn () => $this->service()->createAdministrators($this->validInput()));

        foreach (['users', 'user_profiles', 'user_settings', 'user_roles', 'virtual_wallets', 'virtual_ledger_entries'] as $table) {
            $count = (int) $this->database->fetchOne("SELECT COUNT(*) AS count FROM {$table}")['count'];
            $this->assertSame(0, $count, "{$table} retained partial installer records.");
        }
    }

    private function service(): InstallerAccountService
    {
        return new InstallerAccountService($this->database, self::KEY);
    }

    /** @return array<string,string> */
    private function validInput(): array
    {
        return [
            'owner_name' => 'Parlor Administrator',
            'owner_email' => 'Owner@Example.test',
            'owner_password' => 'owner-long-unique-passphrase',
            'owner_password_confirmation' => 'owner-long-unique-passphrase',
            'developer_name' => 'Parlor Administrator',
            'developer_email' => 'Developer@Example.test',
            'developer_password' => 'developer-long-unique-passphrase',
            'developer_password_confirmation' => 'developer-long-unique-passphrase',
        ];
    }
}
