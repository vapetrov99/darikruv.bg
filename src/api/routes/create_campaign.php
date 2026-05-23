<?php

/**
 * POST create_campaign — manual trigger that sends campaign emails to opted-in donors.
 */
return static function (PDO $pdo): void {
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        $title = trim((string)($input['title'] ?? ''));
        $city = trim((string)($input['city'] ?? ''));
        $dateLabel = trim((string)($input['date'] ?? ''));
        $campaignLink = trim((string)($input['link'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));

        if ($title === '' || $city === '' || $dateLabel === '' || $campaignLink === '') {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'title, city, date and link are required'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!filter_var($campaignLink, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'A valid campaign link is required'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $columnCheck = $pdo->prepare("SHOW COLUMNS FROM donors LIKE 'campaign_email_notifications'");
        $columnCheck->execute();
        if (!$columnCheck->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Missing DB column campaign_email_notifications. Run migration_campaign_notifications.sql first.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $notifications = new NotificationService($pdo);
        $stats = $notifications->notifyDonorsForCampaign(
            $title,
            $city,
            $dateLabel,
            $campaignLink,
            $description
        );

        echo json_encode([
            'status' => 'success',
            'message' => 'Campaign notification process completed',
            'data' => $stats
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Campaign notifications failed',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
};

