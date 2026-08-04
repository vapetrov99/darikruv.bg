<?php

/**
 * POST create_request — inserts blood_requests row, then best-effort donor notifications (push + email).
 * Notification failures are logged but do not fail the HTTP response for the created request.
 */
return static function (PDO $pdo): void {
    try {
        $authUser = auth_require_user();
        $input = json_decode(file_get_contents('php://input'), true);

        $patientName = trim($input['patient_name'] ?? '');
        $bloodType = trim($input['blood_type'] ?? '');
        $city = trim($input['city'] ?? '');
        $hospital = trim($input['hospital'] ?? '');
        $contactName = trim($input['contact_name'] ?? '');
        $contactPhone = trim($input['contact_phone'] ?? '');
        $description = trim($input['description'] ?? '');
        $requiredUnitsCount = (int)($input['required_units_count'] ?? 1);
        $createdBy = (int)$authUser['id'];
        $createdByPublicId = (string)($authUser['public_id'] ?? '');

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

        $stmt = $pdo->prepare("
            INSERT INTO blood_requests (
                patient_name,
                blood_type,
                city,
                hospital,
                contact_name,
                contact_phone,
                description,
                required_units_count,
                created_by
            ) VALUES (
                :patient_name,
                :blood_type,
                :city,
                :hospital,
                :contact_name,
                :contact_phone,
                :description,
                :required_units_count,
                :created_by
            )
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
            ':created_by' => $createdBy
        ]);

        $requestId = (int)$pdo->lastInsertId();
        try {
            // Fire-and-forget style: donors are notified outside the main DB transaction scope of this handler.
            $notificationService = new NotificationService($pdo);
            $notificationService->notifyDonorsForRequest($requestId, $bloodType, $city, $hospital);
        } catch (Throwable $notificationError) {
            error_log('Notification error: ' . $notificationError->getMessage());
        }

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'message' => 'Blood request created successfully',
            'data' => [
                'id' => $requestId,
                'patient_name' => $patientName,
                'blood_type' => $bloodType,
                'city' => $city,
                'hospital' => $hospital,
                'contact_name' => $contactName,
                'contact_phone' => $contactPhone,
                'description' => $description,
                'required_units_count' => $requiredUnitsCount,
                'fulfilled_units_count' => 0,
                'created_by_public_id' => $createdByPublicId
            ]
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to create blood request',
        ], JSON_UNESCAPED_UNICODE);
    }
};
