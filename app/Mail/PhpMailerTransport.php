<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\Config;
use RuntimeException;

final class PhpMailerTransport implements MailTransportInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function send(MailMessage $message): void
    {
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            throw new RuntimeException('PHPMailer is not installed. Run composer install locally and upload vendor/.');
        }
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $driver = strtolower(trim((string) $this->config->get('app.mail.driver', 'smtp')));
        if ($driver === 'smtp') {
            $mail->isSMTP();
            $mail->Host = (string) $this->config->require('app.mail.host');
            $mail->Port = (int) $this->config->get('app.mail.port', 587);
            $mail->SMTPAuth = true;
            $mail->Username = (string) $this->config->require('app.mail.username');
            $mail->Password = (string) $this->config->require('app.mail.password');
            $encryption = strtolower((string) $this->config->get('app.mail.encryption', 'tls'));
            if ($encryption === 'tls') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($encryption === 'ssl' || $encryption === 'smtps') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption !== 'none' && $encryption !== '') {
                throw new RuntimeException('Unsupported SMTP encryption mode.');
            }
        } elseif ($driver === 'mail') {
            // cPanel supplies a local mail transfer agent. This mode avoids
            // storing a mailbox password in the application configuration.
            $mail->isMail();
        } elseif ($driver === 'sendmail') {
            $mail->isSendmail();
        } else {
            throw new RuntimeException('Unsupported mail transport driver.');
        }
        $mail->CharSet = 'UTF-8';
        $mail->setFrom((string) $this->config->require('app.mail.from_address'), (string) $this->config->get('app.mail.from_name', 'The Purple Parlor'));
        $mail->addAddress($message->toEmail, $message->toName);
        $mail->Subject = $message->subject;
        $mail->isHTML(true);
        $mail->Body = $message->htmlBody;
        $mail->AltBody = $message->textBody;
        foreach ($message->headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]+$/', (string) $name) && !preg_match('/[\r\n]/', (string) $value)) {
                $mail->addCustomHeader((string) $name, (string) $value);
            }
        }
        $mail->send();
    }
}
