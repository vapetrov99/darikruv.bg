<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

class MailService
{
    private array $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config/mail.php';
    }

    public function sendVerificationEmail(string $toEmail, string $toName, string $verificationLink): bool
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $this->config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $this->config['username'];
            $mail->Password = $this->config['password'];
            $mail->Port = $this->config['port'];
            $mail->CharSet = 'UTF-8';

            // 587 = STARTTLS, 465 = SMTPS
            if ($this->config['port'] === 465) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->setFrom($this->config['from_address'], $this->config['from_name']);
            $mail->addAddress($toEmail, $toName);

            $mail->isHTML(true);
            $mail->Subject = 'Потвърждение на регистрацията в DariKruv';
            $mail->Body = "
                <h2>Здравейте, {$toName}!</h2>
                <p>Благодарим Ви за регистрацията в DariKruv.</p>
                <p>За да потвърдите своя имейл, натиснете бутона по-долу:</p>
                <p>
                    <a href=\"{$verificationLink}\" style=\"display:inline-block;padding:10px 16px;background:#b30000;color:#fff;text-decoration:none;border-radius:6px;\">
                        Потвърди имейла
                    </a>
                </p>
                <p>Ако бутонът не работи, използвайте този линк:</p>
                <p>{$verificationLink}</p>
            ";
            $mail->AltBody = "Потвърдете имейла си: {$verificationLink}";

            return $mail->send();
        } catch (Exception $e) {
            error_log('Mail error: ' . $mail->ErrorInfo);
            return false;
        }
    }
}