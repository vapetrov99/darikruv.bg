<?php

/**
 * Server-side notifications: FCM push for donors and optional email via MailService.
 *
 * Triggered after a blood request is inserted (see create_request.php). Eligible donors are those
 * whose blood type can donate to the requested type, who are verified, available, and have a row in donors.
 * Each delivery attempt is written to notification_logs for auditing.
 */

require_once __DIR__ . '/MailServices.php';

class NotificationService
{
    private PDO $pdo;
    private array $pushConfig;
    private MailService $mailService;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pushConfig = require __DIR__ . '/../config/push.php';
        $this->mailService = new MailService();
    }

    /**
     * Main entry: find donors compatible with $bloodType, send push now, queue emails for async worker.
     */
    public function notifyDonorsForRequest(int $requestId, string $bloodType, string $city, string $hospital): void
    {
        $eligibleDonors = $this->findEligibleDonors($bloodType);
        if (count($eligibleDonors) === 0) {
            return;
        }

        foreach ($eligibleDonors as $donor) {
            // User-visible notification body (Bulgarian product copy).
            $messageBody = sprintf(
                'Нова заявка за %s в %s (%s). Виж детайли в платформата.',
                $bloodType,
                $city,
                $hospital
            );

            $pushSent = $this->sendPushToDonor((int)$donor['id'], $requestId, $messageBody);
            $this->logDelivery($requestId, (int)$donor['id'], 'push', $pushSent ? 'sent' : 'failed', $pushSent ? null : 'Push delivery failed');

            $fullName = trim(($donor['first_name'] ?? '') . ' ' . ($donor['last_name'] ?? ''));
            $wantsEmail = (int)($donor['email_notifications'] ?? 0) === 1;
            if (
                $wantsEmail
                && method_exists($this->mailService, 'sendRequestNotificationEmail')
            ) {
                $this->enqueueRequestEmail(
                    $requestId,
                    (int)$donor['id'],
                    (string)$donor['email'],
                    $fullName !== '' ? $fullName : 'Донор',
                    $bloodType,
                    $city,
                    $hospital
                );
            }
        }

        // Optional inline draining to reduce queue growth during low traffic.
        try {
            $this->processEmailQueue(5);
        } catch (Throwable $e) {
            error_log('Email queue process error: ' . $e->getMessage());
        }
    }

    /**
     * Enqueues request notification email for asynchronous processing.
     *
     * If a queue row already exists for this request+donor pair, reset it back to pending.
     */
    private function enqueueRequestEmail(
        int $requestId,
        int $donorUserId,
        string $email,
        string $name,
        string $bloodType,
        string $city,
        string $hospital
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO email_queue (
                request_id,
                donor_user_id,
                to_email,
                to_name,
                blood_type,
                city,
                hospital,
                status,
                attempts,
                max_attempts,
                next_attempt_at,
                last_error
            )
            VALUES (
                :request_id,
                :donor_user_id,
                :to_email,
                :to_name,
                :blood_type,
                :city,
                :hospital,
                'pending',
                0,
                3,
                CURRENT_TIMESTAMP,
                NULL
            )
            ON DUPLICATE KEY UPDATE
                to_email = VALUES(to_email),
                to_name = VALUES(to_name),
                blood_type = VALUES(blood_type),
                city = VALUES(city),
                hospital = VALUES(hospital),
                status = 'pending',
                attempts = 0,
                next_attempt_at = CURRENT_TIMESTAMP,
                last_error = NULL
        ");
        $stmt->execute([
            ':request_id' => $requestId,
            ':donor_user_id' => $donorUserId,
            ':to_email' => $email,
            ':to_name' => $name,
            ':blood_type' => $bloodType,
            ':city' => $city,
            ':hospital' => $hospital
        ]);
    }

    /**
     * Drains a batch from email_queue and attempts SMTP delivery.
     *
     * @return array{selected:int,sent:int,failed:int,deferred:int}
     */
    public function processEmailQueue(int $batchSize = 20): array
    {
        $batchSize = max(1, min(100, $batchSize));

        $stmt = $this->pdo->prepare("
            SELECT
                id,
                request_id,
                donor_user_id,
                to_email,
                to_name,
                blood_type,
                city,
                hospital,
                attempts,
                max_attempts
            FROM email_queue
            WHERE status = 'pending'
              AND next_attempt_at <= CURRENT_TIMESTAMP
            ORDER BY id ASC
            LIMIT :batch_size
        ");
        $stmt->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
        $stmt->execute();
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $result = [
            'selected' => count($jobs),
            'sent' => 0,
            'failed' => 0,
            'deferred' => 0
        ];

        foreach ($jobs as $job) {
            $sent = (bool)call_user_func(
                [$this->mailService, 'sendRequestNotificationEmail'],
                (string)$job['to_email'],
                (string)$job['to_name'],
                (int)$job['request_id'],
                (string)$job['blood_type'],
                (string)$job['city'],
                (string)$job['hospital']
            );

            if ($sent) {
                $updateSentStmt = $this->pdo->prepare("
                    UPDATE email_queue
                    SET status = 'sent',
                        sent_at = CURRENT_TIMESTAMP,
                        last_error = NULL
                    WHERE id = :id
                ");
                $updateSentStmt->execute([':id' => $job['id']]);
                $this->logDelivery((int)$job['request_id'], (int)$job['donor_user_id'], 'email', 'sent', null);
                $result['sent']++;
                continue;
            }

            $error = $this->mailService->getLastError() ?: 'Email delivery failed';
            $attempts = (int)$job['attempts'] + 1;
            $maxAttempts = (int)$job['max_attempts'];

            if ($attempts >= $maxAttempts) {
                $updateFailedStmt = $this->pdo->prepare("
                    UPDATE email_queue
                    SET status = 'failed',
                        attempts = :attempts,
                        last_error = :last_error
                    WHERE id = :id
                ");
                $updateFailedStmt->execute([
                    ':attempts' => $attempts,
                    ':last_error' => substr($error, 0, 250),
                    ':id' => $job['id']
                ]);
                $this->logDelivery((int)$job['request_id'], (int)$job['donor_user_id'], 'email', 'failed', $error);
                $result['failed']++;
                continue;
            }

            $retryDelaySeconds = min(300, 30 * $attempts);
            $nextAttempt = date('Y-m-d H:i:s', time() + $retryDelaySeconds);
            $updateDeferredStmt = $this->pdo->prepare("
                UPDATE email_queue
                SET attempts = :attempts,
                    next_attempt_at = :next_attempt_at,
                    last_error = :last_error
                WHERE id = :id
            ");
            $updateDeferredStmt->execute([
                ':attempts' => $attempts,
                ':next_attempt_at' => $nextAttempt,
                ':last_error' => substr($error, 0, 250),
                ':id' => $job['id']
            ]);
            $result['deferred']++;
        }

        return $result;
    }

    /**
     * Sends campaign email to donors who opted in for campaign announcements.
     *
     * @return array{targeted:int,sent:int,failed:int}
     */
    public function notifyDonorsForCampaign(
        string $title,
        string $city,
        string $dateLabel,
        string $campaignLink,
        string $description = ''
    ): array {
        $eligibleDonors = $this->findCampaignEmailDonors();
        $sent = 0;
        $failed = 0;

        foreach ($eligibleDonors as $donor) {
            $fullName = trim(($donor['first_name'] ?? '') . ' ' . ($donor['last_name'] ?? ''));
            $ok = $this->mailService->sendCampaignNotificationEmail(
                (string)$donor['email'],
                $fullName !== '' ? $fullName : 'Донор',
                $title,
                $city,
                $dateLabel,
                $campaignLink,
                $description
            );

            if ($ok) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return [
            'targeted' => count($eligibleDonors),
            'sent' => $sent,
            'failed' => $failed
        ];
    }

    /**
     * Loads verified, available donors whose blood type appears in the compatibility list for the request.
     */
    private function findEligibleDonors(string $requiredBloodType): array
    {
        $compatibleDonorTypes = $this->getCompatibleDonorBloodTypes($requiredBloodType);
        if (count($compatibleDonorTypes) === 0) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($compatibleDonorTypes), '?'));
        $stmt = $this->pdo->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email, d.blood_type, d.email_notifications
            FROM users u
            INNER JOIN donors d ON d.user_id = u.id
            WHERE u.role = 'donor'
              AND u.is_verified = 1
              AND d.is_available = 1
              AND d.blood_type IN ($placeholders)
        ");
        $stmt->execute($compatibleDonorTypes);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Loads verified donors that explicitly opted in for campaign email announcements.
     */
    private function findCampaignEmailDonors(): array
    {
        $columnCheck = $this->pdo->prepare("SHOW COLUMNS FROM donors LIKE 'campaign_email_notifications'");
        $columnCheck->execute();
        $hasCampaignEmailColumn = (bool)$columnCheck->fetch(PDO::FETCH_ASSOC);
        if (!$hasCampaignEmailColumn) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email
            FROM users u
            INNER JOIN donors d ON d.user_id = u.id
            WHERE u.role = 'donor'
              AND u.is_verified = 1
              AND d.campaign_email_notifications = 1
        ");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Standard donation compatibility: which donor blood types can supply the recipient type.
     *
     * @return list<string>
     */
    private function getCompatibleDonorBloodTypes(string $recipientType): array
    {
        $compatibility = [
            'A+' => ['A+', 'A-', 'O+', 'O-'],
            'A-' => ['A-', 'O-'],
            'B+' => ['B+', 'B-', 'O+', 'O-'],
            'B-' => ['B-', 'O-'],
            'AB+' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],
            'AB-' => ['A-', 'B-', 'AB-', 'O-'],
            'O+' => ['O+', 'O-'],
            'O-' => ['O-']
        ];

        return $compatibility[$recipientType] ?? [];
    }

    /**
     * Sends FCM to every active token for the donor; invalid tokens are deactivated.
     */
    private function sendPushToDonor(int $donorUserId, int $requestId, string $messageBody): bool
    {
        $serviceAccount = $this->getServiceAccountConfig();
        if ($serviceAccount === null) {
            return false;
        }

        $tokenStmt = $this->pdo->prepare("
            SELECT id, fcm_token
            FROM donor_push_tokens
            WHERE donor_user_id = :donor_user_id
              AND is_active = 1
        ");
        $tokenStmt->execute([':donor_user_id' => $donorUserId]);
        $tokens = $tokenStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (count($tokens) === 0) {
            return false;
        }

        $hasSuccess = false;
        foreach ($tokens as $row) {
            $token = (string)$row['fcm_token'];
            $ok = $this->sendPushToken($serviceAccount, $token, $requestId, $messageBody);
            if ($ok) {
                $hasSuccess = true;
                continue;
            }

            // Expired or unregistered tokens should not be retried forever.
            $this->deactivateToken($token);
        }

        return $hasSuccess;
    }

    /**
     * One FCM HTTP v1 send; returns true when the API returns a message resource name.
     */
    private function sendPushToken(array $serviceAccount, string $token, int $requestId, string $messageBody): bool
    {
        $accessToken = $this->getGoogleAccessToken($serviceAccount);
        if ($accessToken === null) {
            return false;
        }

        $projectId = (string)($serviceAccount['project_id'] ?? '');
        if ($projectId === '') {
            return false;
        }

        // "notification" drives system UI; "data" is available to the service worker for deep links.
        $payload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => 'Нова заявка за кръв',
                    'body' => $messageBody
                ],
                'data' => [
                    'request_id' => (string)$requestId,
                    'type' => 'new_blood_request'
                ]
            ],
        ];

        $endpoint = sprintf(
            'https://fcm.googleapis.com/v1/projects/%s/messages:send',
            rawurlencode($projectId)
        );

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 8
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error !== '' || $response === false || $statusCode < 200 || $statusCode >= 300) {
            return false;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return false;
        }

        // Successful FCM v1 responses include a "name" field for the created message.
        if (!isset($decoded['name'])) {
            return false;
        }

        return true;
    }

    /**
     * Parses service account JSON from env (plain or Base64). Returns null if misconfigured.
     *
     * @return array<string, mixed>|null
     */
    private function getServiceAccountConfig(): ?array
    {
        $inline = trim((string)($this->pushConfig['service_account_json'] ?? ''));
        $base64 = trim((string)($this->pushConfig['service_account_json_base64'] ?? ''));

        $json = $inline;
        if ($json === '' && $base64 !== '') {
            $decoded = base64_decode($base64, true);
            $json = $decoded !== false ? $decoded : '';
        }

        if ($json === '') {
            return null;
        }

        $serviceAccount = json_decode($json, true);
        if (!is_array($serviceAccount)) {
            return null;
        }

        if (empty($serviceAccount['client_email']) || empty($serviceAccount['private_key']) || empty($serviceAccount['project_id'])) {
            return null;
        }

        return $serviceAccount;
    }

    /**
     * Exchanges a signed JWT for a short-lived OAuth access token with firebase.messaging scope.
     */
    private function getGoogleAccessToken(array $serviceAccount): ?string
    {
        $jwt = $this->buildJwt($serviceAccount);
        if ($jwt === null) {
            return null;
        }

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ])
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error !== '' || $response === false || $statusCode < 200 || $statusCode >= 300) {
            return null;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || empty($decoded['access_token'])) {
            return null;
        }

        return (string)$decoded['access_token'];
    }

    /**
     * Builds a JWT signed with the service account private key (RS256) for Google token endpoint.
     */
    private function buildJwt(array $serviceAccount): ?string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $issuedAt = time();
        $payload = [
            'iss' => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $issuedAt,
            'exp' => $issuedAt + 3600
        ];

        $base64Header = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $base64Payload = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $unsignedToken = $base64Header . '.' . $base64Payload;

        $signature = '';
        $privateKey = (string)$serviceAccount['private_key'];
        $isSigned = openssl_sign($unsignedToken, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$isSigned) {
            return null;
        }

        return $unsignedToken . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function deactivateToken(string $token): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE donor_push_tokens
            SET is_active = 0
            WHERE fcm_token = :token
        ");
        $stmt->execute([':token' => $token]);
    }

    private function logDelivery(int $requestId, int $donorUserId, string $channel, string $status, ?string $errorMessage): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO notification_logs (request_id, donor_user_id, channel, status, error_message)
            VALUES (:request_id, :donor_user_id, :channel, :status, :error_message)
        ");
        $stmt->execute([
            ':request_id' => $requestId,
            ':donor_user_id' => $donorUserId,
            ':channel' => $channel,
            ':status' => $status,
            ':error_message' => $errorMessage
        ]);
    }
}
