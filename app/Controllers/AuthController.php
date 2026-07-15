<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Auth\AuthenticationException;
use App\Auth\SessionManager;
use App\Core\Config;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Database\Database;
use App\Http\Application;
use App\Http\GuestWallet;
use App\Http\View;
use App\Mail\MailMessage;
use App\Mail\MailQueue;
use App\Repositories\UserRepository;
use App\Security\UrlGuard;
use App\Services\EngagementProgressService;
use App\Services\GuestConversionService;
use App\Services\LegalAcceptanceService;
use Throwable;

final class AuthController extends BaseController
{
    public function __construct(
        View $view,
        Config $config,
        Database $database,
        AuthService $auth,
        private readonly SessionManager $sessions,
        private readonly UserRepository $users,
        private readonly MailQueue $mail,
        private readonly EngagementProgressService $engagement,
        private readonly GuestWallet $guests,
        private readonly GuestConversionService $guestConversions,
        private readonly LegalAcceptanceService $legalAcceptances,
        private readonly Logger $logger,
    ) {
        parent::__construct($view, $config, $database, $auth);
    }

    public function loginForm(Request $request): Response
    {
        return $this->page($request, 'auth/login', 'Log in', [], true);
    }

    public function login(Request $request): Response
    {
        $identifier = trim((string) $request->input('login'));
        $this->rememberInput(['login' => $identifier]);
        $result = $this->auth->login(
            $identifier,
            (string) $request->input('password'),
            $request->clientIp(),
            substr((string) $request->header('user-agent', 'unknown'), 0, 500),
            (string) $request->input('remember') === '1',
        );
        if ($result->status === 'two_factor_required') {
            $_SESSION['_login_remember'] = (string) $request->input('remember') === '1';
            return Response::redirect('/two-factor');
        }
        if ($result->successful && $result->status === 'two_factor_setup_required') {
            $this->clearOldInput();
            $this->flash('Administrator access requires two-factor authentication. Complete enrollment before opening the admin area.', 'warning');
            return Response::redirect('/settings/two-factor');
        }
        if (!$result->successful) {
            $messages = [
                'locked' => 'This account is temporarily locked after repeated failed attempts.',
                'rate_limited' => 'Too many attempts. Please wait before trying again.',
                'email_verification_required' => 'Verify your email before signing in.',
                'administrator_two_factor_setup_required' => 'Administrator two-factor setup must be completed through the protected installer or an Adult Owner.',
            ];
            $this->flash($messages[$result->status] ?? 'The login details were not accepted.', 'error');
            return Response::redirect('/login');
        }
        $this->clearOldInput();
        $response = Response::redirect(UrlGuard::localRedirect((string) $request->input('return', $request->query['return'] ?? ''), '/profile'));
        if ($result->rememberToken !== null) {
            $response = $response->withHeader('Set-Cookie', Application::cookie('purple_parlor_remember', $result->rememberToken, time() + 2592000, $request));
        }
        return $response;
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();
        return Response::redirect('/')
            ->withHeader('Set-Cookie', Application::cookie('purple_parlor_remember', '', time() - 3600, $request));
    }

    public function registerForm(Request $request): Response
    {
        return $this->page($request, 'auth/register', 'Create an account', [], true);
    }

