<?php

/**
 * POST reset_password — validates reset token and stores a new password hash.
 */
return static function (PDO $pdo): void {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $token = trim($input['token'] ?? '');
        $password = $input['password'] ?? '';

        if ($token === '' || $password === '') {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Token and password are required'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $hasMinLength = strlen($password) >= 8;
        $hasLowercase = preg_match('/[a-z]/', $password) === 1;
        $hasUppercase = preg_match('/[A-Z]/', $password) === 1;
        $hasDigit = preg_match('/\d/', $password) === 1;
        $isStrongPassword = $hasMinLength && $hasLowercase && $hasUppercase && $hasDigit;

        if (!$isStrongPassword) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Password must be at least 8 characters and include lowercase, uppercase, and a number'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $stmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE password_reset_token = :token
              AND password_reset_expires_at IS NOT NULL
              AND password_reset_expires_at > CURRENT_TIMESTAMP
            LIMIT 1
        ");
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Невалиден или изтекъл линк за смяна на парола.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $updateStmt = $pdo->prepare("
            UPDATE users
            SET password = :password,
                password_reset_token = NULL,
                password_reset_expires_at = NULL,
                password_reset_requested_at = NULL
            WHERE id = :id
        ");
        $updateStmt->execute([
            ':password' => $hashedPassword,
            ':id' => $user['id']
        ]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Паролата е сменена успешно.'
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Password reset failed',
        ], JSON_UNESCAPED_UNICODE);
    }
};
