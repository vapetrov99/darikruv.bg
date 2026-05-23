<?php

/**
 * GET verify_email?token=... — marks the user verified and clears verification_token.
 * Browser requests (Accept: text/html) redirect to the email-verified landing page.
 */
return static function (PDO $pdo): void {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $wantsHtml = str_contains($accept, 'text/html') && !str_contains($accept, 'application/json');

    $redirect = static function (string $status, ?string $message = null): void {
        $query = 'status=' . rawurlencode($status);
        if ($message !== null && $message !== '') {
            $query .= '&message=' . rawurlencode($message);
        }
        header('Location: /html/email-verified.html?' . $query);
        exit;
    };

    try {
        $token = trim($_GET['token'] ?? '');

        if ($token === '') {
            if ($wantsHtml) {
                $redirect('error', 'Липсва код за потвърждение.');
            }
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Verification token is required'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $stmt = $pdo->prepare("
            SELECT id, email, is_verified
            FROM users
            WHERE verification_token = :token
            LIMIT 1
        ");
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            if ($wantsHtml) {
                $redirect('error', 'Линкът е невалиден или вече е използван.');
            }
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid or expired verification token'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ((int)$user['is_verified'] === 1) {
            if ($wantsHtml) {
                $redirect('already');
            }
            echo json_encode([
                'status' => 'success',
                'message' => 'Email is already verified'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $updateStmt = $pdo->prepare("
            UPDATE users
            SET is_verified = 1,
                verification_token = NULL,
                verified_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $updateStmt->execute([':id' => $user['id']]);

        if ($wantsHtml) {
            $redirect('success');
        }

        echo json_encode([
            'status' => 'success',
            'message' => 'Email verified successfully'
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        if ($wantsHtml) {
            $redirect('error', 'Грешка при потвърждение. Опитай отново.');
        }
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Email verification failed',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
};