    public function register(Request $request): Response
    {
        $email = trim((string) $request->input('email'));
        $username = trim((string) $request->input('username'));
        $displayName = trim((string) $request->input('display_name'));
        $this->rememberInput(['email' => $email, 'username' => $username, 'display_name' => $displayName]);
        if ((string) $request->input('age_confirmed') !== '1' || (string) $request->input('terms') !== '1') {
            $this->flash('Age confirmation and the site policies must be accepted.', 'error');
            return Response::redirect('/register');
        }
        $password = (string) $request->input('password');
        if (!hash_equals($password, (string) $request->input('password_confirmation'))) {
            $this->flash('The password confirmation does not match.', 'error');
            return Response::redirect('/register');
        }
        if ($displayName === '' || mb_strlen($displayName) > 40 || preg_match('/[\x00-\x1F\x7F]/u', $displayName)) {
            $this->flash('Display name must contain 1–40 ordinary characters.', 'error');
            return Response::redirect('/register');
        }
        try {
            $result = $this->database->transaction(function () use ($email, $username, $password, $request, $displayName): array {
                $registered = $this->auth->register($email, $username, $password, $request->clientIp());
                $user = $registered['user'];
                $now = gmdate('Y-m-d H:i:s');
                $this->database->execute('UPDATE user_profiles SET display_name = :name, updated_at = :updated WHERE user_id = :user', ['name' => $displayName, 'updated' => $now, 'user' => $user->id]);
                $settings = ['notifications' => ['news' => (string) $request->input('news') === '1'], 'analytics_opt_out' => true];
                $this->database->execute('UPDATE user_settings SET settings_json = :settings, updated_at = :updated WHERE user_id = :user', ['settings' => json_encode($settings, JSON_THROW_ON_ERROR), 'updated' => $now, 'user' => $user->id]);
                $this->legalAcceptances->accept($user->id, ['terms', 'privacy', 'virtual-currency']);
                return $registered;
            });
            $user = $result['user'];
            if ($this->guests->hasSession()) {
                try {
                    $token = $this->guests->conversionToken();
                    $this->guestConversions->convert($token, $user->id, 'guest-conversion:' . hash('sha256', $token), $this->guests->progressSnapshot());
                    $this->guests->forgetAfterConversion();
                } catch (Throwable) {
                    // Account creation and verification must remain usable if
                    // optional guest-history preservation cannot complete.
                }
            }
            $this->queueVerification($user->id, $result['verification_token']);
            $_SESSION['_pending_verification_user'] = $user->id;
            $_SESSION['_pending_verification_email'] = $user->email;
            $this->clearOldInput();
            return Response::redirect('/verify-email');
        } catch (Throwable $exception) {
            $this->logger->error('Account registration failed.', [
                'exception' => $exception,
                'email_hash' => hash('sha256', strtolower($email)),
                'username_hash' => hash('sha256', strtolower($username)),
            ]);
            $this->flash($exception instanceof AuthenticationException ? $exception->getMessage() : 'The account could not be created. Please try again or contact support with the request ID.', 'error');
            return Response::redirect('/register');
        }
    }

    public function verifyNotice(Request $request): Response
    {
        $email = (string) ($_SESSION['_pending_verification_email'] ?? $this->auth->currentUser()?->email ?? '');
        return $this->page($request, 'auth/verify-email', 'Verify your email', ['masked_email' => $this->maskEmail($email)], true);
    }

    public function verify(Request $request): Response
    {
        $token = (string) $request->attribute('token');
        $verification = preg_match('/^[a-f0-9]{64}$/', $token)
            ? $this->database->fetchOne('SELECT user_id, new_email FROM email_verifications WHERE token_hash = :hash AND used_at IS NULL', ['hash' => hash('sha256', $token)])
            : null;
        if ($this->auth->verifyEmail($token)) {
            if ($verification !== null && $verification['new_email'] === null) {
                $this->engagement->unlockAchievement((int) $verification['user_id'], 'welcome');
            }
            unset($_SESSION['_pending_verification_user'], $_SESSION['_pending_verification_email']);
            $this->flash(
                $verification !== null && $verification['new_email'] !== null
                    ? 'Your new email is verified. Every signed-in session was revoked; sign in again with the new address.'
                    : 'Your email is verified. You can now log in.',
                'success',
            );
            return Response::redirect('/login');
        }
        $this->flash('That verification link is invalid, expired, or already used.', 'error');
        return Response::redirect('/verify-email');
    }

    public function resendVerification(Request $request): Response
    {
        $userId = (int) ($_SESSION['_pending_verification_user'] ?? $this->auth->currentUser()?->id ?? 0);
        $user = $userId > 0 ? $this->users->findById($userId) : null;
        if ($user !== null && $user->emailVerifiedAt === null) {
            $this->queueVerification($user->id, $this->auth->issueEmailVerification($user->id));
        }
        $this->flash('If the pending account is eligible, another verification message has been queued.', 'success');
        return Response::redirect('/verify-email');
    }

    public function forgotForm(Request $request): Response
    {
        return $this->page($request, 'auth/forgot-password', 'Forgot password', [], true);
    }

    public function forgot(Request $request): Response
    {
        $result = $this->auth->requestPasswordReset(trim((string) $request->input('email')), $request->clientIp());
        if (is_array($result)) {
            $url = rtrim((string) $this->config->get('app.url'), '/') . '/reset-password/' . rawurlencode((string) $result['token']);
            $body = $this->renderEmail('reset-password', ['resetUrl' => $url, 'expiresIn' => '60 minutes'],
                "A password reset was requested. Use this one-time link within 60 minutes:\n{$url}\nIf you did not request it, ignore this message.");
            $this->mail->enqueue(new MailMessage(
                $result['user']->email,
                $result['user']->username,
                'Reset your Purple Parlor password',
                $body['html'],
                $body['text'],
            ), 'password.reset');
        }
        $this->flash('If an eligible account exists, password-reset instructions have been queued.', 'success');
        return Response::redirect('/forgot-password');
    }

