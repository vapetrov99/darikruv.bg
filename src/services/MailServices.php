<?php

/**
 * Thin wrapper around PHPMailer for transactional emails used by the platform.
 *
 * Templates use Bulgarian copy for end users; code comments are in English.
 */

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

    /**
     * Sends the post-registration email containing the verification link.
     */
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

            // Port 587 uses STARTTLS; 465 uses implicit TLS (SMTPS).
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

    /**
     * Notifies a donor by email when a compatible blood request is created.
     * Called from NotificationService when the method exists (keeps optional coupling).
     */
    public function sendRequestNotificationEmail(
        string $toEmail,
        string $toName,
        int $requestId,
        string $bloodType,
        string $city,
        string $hospital
    ): bool {
        $mail = new PHPMailer(true);
        $detailsLink = "http://localhost:8080/html/request-details.html?id={$requestId}";

        try {
            $mail->isSMTP();
            $mail->Host = $this->config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $this->config['username'];
            $mail->Password = $this->config['password'];
            $mail->Port = $this->config['port'];
            $mail->CharSet = 'UTF-8';

            if ($this->config['port'] === 465) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->setFrom($this->config['from_address'], $this->config['from_name']);
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML(true);
            $mail->Subject = 'Нова спешна заявка за кръв';
            $mail->Body = "
                <h2>Здравейте, {$toName}!</h2>
                <p>Има нова заявка за даряване на кръв в системата DariKruv.</p>
                <p><strong>Кръвна група:</strong> {$bloodType}</p>
                <p><strong>Град:</strong> {$city}</p>
                <p><strong>Болница:</strong> {$hospital}</p>
                <p>
                    <a href=\"{$detailsLink}\" style=\"display:inline-block;padding:10px 16px;background:#b30000;color:#fff;text-decoration:none;border-radius:6px;\">
                        Виж детайли на заявката
                    </a>
                </p>
            ";
            $mail->AltBody = "Нова заявка за {$bloodType} в {$city}, {$hospital}. Детайли: {$detailsLink}";

            return $mail->send();
        } catch (Exception $e) {
            error_log('Mail error: ' . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Notifies donor by email when a new blood donation campaign is published.
     */
    public function sendCampaignNotificationEmail(
        string $toEmail,
        string $toName,
        string $campaignTitle,
        string $campaignCity,
        string $campaignDate,
        string $campaignLink,
        string $campaignDescription = ''
    ): bool {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $this->config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $this->config['username'];
            $mail->Password = $this->config['password'];
            $mail->Port = $this->config['port'];
            $mail->CharSet = 'UTF-8';

            if ($this->config['port'] === 465) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->setFrom($this->config['from_address'], $this->config['from_name']);
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML(true);
            $mail->Subject = 'Нова кръводарителска кампания';

            $safeDescription = trim($campaignDescription) !== ''
                ? "<p><strong>Детайли:</strong> {$campaignDescription}</p>"
                : "";

            $mail->Body = "
                <h2>Здравейте, {$toName}!</h2>
                <p>Публикувана е нова кръводарителска кампания в DariKruv.</p>
                <p><strong>Кампания:</strong> {$campaignTitle}</p>
                <p><strong>Град:</strong> {$campaignCity}</p>
                <p><strong>Кога:</strong> {$campaignDate}</p>
                {$safeDescription}
                <p>
                    <a href=\"{$campaignLink}\" style=\"display:inline-block;padding:10px 16px;background:#b30000;color:#fff;text-decoration:none;border-radius:6px;\">
                        Виж кампанията
                    </a>
                </p>
            ";
            $mail->AltBody = "Нова кръводарителска кампания: {$campaignTitle} ({$campaignCity}, {$campaignDate}). Линк: {$campaignLink}";

            return $mail->send();
        } catch (Exception $e) {
            error_log('Mail error: ' . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Sends one-time password reset email with an expiration-limited link.
     */
    public function sendPasswordResetEmail(string $toEmail, string $toName, string $resetLink): bool
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

            if ($this->config['port'] === 465) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->setFrom($this->config['from_address'], $this->config['from_name']);
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML(true);
            $mail->Subject = 'Смяна на парола в DariKruv';
            $mail->Body = "
                <h2>Здравейте, {$toName}!</h2>
                <p>Получихме заявка за смяна на паролата в DariKruv.</p>
                <p>Линкът е валиден 60 минути:</p>
                <p>
                    <a href=\"{$resetLink}\" style=\"display:inline-block;padding:10px 16px;background:#b30000;color:#fff;text-decoration:none;border-radius:6px;\">
                        Смени паролата
                    </a>
                </p>
                <p>Ако не сте заявявали смяна на парола, игнорирайте това съобщение.</p>
                <p>Резервен линк: {$resetLink}</p>
            ";
            $mail->AltBody = "Смяна на парола (валиден 60 минути): {$resetLink}";

            return $mail->send();
        } catch (Exception $e) {
            error_log('Mail error: ' . $mail->ErrorInfo);
            return false;
        }
    }
}
