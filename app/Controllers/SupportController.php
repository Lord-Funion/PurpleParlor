<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Database\Database;
use App\Http\View;
use App\Mail\MailMessage;
use App\Mail\MailQueue;

final class SupportController extends BaseController
{
    public function __construct(View $view, Config $config, Database $database, AuthService $auth, private readonly MailQueue $mail)
    {
        parent::__construct($view, $config, $database, $auth);
    }

    public function contact(Request $request): Response
    {
        if ($this->spam($request, 'website')) {
            return $this->accepted('/contact');
        }
        $name = mb_substr(trim((string) $request->input('name')), 0, 80);
        $email = trim((string) $request->input('email'));
        $topic = mb_substr(trim((string) $request->input('topic')), 0, 80);
        $message = mb_substr(trim((string) $request->input('message')), 0, 4000);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $topic === '' || $message === '' || (string) $request->input('consent') !== '1') {
            $this->rememberInput($request->body);
            $this->flash('Complete the required contact fields and consent.', 'error');
            return Response::redirect('/contact');
        }
        $id = $this->database->insert('INSERT INTO contact_messages (user_id, name, email, subject, message, status, spam_score, submitted_at) VALUES (:user, :name, :email, :subject, :message, :status, 0, :submitted)', ['user' => $this->userId(), 'name' => $name, 'email' => $email, 'subject' => $topic, 'message' => $message, 'status' => 'new', 'submitted' => gmdate('Y-m-d H:i:s')]);
        $this->queueSupportNotice('General contact #' . $id, $name, $email, $topic, $message);
        $this->clearOldInput();
        return $this->accepted('/contact');
    }

    public function billing(Request $request): Response
    {
        $topic = mb_substr(trim((string) $request->input('topic')), 0, 80);
        $reference = mb_substr(trim((string) $request->input('reference')), 0, 80);
        $message = mb_substr(trim((string) $request->input('message')), 0, 3000);
        if ($topic === '' || $message === '') {
            $this->flash('Choose a billing topic and include a message.', 'error');
            return Response::redirect('/billing/support');
        }
        $user = $this->auth->currentUser();
        $id = $this->database->insert("INSERT INTO support_tickets (user_id, subject, status, priority, created_at, updated_at) VALUES (:user, :subject, 'open', 'normal', :created, :created)", ['user' => $user->id, 'subject' => mb_substr($topic . ($reference === '' ? '' : ' [' . $reference . ']'), 0, 150), 'created' => gmdate('Y-m-d H:i:s')]);
        $this->database->execute('INSERT INTO contact_messages (user_id, name, email, subject, message, status, spam_score, submitted_at) VALUES (:user, :name, :email, :subject, :message, :status, 0, :submitted)', ['user' => $user->id, 'name' => $user->username, 'email' => $user->email, 'subject' => 'Billing ticket #' . $id, 'message' => $message, 'status' => 'new', 'submitted' => gmdate('Y-m-d H:i:s')]);
        $this->queueSupportNotice('Billing ticket #' . $id, $user->username, $user->email, $topic, $message);
        $this->flash('Your billing request was recorded. Keep ticket #' . $id . ' for reference.', 'success');
        return Response::redirect('/billing/support');
    }

    public function inquiry(Request $request): Response
    {
        if ($this->spam($request, 'company_site_confirmation')) {
            return $this->accepted($request->input('inquiry_type') === 'sponsor.index' ? '/sponsor' : '/licensing');
        }
        $name = mb_substr(trim((string) $request->input('name')), 0, 80);
        $business = mb_substr(trim((string) $request->input('business_name')), 0, 120);
        $email = trim((string) $request->input('email'));
        $service = mb_substr(trim((string) $request->input('service')), 0, 100);
        $budget = mb_substr(trim((string) $request->input('budget_range')), 0, 100);
        $message = mb_substr(trim((string) $request->input('message')), 0, 5000);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $service === '' || $message === '' || (string) $request->input('consent') !== '1') {
            $this->flash('Complete every required business-inquiry field.', 'error');
            return Response::redirect('/licensing#inquiry');
        }
        $id = $this->database->insert('INSERT INTO licensing_inquiries (name, business_name, email, service_requested, budget_range, message, consent, spam_score, status, submitted_at) VALUES (:name, :business, :email, :service, :budget, :message, 1, 0, :status, :submitted)', ['name' => $name, 'business' => $business ?: null, 'email' => $email, 'service' => $service, 'budget' => $budget ?: null, 'message' => $message, 'status' => 'new', 'submitted' => gmdate('Y-m-d H:i:s')]);
        $this->queueSupportNotice('Business inquiry #' . $id, $name, $email, $service, $message);
        return $this->accepted('/licensing');
    }

    public function reportProfile(Request $request): Response
    {
        $reported = mb_substr((string) $request->attribute('username'), 0, 50);
        $reporter = $this->auth->currentUser();
        $this->database->execute('INSERT INTO contact_messages (user_id, name, email, subject, message, status, spam_score, submitted_at) VALUES (:user, :name, :email, :subject, :message, :status, 0, :submitted)', ['user' => $reporter->id, 'name' => $reporter->username, 'email' => $reporter->email, 'subject' => 'Profile moderation report', 'message' => 'Reported public profile: ' . $reported, 'status' => 'new', 'submitted' => gmdate('Y-m-d H:i:s')]);
        $this->flash('The public profile was sent to moderation for review.', 'success');
        return Response::redirect('/u/' . rawurlencode($reported));
    }

    private function spam(Request $request, string $honeypot): bool
    {
        if (trim((string) $request->input($honeypot)) !== '') return true;
        $started = (int) $request->input('form_started_at', 0);
        return $started > 0 && $started > time() - 2;
    }

    private function accepted(string $path): Response
    {
        $this->flash('Thank you. Your message was recorded for private review.', 'success');
        return Response::redirect($path);
    }

    private function queueSupportNotice(string $reference, string $name, string $replyEmail, string $subject, string $message): void
    {
        $support = (string) $this->config->get('app.support_email', $this->config->get('app.mail.from_address'));
        if (!filter_var($support, FILTER_VALIDATE_EMAIL)) return;
        $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $this->mail->enqueue(new MailMessage($support, 'Purple Parlor support', $reference . ': ' . mb_substr($subject, 0, 100), '<h1>' . htmlspecialchars($reference) . '</h1><p>From ' . htmlspecialchars($name) . ' (' . htmlspecialchars($replyEmail) . ')</p><p>' . nl2br($safe) . '</p>', $reference . "\nFrom: {$name} <{$replyEmail}>\n\n{$message}"), 'support.received');
    }
}
