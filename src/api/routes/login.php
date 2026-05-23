<?php

/**
 * POST login — authenticates by email/password, requires verified email, returns user row without password hash.
 */
return static function (PDO $pdo): void {
    try {
        $input = json_decode(file_get_contents('php://input'), true);

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

        $columnCheck = $pdo->prepare("SHOW COLUMNS FROM donors LIKE 'campaign_email_notifications'");
        $columnCheck->execute();
        $hasCampaignEmailColumn = (bool)$columnCheck->fetch(PDO::FETCH_ASSOC);
        $campaignEmailSelect = $hasCampaignEmailColumn
            ? "d.campaign_email_notifications"
            : "0 AS campaign_email_notifications";

        $stmt = $pdo->prepare("
            SELECT
                u.id,
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

        echo json_encode([
            'status' => 'success',
            'message' => 'Login successful',
            'data' => $user
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Login failed',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
};
