<?php

declare(strict_types=1);

namespace Tests\Admin;

use App\Mail\MailMessage;
use App\Mail\MailQueue;
use App\Mail\MailTransportInterface;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Services\ManagedContentService;
use DomainException;
use Tests\Support\TestCase;

final class ManagedContentServiceTest extends TestCase
{
    public function testLegalDraftIsolationAndAdultOwnerPublicationAuthorization(): void
    {
        $actor = $this->user('managed-legal-owner');
        $service = $this->service();
        $draft = $service->createDraft(
            $actor,
            'legal',
            'privacy',
            'legal-v1',
            '{{SITE_NAME}} Privacy Policy',
            "This published privacy policy applies to {{SITE_NAME}}.\n\nQuestions may be sent to {{SUPPORT_EMAIL}}.",
            'Created reviewed privacy draft',
            null,
        );

        $this->assertSame(null, $service->published('legal', 'privacy'));
        $this->expectException(DomainException::class, fn () => $service->approveLegal(
            $actor, 'privacy', (int) $draft['id'], 'Attempt without owner authority', null, false,
        ));
        $service->approveLegal($actor, 'privacy', (int) $draft['id'], 'Adult Owner approved legal text', null, true);
        $this->expectException(DomainException::class, fn () => $service->publish(
            $actor, 'legal', 'privacy', (int) $draft['id'], 'Attempt without owner authority', null, false,
        ));
        $service->publish($actor, 'legal', 'privacy', (int) $draft['id'], 'Adult Owner published approved policy', null, true);
        $this->assertSame('legal-v1', $service->published('legal', 'privacy')['version_label']);

        $service->createDraft(
            $actor,
            'legal',
            'privacy',
            'legal-v2',
            'Unpublished replacement privacy policy',
            'This newer private draft must not appear on the public privacy page before approval and publication.',
            'Started a future privacy revision',
            null,
        );
        $this->assertSame('legal-v1', $service->published('legal', 'privacy')['version_label']);

        $audit = $this->database->fetchAll("SELECT action, previous_json, new_json, reason FROM admin_audit_logs WHERE target_type = 'legal' ORDER BY id");
        $this->assertSame(4, count($audit));
        $created = json_decode((string) $audit[0]['new_json'], true);
        $this->assertTrue(str_contains((string) ($created['body_text'] ?? ''), 'privacy policy applies'));
        $this->assertSame('Adult Owner published approved policy', $audit[2]['reason']);
    }

    public function testEmailPlaceholdersAreAllowListedAndPublishedHtmlIsEscaped(): void
    {
        $actor = $this->user('managed-email-editor');
        $service = $this->service();
        $this->expectException(DomainException::class, fn () => $service->createDraft(
            $actor,
            'email_template',
            'verify-email',
            'unsafe-v1',
            'Verify your address',
            'Hello {{ARBITRARY_PHP}}. This token is deliberately not installed or allowed for this email template.',
            'Tested unknown placeholder rejection',
            null,
        ));

        $draft = $service->createDraft(
            $actor,
            'email_template',
            'verify-email',
            'email-v1',
            'Verify for {{SITE_NAME}}',
            "Hello {{RECIPIENT_NAME}}.\n\nOpen {{VERIFICATION_URL}} within {{EXPIRES_IN}}.\n\n<script>alert('never execute')</script>",
            'Created safe verification email copy',
            null,
        );
        $service->publish($actor, 'email_template', 'verify-email', (int) $draft['id'], 'Published reviewed verification copy', null, false);
        $rendered = $service->renderPublishedEmail('verify-email', [
            'recipientName' => '<Finn & family>',
            'verificationUrl' => 'https://example.test/verify?token=a&b=c',
            'expiresIn' => '24 hours',
        ], $this->app());

        $this->assertTrue(is_array($rendered));
        $this->assertTrue(str_contains($rendered['html'], '&lt;Finn &amp; family&gt;'));
        $this->assertTrue(str_contains($rendered['html'], '&lt;script&gt;'));
        $this->assertFalse(str_contains($rendered['html'], '<script>'));
        $this->assertTrue(str_contains($rendered['html'], 'a&amp;b=c'));
        $this->assertTrue(str_contains($rendered['text'], "<script>alert('never execute')</script>"));
    }

