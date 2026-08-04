<?php

/**
 * GET my_requests — blood_requests where created_by matches the authenticated user.
 */
return static function (PDO $pdo): void {    try {
        $authUser = auth_require_user();
        $userId = (int)$authUser['id'];

        $stmt = $pdo->prepare("
            SELECT
                br.id,
                br.patient_name,
                br.blood_type,
                br.city,
                br.hospital,
                br.status,
                u_creator.public_id AS created_by_public_id,
                br.created_at
            FROM blood_requests br
            LEFT JOIN users u_creator ON u_creator.id = br.created_by
            WHERE br.created_by = :user_id
            ORDER BY br.created_at DESC
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
        ], JSON_UNESCAPED_UNICODE);
    }
};