    public function resetForm(Request $request): Response
    {
        return $this->page($request, 'auth/reset-password', 'Reset password', ['token' => (string) $request->attribute('token')], true);
    }

    public function reset(Request $request): Response
    {
        $password = (string) $request->input('password');
        if (!hash_equals($password, (string) $request->input('password_confirmation'))) {
            $this->flash('The password confirmation does not match.', 'error');
            return Response::redirect('/reset-password/' . rawurlencode((string) $request->input('token')));
        }
        if (strlen($password) < 12 || strlen($password) > 1024 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $password)) {
            $this->flash('Choose a password between 12 and 1024 bytes without control characters.', 'error');
            return Response::redirect('/reset-password/' . rawurlencode((string) $request->input('token')));
        }
        try {
            $success = $this->auth->resetPassword((string) $request->input('token'), $password);
        } catch (Throwable) {
            $this->flash('The password could not be updated safely. Request a new reset link or contact support with the request reference.', 'error');
            return Response::redirect('/forgot-password');
        }
        $this->flash($success ? 'Password updated. All prior sessions were revoked.' : 'That reset link is invalid, expired, or already used.', $success ? 'success' : 'error');
        return Response::redirect($success ? '/login' : '/forgot-password');
    }

    public function twoFactorForm(Request $request): Response
    {
        if ($this->sessions->pendingTwoFactorUserId() === null) {
            return Response::redirect('/login');
        }
        return $this->page($request, 'auth/two-factor', 'Two-factor authentication', [], true);
    }

    public function verifyTwoFactor(Request $request): Response
    {
        $code = trim((string) ($request->input('use_recovery') === '1' ? $request->input('recovery_code') : $request->input('code')));
        $result = $this->auth->completeTwoFactor($code, $request->clientIp(), substr((string) $request->header('user-agent', 'unknown'), 0, 500), (bool) ($_SESSION['_login_remember'] ?? false));
        unset($_SESSION['_login_remember']);
        if (!$result->successful) {
            $this->flash('The authentication or recovery code was not accepted.', 'error');
            return Response::redirect('/two-factor');
        }
        $response = Response::redirect('/admin');
        if ($result->rememberToken !== null) {
            $response = $response->withHeader('Set-Cookie', Application::cookie('purple_parlor_remember', $result->rememberToken, time() + 2592000, $request));
        }
        return $response;
    }

    public function confirmPasswordForm(Request $request): Response
    {
        return $this->page($request, 'auth/confirm-password', 'Confirm your identity', ['intent' => UrlGuard::localRedirect((string) $request->input('return'), '/profile')], true);
    }

    public function confirmPassword(Request $request): Response
    {
        if (!$this->auth->reauthenticate((string) $request->input('password'), ($code = trim((string) $request->input('two_factor_code'))) === '' ? null : $code)) {
            $this->flash('Your password or two-factor code was not accepted.', 'error');
            return Response::redirect('/confirm-password');
        }
        $userId = $this->auth->currentUser()?->id ?? 0;
        $userAgent = substr((string) $request->header('user-agent', 'unknown'), 0, 500);
        $proof = $this->sessions->issuePrivilegedProof($userId, $userAgent);
        $ttl = max(60, (int) $this->config->get('app.session.privileged_minutes', 15) * 60);
        return Response::redirect(UrlGuard::localRedirect((string) $request->input('intent'), '/profile'))
            ->withAddedHeader('Set-Cookie', Application::cookie('__Host-purple_parlor_privileged', $proof, time() + $ttl, $request, 'Strict'));
    }

    private function queueVerification(int $userId, string $token): void
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            return;
        }
        $url = rtrim((string) $this->config->get('app.url'), '/') . '/verify-email/' . rawurlencode($token);
        $body = $this->renderEmail('verify-email', ['verificationUrl' => $url, 'recipientName' => $user->username, 'expiresIn' => '24 hours'],
            "Verify your Purple Parlor email within 24 hours:\n{$url}\nIf you did not create this account, ignore this message.");
        $this->mail->enqueue(new MailMessage(
            $user->email,
            $user->username,
            'Verify your Purple Parlor email',
            $body['html'],
            $body['text'],
        ), 'email.verify');
    }

    /** @param array<string,mixed> $variables @return array{html:string,text:string} */
    private function renderEmail(string $template, array $variables, string $fallbackText): array
    {
        return $this->mail->renderTemplate($template, $variables, $this->appData(), $fallbackText);
    }

    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return '';
        }
        [$local, $domain] = explode('@', $email, 2);
        return mb_substr($local, 0, 1) . str_repeat('•', max(2, min(6, mb_strlen($local) - 1))) . '@' . $domain;
    }
}
