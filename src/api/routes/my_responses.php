<?php

/**
 * GET my_responses — request_responses joined with blood_requests for authenticated user's history.
 */
return static function (PDO $pdo): void {    try {
        $authUser = auth_require_user();
        $userId = (int)$authUser['id'];

        $stmt = $pdo->prepare("
            SELECT
                rr.id,
                rr.request_id,
                rr.response_status,
                rr.created_at,
                br.patient_name,
                br.blood_type,
                br.city,
                br.hospital
            FROM request_responses rr
            JOIN blood_requests br ON br.id = rr.request_id
            WHERE rr.donor_user_id = :user_id
            ORDER BY rr.created_at DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'data' => $responses
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to fetch user responses',
        ], JSON_UNESCAPED_UNICODE);
    }
};
