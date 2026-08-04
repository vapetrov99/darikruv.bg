<?php

/**
 * POST login — authenticates by email/password, requires verified email, returns user row without password hash.
 */
return static function (PDO $pdo): void {
    require_once __DIR__ . '/../helpers/rate_limit.php';

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }

        if (rate_limit_honeypot_filled($input)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid request payload'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';

        if ($email === '' || $password === '') {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Email and password are required'
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

        $ipLimit = rate_limit_check_and_hit($pdo, 'login_ip', rate_limit_get_client_ip(), 12, 600);
        if (!$ipLimit['allowed']) {
            http_response_code(429);
            echo json_encode([
                'status' => 'error',
                'message' => 'Твърде много опити за вход. Опитай отново след малко.',
                'retry_after' => (int)$ipLimit['retry_after']
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $emailLimit = rate_limit_check_and_hit($pdo, 'login_email', $email, 6, 600);
        if (!$emailLimit['allowed']) {
            http_response_code(429);
            echo json_encode([
                'status' => 'error',
                'message' => 'Твърде много опити за вход. Опитай отново след малко.',
                'retry_after' => (int)$emailLimit['retry_after']
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $columnCheck = $pdo->prepare("SHOW COLUMNS FROM donors LIKE 'campaign_email_notifications'");
        $columnCheck->execute();
        $hasCampaignEmailColumn = (bool)$columnCheck->fetch(PDO::FETCH_ASSOC);
        $campaignEmailSelect = $hasCampaignEmailColumn
            ? "d.campaign_email_notifications"
            : "0 AS campaign_email_notifications";

        $stmt = $pdo->prepare("
            SELECT
                u.id,
                u.public_id,
                u.first_name,
                u.last_name,
                u.email,
                u.password,
                u.phone,
                u.city,
                u.role,
                u.created_at,
                u.is_verified,
                d.blood_type,
                d.last_donation_date,
                d.is_available,
                d.email_notifications,
                {$campaignEmailSelect}
            FROM users u
            LEFT JOIN donors d ON d.user_id = u.id
            WHERE u.email = :email
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid email or password'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ((int)$user['is_verified'] !== 1) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'Please verify your email before logging in'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        unset($user['password']);
        $tokenData = auth_issue_token($user);
        // Internal PK is only needed to issue JWT when public_id is not UUID v4.
        unset($user['id']);
        $user['auth_token'] = $tokenData['token'];
        $user['token_type'] = $tokenData['token_type'];
        $user['expires_in'] = $tokenData['expires_in'];
        $user['expires_at'] = $tokenData['expires_at'];

        echo json_encode([
            'status' => 'success',
            'message' => 'Login successful',
            'data' => $user
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Login failed',
        ], JSON_UNESCAPED_UNICODE);
    }
};
