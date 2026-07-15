<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Database\Database;
use DomainException;
use RuntimeException;
use Throwable;

/**
 * Versioned, plain-text managed content.
 *
 * Stored content is never evaluated or included as PHP/HTML. Only a published
 * revision is available to public/email consumers, and email HTML is produced
 * by escaping the resolved plain text inside the fixed source layout.
 */
final class ManagedContentService
{
    /** @var list<string> */
    private const LEGAL_KEYS = [
        'acceptable-use', 'advertising', 'attributions', 'cookies', 'copyright',
        'privacy', 'refund', 'subscription-terms', 'terms', 'virtual-currency',
    ];

    /** @var array<string,list<string>> */
    private const EMAIL_PLACEHOLDERS = [
        'receipt' => ['ITEM_NAME', 'TOTAL_LABEL', 'PROVIDER_LABEL', 'RECEIPT_REFERENCE', 'STATUS_LABEL'],
        'reset-password' => ['RESET_URL', 'EXPIRES_IN'],
        'security-alert' => ['ALERT_TITLE', 'ALERT_MESSAGE', 'EVENT_TIME', 'DEVICE_LABEL', 'REQUEST_ID'],
        'subscription-status' => ['SUBJECT', 'HEADING', 'MESSAGE', 'PLAN_NAME', 'STATUS_LABEL', 'PAID_THROUGH', 'BILLING_URL'],
        'support-received' => ['TICKET_REFERENCE'],
        'verify-email-change' => ['VERIFICATION_URL', 'RECIPIENT_NAME', 'EXPIRES_IN'],
        'verify-email' => ['VERIFICATION_URL', 'RECIPIENT_NAME', 'EXPIRES_IN'],
        'welcome' => ['RECIPIENT_NAME', 'LOBBY_URL'],
    ];

    /** @var list<string> */
    private const LEGAL_PLACEHOLDERS = [
        'SITE_NAME', 'OWNER_NAME', 'CREATOR_NAME', 'SUPPORT_EMAIL', 'SITE_URL',
        'MINIMUM_AGE', 'JURISDICTION', 'EFFECTIVE_DATE',
        'COZY_CLUB_DAILY_COINS', 'COZY_CLUB_PLUS_DAILY_COINS',
    ];

    /** @var list<string> */
    private const EMAIL_GLOBAL_PLACEHOLDERS = ['SITE_NAME', 'CREATOR_NAME', 'SUPPORT_EMAIL', 'SITE_URL'];

    public function __construct(
        private readonly Database $database,
        private readonly Config $config,
        private readonly ?AuditService $audit = null,
    ) {
    }

    /** @return list<string> */
    public static function legalKeys(): array
    {
        return self::LEGAL_KEYS;
    }

    /** @return list<string> */
    public static function emailKeys(): array
    {
        return array_keys(self::EMAIL_PLACEHOLDERS);
    }

    /** @return list<string> */
    public static function placeholdersFor(string $type, string $key): array
    {
        $type = strtolower(trim($type));
        $key = strtolower(trim($key));
        if ($type === 'legal' && in_array($key, self::LEGAL_KEYS, true)) {
            return self::LEGAL_PLACEHOLDERS;
        }
        if ($type === 'email_template' && isset(self::EMAIL_PLACEHOLDERS[$key])) {
            return array_values(array_unique(array_merge(self::EMAIL_GLOBAL_PLACEHOLDERS, self::EMAIL_PLACEHOLDERS[$key])));
        }
        throw new DomainException('Select an installed legal document or email template.');
    }

