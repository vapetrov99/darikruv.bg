<?php

/**
 * POST update_last_donation — JSON body: user_id, last_donation_date (YYYY-MM-DD); updates donors row.
 */
return static function (PDO $pdo): void {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
        $lastDonationDate = trim($input['last_donation_date'] ?? '');

        if ($userId <= 0 || $lastDonationDate === '') {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'user_id and last_donation_date are required'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $date = DateTime::createFromFormat('Y-m-d', $lastDonationDate);
        if (!$date || $date->format('Y-m-d') !== $lastDonationDate) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid date format. Expected YYYY-MM-DD'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $today = (new DateTime('today'))->format('Y-m-d');
        if ($lastDonationDate > $today) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Датата не може да бъде в бъдещето.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $userStmt = $pdo->prepare("SELECT id FROM users WHERE id = :user_id LIMIT 1");
        $userStmt->execute([':user_id' => $userId]);
        if (!$userStmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'Потребителят не е намерен.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $check = $pdo->prepare("SELECT user_id FROM donors WHERE user_id = :user_id LIMIT 1");
        $check->execute([':user_id' => $userId]);
        $donor = $check->fetch(PDO::FETCH_ASSOC);

        if (!$donor) {
            $campaignColumnCheck = $pdo->prepare("SHOW COLUMNS FROM donors LIKE 'campaign_email_notifications'");
            $campaignColumnCheck->execute();
            $hasCampaignEmailColumn = (bool)$campaignColumnCheck->fetch(PDO::FETCH_ASSOC);

            $insertSql = "
                INSERT INTO donors (user_id, blood_type, is_available, email_notifications";
            if ($hasCampaignEmailColumn) {
                $insertSql .= ", campaign_email_notifications";
            }
            $insertSql .= ")
                VALUES (:user_id, :blood_type, 1, 0";
            if ($hasCampaignEmailColumn) {
                $insertSql .= ", 0";
            }
            $insertSql .= ")
            ";

            $insertDonorStmt = $pdo->prepare($insertSql);
            $insertDonorStmt->execute([
                ':user_id' => $userId,
                ':blood_type' => 'O+'
            ]);
        }

        $stmt = $pdo->prepare("
            UPDATE donors
            SET last_donation_date = :last_donation_date
            WHERE user_id = :user_id
        ");
        $stmt->execute([
            ':last_donation_date' => $lastDonationDate,
            ':user_id' => $userId
        ]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Last donation date updated',
            'data' => [
                'user_id' => $userId,
                'last_donation_date' => $lastDonationDate
            ]
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to update donation date',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
};
