<?php

/**
 * POST respond_to_request — donor pledge / confirm flow.
 * action=pledge  → "Ще се отзова": pending response + request status waiting (24h).
 * action=confirm → "Отзовах се": confirmed response + increment fulfilled_units_count.
 */
return static function (PDO $pdo): void {
    require_once __DIR__ . '/../helpers/blood_request_helpers.php';

    try {
        $input = json_decode(file_get_contents('php://input'), true);

        $requestId = isset($input['request_id']) ? (int)$input['request_id'] : 0;
        $donorUserId = isset($input['donor_user_id']) ? (int)$input['donor_user_id'] : 0;
        $action = trim($input['action'] ?? 'pledge');

        if ($requestId < 1 || $donorUserId < 1) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Valid request_id and donor_user_id are required'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!in_array($action, ['pledge', 'confirm'], true)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid action. Use pledge or confirm'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        expireStaleWaitingRequests($pdo);

        $requestStmt = $pdo->prepare("
            SELECT
                id,
                status,
                created_by,
                required_units_count,
                fulfilled_units_count,
                waiting_until
            FROM blood_requests
            WHERE id = :id
            LIMIT 1
        ");
        $requestStmt->execute([':id' => $requestId]);
        $request = $requestStmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'Blood request not found'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (in_array($request['status'], ['fulfilled', 'closed'], true)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Тази заявка вече не приема отзовавания'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $userStmt = $pdo->prepare("
            SELECT id, role
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $userStmt->execute([':id' => $donorUserId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'Donor user not found'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!in_array($user['role'], ['donor', 'requester'], true)) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'Нямаш право да се отзовеш на заявки'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($request['created_by'] !== null && (int)$request['created_by'] === $donorUserId) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'Не можеш да се отзовеш на собствена заявка'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $responseStmt = $pdo->prepare("
            SELECT id, response_status
            FROM request_responses
            WHERE request_id = :request_id AND donor_user_id = :donor_user_id
            LIMIT 1
        ");
        $responseStmt->execute([
            ':request_id' => $requestId,
            ':donor_user_id' => $donorUserId
        ]);
        $existingResponse = $responseStmt->fetch(PDO::FETCH_ASSOC);

        if ($action === 'pledge') {
            if ($existingResponse && $existingResponse['response_status'] === 'confirmed') {
                http_response_code(409);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Вече си потвърдил отзоваването си'
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            if ($request['status'] === 'waiting') {
                if ($existingResponse && $existingResponse['response_status'] === 'pending') {
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Вече си заявил, че ще се отзовеш',
                        'data' => buildRespondPayload($pdo, $requestId, $donorUserId)
                    ], JSON_UNESCAPED_UNICODE);
                    return;
                }

                http_response_code(409);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Заявката в момента е резервирана от друг донор'
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            if ($request['status'] !== 'active') {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Заявката не е активна'
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo->beginTransaction();

            if (!$existingResponse) {
                $insertStmt = $pdo->prepare("
                    INSERT INTO request_responses (request_id, donor_user_id, response_status)
                    VALUES (:request_id, :donor_user_id, 'pending')
                ");
                $insertStmt->execute([
                    ':request_id' => $requestId,
                    ':donor_user_id' => $donorUserId
                ]);
            }

            $updateRequestStmt = $pdo->prepare("
                UPDATE blood_requests
                SET status = 'waiting',
                    waiting_until = DATE_ADD(NOW(), INTERVAL 24 HOUR)
                WHERE id = :id AND status = 'active'
                LIMIT 1
            ");
            $updateRequestStmt->execute([':id' => $requestId]);

            if ($updateRequestStmt->rowCount() < 1) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Заявката вече не е налична за резервиране'
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo->commit();

            http_response_code(201);
            echo json_encode([
                'status' => 'success',
                'message' => 'Заявката е резервирана за 24 часа',
                'data' => buildRespondPayload($pdo, $requestId, $donorUserId)
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // confirm
        if (!$existingResponse || $existingResponse['response_status'] !== 'pending') {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Първо трябва да натиснеш „Ще се отзова“'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($request['status'] !== 'waiting') {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Заявката не е в режим на изчакване'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $requiredUnits = (int)$request['required_units_count'];
        $fulfilledUnits = (int)$request['fulfilled_units_count'] + 1;

        if ($fulfilledUnits > $requiredUnits) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Всички необходими банки вече са покрити'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $newStatus = $fulfilledUnits >= $requiredUnits ? 'fulfilled' : 'active';

        $pdo->beginTransaction();

        $confirmResponseStmt = $pdo->prepare("
            UPDATE request_responses
            SET response_status = 'confirmed'
            WHERE id = :id AND response_status = 'pending'
            LIMIT 1
        ");
        $confirmResponseStmt->execute([':id' => (int)$existingResponse['id']]);

        $updateRequestStmt = $pdo->prepare("
            UPDATE blood_requests
            SET fulfilled_units_count = :fulfilled_units_count,
                status = :status,
                waiting_until = NULL
            WHERE id = :id
            LIMIT 1
        ");
        $updateRequestStmt->execute([
            ':fulfilled_units_count' => $fulfilledUnits,
            ':status' => $newStatus,
            ':id' => $requestId
        ]);

        $pdo->commit();

        echo json_encode([
            'status' => 'success',
            'message' => $newStatus === 'fulfilled'
                ? 'Заявката е изпълнена'
                : 'Благодарим! Остават още необходими банки.',
            'data' => buildRespondPayload($pdo, $requestId, $donorUserId)
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to respond to blood request',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
};

function buildRespondPayload(PDO $pdo, int $requestId, int $donorUserId): array
{
    $requestStmt = $pdo->prepare("
        SELECT
            id,
            status,
            required_units_count,
            fulfilled_units_count,
            waiting_until
        FROM blood_requests
        WHERE id = :id
        LIMIT 1
    ");
    $requestStmt->execute([':id' => $requestId]);
    $request = $requestStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $responseStmt = $pdo->prepare("
        SELECT response_status
        FROM request_responses
        WHERE request_id = :request_id AND donor_user_id = :donor_user_id
        LIMIT 1
    ");
    $responseStmt->execute([
        ':request_id' => $requestId,
        ':donor_user_id' => $donorUserId
    ]);
    $response = $responseStmt->fetch(PDO::FETCH_ASSOC);

    return [
        'request' => $request,
        'my_response_status' => $response['response_status'] ?? null
    ];
}
