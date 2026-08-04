<?php

/**
 * POST update_profile — updates users and optionally donors (blood type, availability) when role is donor.
 * Accepts a combined "name" field split into first/last name; supports role switch donor/requester.
 */
return static function (PDO $pdo): void {    try {
        $authUser = auth_require_user();
        $userId = (int)$authUser['id'];
        $input = json_decode(file_get_contents('php://input'), true);

        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $city = trim($input['city'] ?? '');
        $bloodType = trim($input['blood_type'] ?? '');
        $isAvailable = isset($input['is_available']) ? (bool)$input['is_available'] : null;
        $emailNotifications = array_key_exists('email_notifications', $input)
            ? (bool)$input['email_notifications']
            : null;
        $campaignEmailNotifications = array_key_exists('campaign_email_notifications', $input)
            ? (bool)$input['campaign_email_notifications']
            : null;

        if ($name === '' || $email === '') {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'name and email are required'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid email format'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $nameParts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
        $firstName = $nameParts[0] ?? '';
        $lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '';

        if ($firstName === '') {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Name must contain at least first name'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $allowedRoles = ['donor', 'requester'];
        $newRole = isset($input['role']) && in_array($input['role'], $allowedRoles, true)
            ? $input['role']
            : null;

        $existingUserStmt = $pdo->prepare("
            SELECT id, role, city
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $existingUserStmt->execute([':id' => $userId]);
        $existingUser = $existingUserStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existingUser) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'User not found'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $emailCheckStmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE email = :email AND id <> :id
            LIMIT 1
        ");
        $emailCheckStmt->execute([
            ':email' => $email,
            ':id' => $userId
        ]);
        if ($emailCheckStmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(409);
            echo json_encode([
                'status' => 'error',
                'message' => 'This email is already in use'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pdo->beginTransaction();

        $campaignColumnCheck = $pdo->prepare("SHOW COLUMNS FROM donors LIKE 'campaign_email_notifications'");
        $campaignColumnCheck->execute();
        $hasCampaignEmailColumn = (bool)$campaignColumnCheck->fetch(PDO::FETCH_ASSOC);

        $resolvedCity = $city !== '' ? $city : (string)$existingUser['city'];
        $resolvedLastName = $lastName !== '' ? $lastName : '-';

        $resolvedRole = $newRole ?? $existingUser['role'];

        $updateUserStmt = $pdo->prepare("
            UPDATE users
            SET first_name = :first_name,
                last_name = :last_name,
                email = :email,
                phone = :phone,
                city = :city,
                role = :role
            WHERE id = :id
            LIMIT 1
        ");
        $updateUserStmt->execute([
            ':first_name' => $firstName,
            ':last_name' => $resolvedLastName,
            ':email' => $email,
            ':phone' => $phone !== '' ? $phone : null,
            ':city' => $resolvedCity,
            ':role' => $resolvedRole,
            ':id' => $userId
        ]);

        if ($resolvedRole === 'donor') {
            $allowedBloodTypes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
            if ($bloodType !== '' && !in_array($bloodType, $allowedBloodTypes, true)) {
                throw new InvalidArgumentException('Invalid blood type');
            }

            $donorExistsStmt = $pdo->prepare("
                SELECT id FROM donors WHERE user_id = :user_id LIMIT 1
            ");
            $donorExistsStmt->execute([':user_id' => $userId]);

            $resolvedAvailable = $isAvailable === null ? null : ($isAvailable ? 1 : 0);
            $resolvedEmailNotify = $resolvedAvailable === 0
                ? 0
                : ($emailNotifications === null ? null : ($emailNotifications ? 1 : 0));
            $resolvedCampaignNotify = $campaignEmailNotifications === null
                ? null
                : ($campaignEmailNotifications ? 1 : 0);

            if ($donorExistsStmt->fetch(PDO::FETCH_ASSOC)) {
                $updateSql = "
                    UPDATE donors
                    SET blood_type = COALESCE(:blood_type, blood_type),
                        is_available = COALESCE(:is_available, is_available),
                        email_notifications = COALESCE(:email_notifications, email_notifications)
                ";
                if ($hasCampaignEmailColumn) {
                    $updateSql .= ",
                        campaign_email_notifications = COALESCE(:campaign_email_notifications, campaign_email_notifications)
                    ";
                }
                $updateSql .= "
                    WHERE user_id = :user_id
                    LIMIT 1
                ";
                $updateDonorStmt = $pdo->prepare($updateSql);
                $updateParams = [
                    ':blood_type' => $bloodType !== '' ? $bloodType : null,
                    ':is_available' => $resolvedAvailable,
                    ':email_notifications' => $resolvedEmailNotify,
                    ':user_id' => $userId
                ];
                if ($hasCampaignEmailColumn) {
                    $updateParams[':campaign_email_notifications'] = $resolvedCampaignNotify;
                }
                $updateDonorStmt->execute($updateParams);
            } else {
                $defaultBlood = $bloodType !== '' ? $bloodType : 'O+';
                $availableFlag = $resolvedAvailable === null ? 1 : $resolvedAvailable;
                $emailFlag = $availableFlag === 0 ? 0 : ($resolvedEmailNotify ?? 0);
                $campaignEmailFlag = $resolvedCampaignNotify ?? 0;
                $insertSql = "
                    INSERT INTO donors (user_id, blood_type, is_available, email_notifications";
                if ($hasCampaignEmailColumn) {
                    $insertSql .= ", campaign_email_notifications";
                }
                $insertSql .= ")
                    VALUES (:user_id, :blood_type, :is_available, :email_notifications";
                if ($hasCampaignEmailColumn) {
                    $insertSql .= ", :campaign_email_notifications";
                }
                $insertSql .= ")
                ";
                $insertDonorStmt = $pdo->prepare($insertSql);
                $insertParams = [
                    ':user_id' => $userId,
                    ':blood_type' => $defaultBlood,
                    ':is_available' => $availableFlag,
                    ':email_notifications' => $emailFlag
                ];
                if ($hasCampaignEmailColumn) {
                    $insertParams[':campaign_email_notifications'] = $campaignEmailFlag;
                }
                $insertDonorStmt->execute($insertParams);
            }
        }

        $pdo->commit();

        $campaignEmailSelect = $hasCampaignEmailColumn
            ? "d.campaign_email_notifications"
            : "0 AS campaign_email_notifications";
        $selectUpdatedStmt = $pdo->prepare("
            SELECT
                u.id,
                u.first_name,
                u.last_name,
                u.email,
                u.phone,
                u.city,
                u.role,
                d.blood_type,
                d.is_available,
                d.email_notifications,
                {$campaignEmailSelect},
                d.last_donation_date
            FROM users u
            LEFT JOIN donors d ON d.user_id = u.id
            WHERE u.id = :id
            LIMIT 1
        ");
        $selectUpdatedStmt->execute([':id' => $userId]);
        $updatedUser = $selectUpdatedStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'message' => 'Profile updated successfully',
            'data' => $updatedUser
        ], JSON_UNESCAPED_UNICODE);
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to update profile',
        ], JSON_UNESCAPED_UNICODE);
    }
};
