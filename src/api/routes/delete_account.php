<?php

/**
 * POST delete_account — GDPR-oriented hard delete for the profile and linked history.
 * Requires password and explicit confirmation phrase.
 */
return static function (PDO $pdo): void {
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        $userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
        $password = $input['password'] ?? '';
        $confirmPhrase = trim($input['confirm_phrase'] ?? '');

        if ($userId < 1 || $password === '') {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'user_id и парола са задължителни'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($confirmPhrase !== 'ИЗТРИЙ') {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Въведи ИЗТРИЙ в полето за потвърждение'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $stmt = $pdo->prepare("
            SELECT id, email, password, role, first_name, last_name, phone
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'Потребителят не е намерен'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($user['role'] === 'admin') {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'Администраторски акаунти не могат да бъдат изтрити оттук'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!password_verify($password, $user['password'])) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'message' => 'Грешна парола'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pdo->beginTransaction();

        /**
         * 1) Delete standalone comments authored by this profile (comments that may not be linked by FK to users).
         * We only remove comments when both full name and phone match, to avoid deleting other people's comments.
         */
        $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        $phone = trim((string)($user['phone'] ?? ''));
        if ($fullName !== '' && $phone !== '') {
            $deleteStandaloneCommentsStmt = $pdo->prepare("
                DELETE FROM request_comments
                WHERE author_name = :author_name
                  AND contact_phone = :contact_phone
            ");
            $deleteStandaloneCommentsStmt->execute([
                ':author_name' => $fullName,
                ':contact_phone' => $phone
            ]);
        }

        /**
         * 2) Delete history linked to this user (responses/logs/tokens/donor profile/requests), then user.
         * Some relations are already ON DELETE CASCADE, but explicit deletes keep behavior deterministic.
         */
        $deleteResponsesStmt = $pdo->prepare("
            DELETE FROM request_responses
            WHERE donor_user_id = :user_id
        ");
        $deleteResponsesStmt->execute([':user_id' => $userId]);

        $deleteNotificationLogsStmt = $pdo->prepare("
            DELETE FROM notification_logs
            WHERE donor_user_id = :user_id
        ");
        $deleteNotificationLogsStmt->execute([':user_id' => $userId]);

        $deletePushTokensStmt = $pdo->prepare("
            DELETE FROM donor_push_tokens
            WHERE donor_user_id = :user_id
        ");
        $deletePushTokensStmt->execute([':user_id' => $userId]);

        $deleteDonorStmt = $pdo->prepare("
            DELETE FROM donors
            WHERE user_id = :user_id
        ");
        $deleteDonorStmt->execute([':user_id' => $userId]);

        $deleteRequestsStmt = $pdo->prepare("
            DELETE FROM blood_requests
            WHERE created_by = :user_id
        ");
        $deleteRequestsStmt->execute([':user_id' => $userId]);

        $deleteUserStmt = $pdo->prepare("
            DELETE FROM users
            WHERE id = :id
        ");
        $deleteUserStmt->execute([':id' => $userId]);

        if ($deleteUserStmt->rowCount() < 1) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'Неуспешно изтриване на профила'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pdo->commit();

        echo json_encode([
            'status' => 'success',
            'message' => 'Профилът е изтрит успешно'
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Грешка при изтриване на профила',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
};
