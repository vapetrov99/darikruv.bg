<?php

/**
 * GET request_details?id= — single blood_requests row by primary key.
 */
return static function (PDO $pdo): void {
    require_once __DIR__ . '/../helpers/blood_request_helpers.php';

    try {
        expireStaleWaitingRequests($pdo);

        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

        if ($id < 1) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Valid request id is required'
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
                contact_name,
                contact_phone,
                description,
                status,
                waiting_until,
                required_units_count,
                fulfilled_units_count,
                created_by,
                created_at
            FROM blood_requests
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'Blood request not found'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $request['my_response_status'] = null;
        if ($userId > 0) {
            $responseStmt = $pdo->prepare("
                SELECT response_status
                FROM request_responses
                WHERE request_id = :request_id AND donor_user_id = :user_id
                LIMIT 1
            ");
            $responseStmt->execute([
                ':request_id' => $id,
                ':user_id' => $userId
            ]);
            $response = $responseStmt->fetch(PDO::FETCH_ASSOC);
            if ($response) {
                $request['my_response_status'] = $response['response_status'];
            }
        }

        echo json_encode([
            'status' => 'success',
            'data' => $request
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to fetch blood request details',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
};
