<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\Config;
use App\Database\Database;
use App\Services\ManagedContentService;
use InvalidArgumentException;
use Throwable;

final class MailQueue
{
    private readonly ManagedContentService $managedContent;

    public function __construct(
        private readonly Database $database,
        private readonly MailTransportInterface $transport,
        private readonly Config $config,
    ) {
        $this->managedContent = new ManagedContentService($database, $config);
    }

    /**
     * Resolves a published plain-text override, or renders the installed source
     * template when no valid published revision exists. Drafts are never read.
     *
     * @param array<string,mixed> $variables
     * @param array<string,mixed> $app
     * @return array{html:string,text:string}
     */
    public function renderTemplate(string $template, array $variables, array $app, string $fallbackText): array
    {
        if (!in_array($template, ManagedContentService::emailKeys(), true)) {
            throw new InvalidArgumentException('Select an installed email template.');
        }
        $published = $this->managedContent->renderPublishedEmail($template, $variables, $app);
        if ($published !== null) {
            return $published;
        }

        extract($variables, EXTR_SKIP);
        $path = dirname(__DIR__, 2) . '/resources/email/' . $template . '.php';
        ob_start();
        try {
            require $path;
            return ['html' => (string) ob_get_clean(), 'text' => $fallbackText];
        } catch (Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
    }

    public function enqueue(MailMessage $message, string $templateKey, ?string $availableAt = null): int
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{2,99}$/', $templateKey)) {
            throw new \InvalidArgumentException('Invalid mail template key.');
        }
        return $this->database->insert('INSERT INTO email_queue (recipient_email, recipient_name, template_key, subject, html_body, text_body, headers_json, status, attempts, available_at, created_at)
            VALUES (:email, :name, :template, :subject, :html, :text, :headers, :status, 0, :available, :now)', [
            'email' => $message->toEmail, 'name' => $message->toName, 'template' => $templateKey, 'subject' => $message->subject,
            'html' => $message->htmlBody, 'text' => $message->textBody, 'headers' => json_encode($message->headers, JSON_THROW_ON_ERROR),
            'status' => 'queued', 'available' => $availableAt ?? gmdate('Y-m-d H:i:s'), 'now' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{sent:int,failed:int} */
    public function process(int $limit = 20): array
    {
        $sent = 0;
        $failed = 0;
        for ($i = 0; $i < max(1, min(100, $limit)); $i++) {
            $record = $this->claimNext();
            if ($record === null) {
                break;
            }
            try {
                $headers = json_decode((string) ($record['headers_json'] ?? '{}'), true);
                $message = new MailMessage((string) $record['recipient_email'], (string) ($record['recipient_name'] ?? ''), (string) $record['subject'],
                    (string) $record['html_body'], (string) $record['text_body'], is_array($headers) ? $headers : []);
                $this->transport->send($message);
                $this->database->execute("UPDATE email_queue SET status = 'sent', sent_at = :now, locked_at = NULL, last_error = NULL WHERE id = :id", ['now' => gmdate('Y-m-d H:i:s'), 'id' => $record['id']]);
                $sent++;
            } catch (Throwable $e) {
                $attempts = (int) $record['attempts'];
                $maximum = max(1, (int) $this->config->get('app.mail.max_attempts', 5));
                $status = $attempts >= $maximum ? 'failed' : 'queued';
                $available = gmdate('Y-m-d H:i:s', time() + min(3600, 60 * (2 ** min(6, $attempts))));
                $this->database->execute('UPDATE email_queue SET status = :status, available_at = :available, locked_at = NULL, last_error = :error WHERE id = :id', [
                    'status' => $status, 'available' => $available, 'error' => substr(str_replace(["\r", "\n"], ' ', $e->getMessage()), 0, 500), 'id' => $record['id'],
                ]);
                $failed++;
            }
        }
        return compact('sent', 'failed');
    }

    private function claimNext(): ?array
    {
        return $this->database->transaction(function (Database $db): ?array {
            $row = $db->fetchOne($db->forUpdate("SELECT * FROM email_queue WHERE ((status = 'queued' AND available_at <= :now) OR (status = 'sending' AND locked_at < :stale)) ORDER BY id LIMIT 1"),
                ['now' => gmdate('Y-m-d H:i:s'), 'stale' => gmdate('Y-m-d H:i:s', time() - 600)]);
            if ($row === null) {
                return null;
            }
            $db->execute("UPDATE email_queue SET status = 'sending', attempts = attempts + 1, locked_at = :now WHERE id = :id", ['now' => gmdate('Y-m-d H:i:s'), 'id' => $row['id']]);
            $row['attempts'] = (int) $row['attempts'] + 1;
            return $row;
        });
    }
}
