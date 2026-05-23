<?php

/**
 * POST save_push_token
 *
 * JSON body: { "user_id": number, "token": string, "enabled": boolean (optional, default true) }
 *
 * Persists the FCM registration token for a donor so the server can target push notifications.
 * Rules:
 * - Only users with role donor, linked donors row, and is_verified = 1.
 * - UNIQUE on fcm_token: INSERT ... ON DUPLICATE KEY UPDATE refreshes last_seen_at and donor_user_id.
 */
return static function (PDO $pdo): void {
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        $userId = (int)($input['user_id'] ?? 0);
        $token = trim($input['token'] ?? '');
        // Client may disable notifications by sending enabled: false.
        $enabled = isset($input['enabled']) ? (bool)$input['enabled'] : true;

        if ($userId < 1 || $token === '') {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'user_id and token are required'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $checkStmt = $pdo->prepare("
            SELECT u.id
            FROM users u
            INNER JOIN donors d ON d.user_id = u.id
            WHERE u.id = :user_id
              AND u.role = 'donor'
              AND u.is_verified = 1
            LIMIT 1
        ");
        $checkStmt->execute([':user_id' => $userId]);
        if (!$checkStmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'Only verified donors can register push tokens'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO donor_push_tokens (donor_user_id, fcm_token, is_active)
            VALUES (:donor_user_id, :fcm_token, :is_active)
            ON DUPLICATE KEY UPDATE
                donor_user_id = VALUES(donor_user_id),
                is_active = VALUES(is_active),
                last_seen_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            ':donor_user_id' => $userId,
            ':fcm_token' => $token,
            ':is_active' => $enabled ? 1 : 0
        ]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Push token saved'
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to save push token',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
};
