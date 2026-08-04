<?php

/**
 * POST update_request — updates a blood_requests row; only the creator (created_by) may edit.
 */
return static function (PDO $pdo): void {
    try {
        $authUser = auth_require_user();
        $userId = (int)$authUser['id'];
        $input = json_decode(file_get_contents('php://input'), true);

        $requestId = isset($input['request_id']) ? (int)$input['request_id'] : 0;
        $patientName = trim($input['patient_name'] ?? '');
        $bloodType = trim($input['blood_type'] ?? '');
        $city = trim($input['city'] ?? '');
        $hospital = trim($input['hospital'] ?? '');
        $contactName = trim($input['contact_name'] ?? '');
        $contactPhone = trim($input['contact_phone'] ?? '');
        $description = trim($input['description'] ?? '');
        $requiredUnitsCount = (int)($input['required_units_count'] ?? 1);

        if ($requestId < 1) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'request_id is required'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (
            $patientName === '' ||
            $bloodType === '' ||
            $city === '' ||
            $hospital === '' ||
            $contactName === '' ||
            $contactPhone === ''
        ) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Required fields are missing'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $allowedBloodTypes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
        if (!in_array($bloodType, $allowedBloodTypes, true)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid blood type'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($requiredUnitsCount < 1) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Required units count must be at least 1'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $ownerStmt = $pdo->prepare("
            SELECT
                br.id,
                br.created_by,
                u_creator.public_id AS created_by_public_id,
                br.fulfilled_units_count,
                br.status,
                br.created_at
            FROM blood_requests br
            LEFT JOIN users u_creator ON u_creator.id = br.created_by
            WHERE br.id = :id
            LIMIT 1
        ");
        $ownerStmt->execute([':id' => $requestId]);
        $existing = $ownerStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'Blood request not found'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ((int)$existing['created_by'] !== $userId) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'You can only edit your own blood requests'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($existing['status'] !== 'active') {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'Само активни заявки могат да бъдат редактирани'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $createdAt = strtotime((string)$existing['created_at']);
        if ($createdAt === false || (time() - $createdAt) > 72 * 3600) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'Изтекоха 72 часа от публикуването. Заявката вече не може да бъде редактирана'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($requiredUnitsCount < (int)$existing['fulfilled_units_count']) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Required units cannot be less than already fulfilled units'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $stmt = $pdo->prepare("
            UPDATE blood_requests
            SET patient_name = :patient_name,
                blood_type = :blood_type,
                city = :city,
                hospital = :hospital,
                contact_name = :contact_name,
                contact_phone = :contact_phone,
                description = :description,
                required_units_count = :required_units_count
            WHERE id = :id AND created_by = :user_id
            LIMIT 1
        ");

        $stmt->execute([
            ':patient_name' => $patientName,
            ':blood_type' => $bloodType,
            ':city' => $city,
            ':hospital' => $hospital,
            ':contact_name' => $contactName,
            ':contact_phone' => $contactPhone,
            ':description' => $description !== '' ? $description : null,
            ':required_units_count' => $requiredUnitsCount,
            ':id' => $requestId,
            ':user_id' => $userId
        ]);

        if ($stmt->rowCount() < 1) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'Blood request could not be updated'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $selectStmt = $pdo->prepare("
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
                br.required_units_count,
                br.fulfilled_units_count,
                u_creator.public_id AS created_by_public_id,
                br.created_at
            FROM blood_requests br
            LEFT JOIN users u_creator ON u_creator.id = br.created_by
            WHERE br.id = :id
            LIMIT 1
        ");
        $selectStmt->execute([':id' => $requestId]);
        $updated = $selectStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'message' => 'Blood request updated successfully',
            'data' => $updated
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to update blood request',
        ], JSON_UNESCAPED_UNICODE);
    }
};