    /** @return array<string,mixed> */
    public function createDraft(
        int $actorId,
        string $type,
        string $key,
        string $version,
        string $title,
        string $body,
        string $reason,
        ?string $ipHash,
    ): array {
        [$type, $key] = $this->typeAndKey($type, $key);
        $reason = $this->reason($reason);
        $version = $this->version($version);
        [$title, $body, $placeholders] = $this->validatedContent($type, $key, $title, $body);

        return $this->database->transaction(function (Database $db) use ($actorId, $type, $key, $version, $title, $body, $placeholders, $reason, $ipHash): array {
            if ($db->fetchOne('SELECT 1 FROM managed_content_revisions WHERE content_type = :type AND content_key = :key AND version_label = :version', [
                'type' => $type, 'key' => $key, 'version' => $version,
            ]) !== null) {
                throw new DomainException('That version already exists for this content item. Create a new version label.');
            }
            $previous = $this->latest($type, $key);
            $now = gmdate('Y-m-d H:i:s');
            $id = $db->insert('INSERT INTO managed_content_revisions
                (content_type, content_key, version_label, title_text, body_text, placeholders_json, status, based_on_revision_id, created_by, approved_by, published_by, created_at, approved_at, published_at)
                VALUES (:type, :key, :version, :title, :body, :placeholders, :status, NULL, :actor, NULL, NULL, :created, NULL, NULL)', [
                'type' => $type, 'key' => $key, 'version' => $version, 'title' => $title, 'body' => $body,
                'placeholders' => json_encode($placeholders, JSON_THROW_ON_ERROR), 'status' => 'draft',
                'actor' => $actorId, 'created' => $now,
            ]);
            $created = $this->revision($type, $key, $id);
            $this->auditor()->record(
                $actorId,
                'managed_content.draft_created',
                $type,
                $key,
                $this->snapshot($previous),
                $this->snapshot($created),
                $reason,
                $ipHash,
            );
            return $created;
        });
    }

    /** @return array<string,mixed> */
    public function approveLegal(int $actorId, string $key, int $revisionId, string $reason, ?string $ipHash, bool $ownerAuthorized): array
    {
        $this->assertOwner($ownerAuthorized);
        [, $key] = $this->typeAndKey('legal', $key);
        $reason = $this->reason($reason);

        return $this->database->transaction(function (Database $db) use ($actorId, $key, $revisionId, $reason, $ipHash): array {
            $before = $db->fetchOne($db->forUpdate('SELECT * FROM managed_content_revisions WHERE id = :id AND content_type = :type AND content_key = :key'), [
                'id' => $revisionId, 'type' => 'legal', 'key' => $key,
            ]);
            if ($before === null || (string) $before['status'] !== 'draft') {
                throw new DomainException('Only an installed legal draft can be approved.');
            }
            $now = gmdate('Y-m-d H:i:s');
            $db->execute("UPDATE managed_content_revisions SET status = 'owner_approved', approved_by = :actor, approved_at = :approved WHERE id = :id", [
                'actor' => $actorId, 'approved' => $now, 'id' => $revisionId,
            ]);
            $after = $this->revision('legal', $key, $revisionId);
            $this->auditor()->record($actorId, 'managed_content.legal_approved', 'legal', $key,
                $this->snapshot($this->normalizeRow($before)), $this->snapshot($after), $reason, $ipHash);
            return $after;
        });
    }

    /** @return array<string,mixed> */
    public function publish(
        int $actorId,
        string $type,
        string $key,
        int $revisionId,
        string $reason,
        ?string $ipHash,
        bool $ownerAuthorized,
    ): array {
        [$type, $key] = $this->typeAndKey($type, $key);
        if ($type === 'legal') {
            $this->assertOwner($ownerAuthorized);
        }
        $reason = $this->reason($reason);

        return $this->database->transaction(function (Database $db) use ($actorId, $type, $key, $revisionId, $reason, $ipHash): array {
            $target = $db->fetchOne($db->forUpdate('SELECT * FROM managed_content_revisions WHERE id = :id AND content_type = :type AND content_key = :key'), [
                'id' => $revisionId, 'type' => $type, 'key' => $key,
            ]);
            if ($target === null) {
                throw new DomainException('The selected content revision was not found.');
            }
            $required = $type === 'legal' ? 'owner_approved' : 'draft';
            if ((string) $target['status'] !== $required) {
                throw new DomainException($type === 'legal'
                    ? 'Only an Adult Owner-approved legal revision can be published.'
                    : 'Only an email draft can be published.');
            }
            $current = $db->fetchOne($db->forUpdate("SELECT * FROM managed_content_revisions WHERE content_type = :type AND content_key = :key AND status = 'published' ORDER BY id DESC LIMIT 1"), [
                'type' => $type, 'key' => $key,
            ]);
            $now = gmdate('Y-m-d H:i:s');
            if ($current !== null) {
                $db->execute("UPDATE managed_content_revisions SET status = 'retired' WHERE id = :id", ['id' => $current['id']]);
            }
            $db->execute("UPDATE managed_content_revisions SET status = 'published', published_by = :actor, published_at = :published WHERE id = :id", [
                'actor' => $actorId, 'published' => $now, 'id' => $revisionId,
            ]);
            $published = $this->revision($type, $key, $revisionId);
            $this->auditor()->record($actorId, 'managed_content.published', $type, $key, [
                'published' => $this->snapshot($this->normalizeRow($current)),
                'target' => $this->snapshot($this->normalizeRow($target)),
            ], [
                'published' => $this->snapshot($published),
                'retired' => $this->snapshot($this->normalizeRow($current === null ? null : array_replace($current, ['status' => 'retired']))),
            ], $reason, $ipHash);
            return $published;
        });
    }

    /** @return array<string,mixed> */
    public function rollback(
        int $actorId,
        string $type,
        string $key,
        int $targetRevisionId,
        string $reason,
        ?string $ipHash,
        bool $ownerAuthorized,
    ): array {
        [$type, $key] = $this->typeAndKey($type, $key);
        if ($type === 'legal') {
            $this->assertOwner($ownerAuthorized);
        }
        $reason = $this->reason($reason);

        return $this->database->transaction(function (Database $db) use ($actorId, $type, $key, $targetRevisionId, $reason, $ipHash): array {
            $target = $db->fetchOne($db->forUpdate('SELECT * FROM managed_content_revisions WHERE id = :id AND content_type = :type AND content_key = :key'), [
                'id' => $targetRevisionId, 'type' => $type, 'key' => $key,
            ]);
            if ($target === null || !in_array((string) $target['status'], ['published', 'retired'], true)) {
                throw new DomainException('Rollback requires a previously published revision of this content item.');
            }
            $current = $db->fetchOne($db->forUpdate("SELECT * FROM managed_content_revisions WHERE content_type = :type AND content_key = :key AND status = 'published' ORDER BY id DESC LIMIT 1"), [
                'type' => $type, 'key' => $key,
            ]);
            if ($current === null || (int) $current['id'] === $targetRevisionId) {
                throw new DomainException('Select a prior published revision to roll back to.');
            }
            $now = gmdate('Y-m-d H:i:s');
            $version = 'rollback-' . gmdate('YmdHis') . '-' . $targetRevisionId . '-' . bin2hex(random_bytes(2));
            $version = mb_substr($version, 0, 32);
            $db->execute("UPDATE managed_content_revisions SET status = 'retired' WHERE id = :id", ['id' => $current['id']]);
            $id = $db->insert('INSERT INTO managed_content_revisions
                (content_type, content_key, version_label, title_text, body_text, placeholders_json, status, based_on_revision_id, created_by, approved_by, published_by, created_at, approved_at, published_at)
                VALUES (:type, :key, :version, :title, :body, :placeholders, :status, :based_on, :actor, :approved_by, :actor, :created, :approved_at, :published)', [
                'type' => $type, 'key' => $key, 'version' => $version, 'title' => $target['title_text'],
                'body' => $target['body_text'], 'placeholders' => $target['placeholders_json'], 'status' => 'published',
                'based_on' => $targetRevisionId, 'actor' => $actorId,
                'approved_by' => $type === 'legal' ? $actorId : null,
                'created' => $now, 'approved_at' => $type === 'legal' ? $now : null, 'published' => $now,
            ]);
            $rolledBack = $this->revision($type, $key, $id);
            $this->auditor()->record($actorId, 'managed_content.rolled_back', $type, $key, [
                'published' => $this->snapshot($this->normalizeRow($current)),
                'rollback_source' => $this->snapshot($this->normalizeRow($target)),
            ], [
                'published' => $this->snapshot($rolledBack),
                'retired' => $this->snapshot($this->normalizeRow(array_replace($current, ['status' => 'retired']))),
            ], $reason, $ipHash);
            return $rolledBack;
        });
    }

    /** @return array<string,mixed>|null */
    public function published(string $type, string $key): ?array
    {
        [$type, $key] = $this->typeAndKey($type, $key);
        return $this->normalizeRow($this->database->fetchOne(
            "SELECT * FROM managed_content_revisions WHERE content_type = :type AND content_key = :key AND status = 'published' ORDER BY id DESC LIMIT 1",
            ['type' => $type, 'key' => $key],
        ));
    }

    /** @return array<string,mixed> */
    public function revision(string $type, string $key, int $id): array
    {
        [$type, $key] = $this->typeAndKey($type, $key);
        $row = $this->normalizeRow($this->database->fetchOne(
            'SELECT * FROM managed_content_revisions WHERE id = :id AND content_type = :type AND content_key = :key',
            ['id' => $id, 'type' => $type, 'key' => $key],
        ));
        if ($row === null) {
            throw new DomainException('The selected content revision was not found.');
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function registry(): array
    {
        $registry = [];
        foreach (['legal' => self::LEGAL_KEYS, 'email_template' => array_keys(self::EMAIL_PLACEHOLDERS)] as $type => $keys) {
            foreach ($keys as $key) {
                $revisions = array_map(fn (array $row): array => $this->normalizeRow($row) ?? [], $this->database->fetchAll(
                    'SELECT * FROM managed_content_revisions WHERE content_type = :type AND content_key = :key ORDER BY id DESC LIMIT 12',
                    ['type' => $type, 'key' => $key],
                ));
                $revisions = array_values(array_filter($revisions));
                // Publication state must not depend on the bounded history
                // window: many newer drafts cannot hide the live revision.
                $published = $this->published($type, $key);
                $registry[] = [
                    'type' => $type,
                    'key' => $key,
                    'label' => ucwords(str_replace(['-', '_'], ' ', $key)),
                    'placeholders' => self::placeholdersFor($type, $key),
                    'latest' => $revisions[0] ?? null,
                    'published' => $published,
                    'revisions' => $revisions,
                ];
            }
        }
        return $registry;
    }

    /**
     * @param array<string,mixed> $variables
     * @param array<string,mixed> $app
     * @return array{html:string,text:string}|null
     */
    public function renderPublishedEmail(string $key, array $variables, array $app): ?array
    {
        try {
            [, $key] = $this->typeAndKey('email_template', $key);
            $revision = $this->published('email_template', $key);
            if ($revision === null) {
                return null;
            }
            [$title, $body] = $this->validatedContent('email_template', $key, (string) $revision['title_text'], (string) $revision['body_text']);
            $replacements = $this->emailReplacements($key, $variables, $app);
            $title = $this->replace($title, $replacements);
            $body = $this->replace($body, $replacements);
            $emailTitle = $title;
            $emailPreheader = mb_substr(trim(preg_replace('/\s+/u', ' ', $body) ?? ''), 0, 160);
            $emailContent = $this->escapedEmailBody($body);

            ob_start();
            try {
                require dirname(__DIR__, 2) . '/resources/email/layout.php';
                $html = (string) ob_get_clean();
            } catch (Throwable $exception) {
                ob_end_clean();
                throw $exception;
            }
            return ['html' => $html, 'text' => $title . "\n\n" . $body];
        } catch (DomainException) {
            // A tampered or obsolete database row must fail closed to the source template.
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    private function latest(string $type, string $key): ?array
    {
        return $this->normalizeRow($this->database->fetchOne(
            'SELECT * FROM managed_content_revisions WHERE content_type = :type AND content_key = :key ORDER BY id DESC LIMIT 1',
            ['type' => $type, 'key' => $key],
        ));
    }

    /** @return array{0:string,1:string} */
    private function typeAndKey(string $type, string $key): array
    {
        $type = strtolower(trim($type));
        $key = strtolower(trim($key));
        self::placeholdersFor($type, $key);
        return [$type, $key];
    }

    /** @return array{0:string,1:string,2:list<string>} */
    private function validatedContent(string $type, string $key, string $title, string $body): array
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
        if (mb_strlen($title) < 3 || mb_strlen($title) > 200 || preg_match('/[\x00-\x1F\x7F]/u', $title) === 1) {
            throw new DomainException('Content title must be 3-200 plain-text characters on one line.');
        }
        $maximum = $type === 'legal' ? 50_000 : 20_000;
        if (mb_strlen($body) < 20 || mb_strlen($body) > $maximum || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $body) === 1) {
            throw new DomainException("Content body must be 20-{$maximum} plain-text characters without unsupported control characters.");
        }
        $allowed = self::placeholdersFor($type, $key);
        $placeholders = array_values(array_unique(array_merge(
            $this->validatePlaceholders($title, $allowed),
            $this->validatePlaceholders($body, $allowed),
        )));
        sort($placeholders);
        return [$title, $body, $placeholders];
    }

    /** @param list<string> $allowed @return list<string> */
    private function validatePlaceholders(string $text, array $allowed): array
    {
        preg_match_all('/\{\{([A-Z][A-Z0-9_]*)\}\}/', $text, $matches);
        $placeholders = array_values(array_unique($matches[1] ?? []));
        $withoutValid = preg_replace('/\{\{[A-Z][A-Z0-9_]*\}\}/', '', $text) ?? $text;
        if (str_contains($withoutValid, '{{') || str_contains($withoutValid, '}}')) {
            throw new DomainException('Placeholders must use the exact {{ALLOW_LISTED_NAME}} format.');
        }
        foreach ($placeholders as $placeholder) {
            if (!in_array($placeholder, $allowed, true)) {
                throw new DomainException('Placeholder {{'.$placeholder.'}} is not allowed for this content item.');
            }
        }
        return $placeholders;
    }

    private function reason(string $reason): string
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        if (mb_strlen($reason) < 8 || mb_strlen($reason) > 500 || preg_match('/[\x00-\x1F\x7F]/u', $reason) === 1) {
            throw new DomainException('A plain-text reason between 8 and 500 characters is required.');
        }
        return $reason;
    }

    private function version(string $version): string
    {
        $version = trim($version);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/', $version) !== 1) {
            throw new DomainException('Version must be 1-32 letters, numbers, periods, underscores, or hyphens.');
        }
        return $version;
    }

    private function assertOwner(bool $ownerAuthorized): void
    {
        if (!$ownerAuthorized) {
            throw new DomainException('A reauthenticated Adult Owner is required to approve, publish, or roll back legal content.');
        }
    }

    private function auditor(): AuditService
    {
        if (!$this->audit instanceof AuditService) {
            throw new RuntimeException('Managed content mutations require the audit service.');
        }
        return $this->audit;
    }

    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    private function normalizeRow(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $placeholders = json_decode((string) ($row['placeholders_json'] ?? '[]'), true);
        $row['id'] = (int) $row['id'];
        $row['based_on_revision_id'] = isset($row['based_on_revision_id']) ? (int) $row['based_on_revision_id'] : null;
        $row['created_by'] = (int) $row['created_by'];
        $row['approved_by'] = isset($row['approved_by']) ? (int) $row['approved_by'] : null;
        $row['published_by'] = isset($row['published_by']) ? (int) $row['published_by'] : null;
        $row['placeholders'] = is_array($placeholders) ? array_values(array_map('strval', $placeholders)) : [];
        unset($row['placeholders_json']);
        return $row;
    }

    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    private function snapshot(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        return array_intersect_key($row, array_flip([
            'id', 'content_type', 'content_key', 'version_label', 'title_text', 'body_text', 'placeholders',
            'status', 'based_on_revision_id', 'created_by', 'approved_by', 'published_by',
            'created_at', 'approved_at', 'published_at',
        ]));
    }

    /** @param array<string,mixed> $variables @param array<string,mixed> $app @return array<string,string> */
    private function emailReplacements(string $key, array $variables, array $app): array
    {
        $variableNames = [
            'ITEM_NAME' => 'itemName', 'TOTAL_LABEL' => 'totalLabel', 'PROVIDER_LABEL' => 'providerLabel',
            'RECEIPT_REFERENCE' => 'receiptReference', 'STATUS_LABEL' => 'statusLabel',
            'RESET_URL' => 'resetUrl', 'EXPIRES_IN' => 'expiresIn', 'ALERT_TITLE' => 'alertTitle',
            'ALERT_MESSAGE' => 'alertMessage', 'EVENT_TIME' => 'eventTime', 'DEVICE_LABEL' => 'deviceLabel',
            'REQUEST_ID' => 'requestId', 'SUBJECT' => 'subject', 'HEADING' => 'heading', 'MESSAGE' => 'message',
            'PLAN_NAME' => 'planName', 'PAID_THROUGH' => 'paidThrough', 'BILLING_URL' => 'billingUrl',
            'TICKET_REFERENCE' => 'ticketReference', 'VERIFICATION_URL' => 'verificationUrl',
            'RECIPIENT_NAME' => 'recipientName', 'LOBBY_URL' => 'lobbyUrl',
        ];
        $values = [
            'SITE_NAME' => $this->scalar($app['name'] ?? $this->config->get('app.name', 'The Purple Parlor')),
            'CREATOR_NAME' => $this->scalar($app['creator_name'] ?? $this->config->get('app.creator_name', 'Lord Funion')),
            'SUPPORT_EMAIL' => $this->scalar($app['support_email'] ?? $this->config->get('app.support_email', 'support@example.invalid')),
            'SITE_URL' => $this->scalar($app['url'] ?? $this->config->get('app.url', '')),
        ];
        foreach (self::EMAIL_PLACEHOLDERS[$key] as $placeholder) {
            $variable = $variableNames[$placeholder] ?? '';
            $values[$placeholder] = $this->scalar($variables[$variable] ?? '[not provided]');
        }
        return $values;
    }

    private function scalar(mixed $value): string
    {
        return is_scalar($value) || $value === null ? (string) $value : '[not provided]';
    }

    /** @param array<string,string> $replacements */
    private function replace(string $text, array $replacements): string
    {
        $tokens = [];
        foreach ($replacements as $key => $value) {
            $tokens['{{' . $key . '}}'] = $value;
        }
        return strtr($text, $tokens);
    }

    private function escapedEmailBody(string $body): string
    {
        $blocks = preg_split('/\n{2,}/', trim($body), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $html = '';
        foreach ($blocks as $block) {
            $escaped = htmlspecialchars(trim($block), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<p style="margin:0 0 18px;color:#ddd0e6;line-height:1.6">' . nl2br($escaped, false) . '</p>';
        }
        return $html;
    }
}