    public function testSourceTemplateFallbackAndRegistryPublishedLookupAreIndependentOfDraftWindow(): void
    {
        $queue = new MailQueue($this->database, new class implements MailTransportInterface {
            public function send(MailMessage $message): void
            {
            }
        }, $this->config);
        $fallback = $queue->renderTemplate('verify-email', [
            'verificationUrl' => 'https://example.test/source', 'recipientName' => 'Source User', 'expiresIn' => '24 hours',
        ], $this->app(), 'installed source fallback text');
        $this->assertTrue(str_contains($fallback['html'], 'Verify your email'));
        $this->assertSame('installed source fallback text', $fallback['text']);

        $actor = $this->user('managed-window-editor');
        $service = $this->service();
        $published = $service->createDraft(
            $actor, 'email_template', 'welcome', 'welcome-v1', 'Welcome to {{SITE_NAME}}',
            'Hello {{RECIPIENT_NAME}}. Your installed welcome message is ready for this account.',
            'Created initial welcome revision', null,
        );
        $service->publish($actor, 'email_template', 'welcome', (int) $published['id'], 'Published initial welcome revision', null, false);
        for ($index = 2; $index <= 14; $index++) {
            $service->createDraft(
                $actor, 'email_template', 'welcome', 'welcome-v'.$index, 'Future welcome '.$index,
                'This is private future welcome draft number '.$index.' and it must not hide the published registry state.',
                'Created future welcome draft '.$index, null,
            );
        }
        $entry = array_values(array_filter($service->registry(), static fn (array $item): bool => $item['type'] === 'email_template' && $item['key'] === 'welcome'))[0];
        $this->assertSame((int) $published['id'], (int) $entry['published']['id']);
        $this->assertSame(12, count($entry['revisions']));
    }

    public function testRollbackCreatesACloneAndRetainsHistoryWithAuditEvidence(): void
    {
        $actor = $this->user('managed-rollback-editor');
        $service = $this->service();
        $first = $service->createDraft(
            $actor, 'email_template', 'reset-password', 'reset-v1', 'Reset password',
            'Use {{RESET_URL}} within {{EXPIRES_IN}}. Ignore this message if you did not request a reset.',
            'Created first reset email revision', null,
        );
        $service->publish($actor, 'email_template', 'reset-password', (int) $first['id'], 'Published first reset email revision', null, false);
        $second = $service->createDraft(
            $actor, 'email_template', 'reset-password', 'reset-v2', 'Reset your account password',
            'Open {{RESET_URL}} within {{EXPIRES_IN}}. Contact support if the request was not yours.',
            'Created second reset email revision', null,
        );
        $service->publish($actor, 'email_template', 'reset-password', (int) $second['id'], 'Published second reset email revision', null, false);
        $rollback = $service->rollback(
            $actor, 'email_template', 'reset-password', (int) $first['id'], 'Rolled back after reviewed copy regression', null, false,
        );

        $this->assertNotSame((int) $first['id'], (int) $rollback['id']);
        $this->assertSame((int) $first['id'], (int) $rollback['based_on_revision_id']);
        $this->assertSame($first['body_text'], $rollback['body_text']);
        $this->assertSame((int) $rollback['id'], (int) $service->published('email_template', 'reset-password')['id']);
        $this->assertSame(3, (int) $this->database->fetchOne("SELECT COUNT(*) AS aggregate FROM managed_content_revisions WHERE content_type = 'email_template' AND content_key = 'reset-password'")['aggregate']);
        $audit = $this->database->fetchOne("SELECT previous_json, new_json, reason FROM admin_audit_logs WHERE action = 'managed_content.rolled_back' ORDER BY id DESC LIMIT 1");
        $this->assertTrue(str_contains((string) $audit['previous_json'], 'rollback_source'));
        $this->assertTrue(str_contains((string) $audit['new_json'], 'published'));
        $this->assertSame('Rolled back after reviewed copy regression', $audit['reason']);
    }

    private function service(): ManagedContentService
    {
        return new ManagedContentService($this->database, $this->config, new AuditService($this->database, self::KEY));
    }

    /** @return array<string,mixed> */
    private function app(): array
    {
        return [
            'name' => 'The Purple Parlor', 'creator_name' => 'Lord Funion',
            'support_email' => 'support@example.test', 'url' => 'https://example.test',
        ];
    }

    private function user(string $name): int
    {
        $users = new UserRepository($this->database);
        $user = $users->create($name.'@example.test', $name, password_hash('ValidPassword-123!', PASSWORD_DEFAULT), 'active');
        $users->markVerified($user->id);
        $users->assignRole($user->id, 'member');
        return $user->id;
    }
}
