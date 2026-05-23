<?php

/**
 * GET my_requests?user_id= — blood_requests where created_by matches (requester's own listings).
 */
return static function (PDO $pdo): void {    try {
        $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

        if ($userId < 1) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Valid user_id is required'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $stmt = $pdo->prepare("
            SELECT
                id,
                patient_name,
                blood_type,
                city,
                hospital,
                status,
                created_by,
                created_at
            FROM blood_requests
            WHERE created_by = :user_id
            ORDER BY created_at DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'data' => $requests
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to fetch user requests',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
};
