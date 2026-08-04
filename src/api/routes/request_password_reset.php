<?php

/**
 * POST request_password_reset — generates one-time reset token and sends reset email.
 * Returns generic success message to avoid leaking whether the email exists.
 */
return static function (PDO $pdo): void {
    require_once __DIR__ . '/../helpers/rate_limit.php';

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }

        if (rate_limit_honeypot_filled($input)) {
            echo json_encode([
                'status' => 'success',
                'message' => 'Ако имейлът съществува в системата, ще получиш линк за нова парола.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $email = trim($input['email'] ?? '');

        if ($email === '') {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Email is required'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid email format'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $ipLimit = rate_limit_check_and_hit($pdo, 'password_reset_ip', rate_limit_get_client_ip(), 6, 900);
        if (!$ipLimit['allowed']) {
            http_response_code(429);
            echo json_encode([
                'status' => 'error',
                'message' => 'Твърде много заявки за нова парола. Опитай отново след малко.',
                'retry_after' => (int)$ipLimit['retry_after']
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $emailLimit = rate_limit_check_and_hit($pdo, 'password_reset_email', $email, 4, 900);
        if (!$emailLimit['allowed']) {
            http_response_code(429);
            echo json_encode([
                'status' => 'error',
                'message' => 'Твърде много заявки за този имейл. Опитай отново след малко.',
                'retry_after' => (int)$emailLimit['retry_after']
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name, email
            FROM users
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour

            $updateStmt = $pdo->prepare("
                UPDATE users
                SET password_reset_token = :token,
                    password_reset_expires_at = :expires_at,
                    password_reset_requested_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':token' => $token,
                ':expires_at' => $expiresAt,
                ':id' => $user['id']
            ]);

            $appConfig = require __DIR__ . '/../../config/app.php';
            $resetLink = $appConfig['base_url'] . '/html/reset-password.html?token=' . $token;
            $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Потребител';

            $mailService = new MailService();
            $mailService->sendPasswordResetEmail($user['email'], $fullName, $resetLink);
        }

        echo json_encode([
            'status' => 'success',
            'message' => 'Ако имейлът съществува в системата, ще получиш линк за нова парола.'
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Password reset request failed',
        ], JSON_UNESCAPED_UNICODE);
    }
};
