<?php

/**
 * GET requests — visible blood requests from the last 2 days (active / waiting).
 * Optional authenticated user joins caller response status for respond buttons.
 */
return static function (PDO $pdo): void {
    require_once __DIR__ . '/../helpers/blood_request_helpers.php';

    try {
        expireStaleWaitingRequests($pdo);

        $authUser = auth_current_user();
        $userId = $authUser ? (int)($authUser['id'] ?? 0) : 0;

        if ($userId > 0) {
            $stmt = $pdo->prepare("
                SELECT
                    br.id,
                    br.patient_name,
                    br.blood_type,
                    br.city,
                    br.hospital,
                    br.contact_name,
                    br.contact_phone,
                    br.description,
                    br.status,
                    br.waiting_until,
                    br.required_units_count,
                    br.fulfilled_units_count,
                    u_creator.public_id AS created_by_public_id,
                    br.created_at,
                    rr.response_status AS my_response_status
                FROM blood_requests br
                LEFT JOIN users u_creator ON u_creator.id = br.created_by
                LEFT JOIN request_responses rr
                    ON rr.request_id = br.id AND rr.donor_user_id = :user_id
                WHERE br.created_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)
                  AND br.status IN ('active', 'waiting')
                ORDER BY br.created_at DESC
            ");
            $stmt->execute([':user_id' => $userId]);
        } else {
            $stmt = $pdo->query("
                SELECT
                    br.id,
                    br.patient_name,
                    br.blood_type,
                    br.city,
                    br.hospital,
                    br.contact_name,
                    br.contact_phone,
                    br.description,
                    br.status,
                    br.waiting_until,
                    br.required_units_count,
                    br.fulfilled_units_count,
                    u_creator.public_id AS created_by_public_id,
                    br.created_at,
                    NULL AS my_response_status
                FROM blood_requests br
                LEFT JOIN users u_creator ON u_creator.id = br.created_by
                WHERE br.created_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)
                  AND br.status IN ('active', 'waiting')
                ORDER BY br.created_at DESC
            ");
        }

        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'data' => $requests
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to fetch blood requests',
        ], JSON_UNESCAPED_UNICODE);
    }
};
