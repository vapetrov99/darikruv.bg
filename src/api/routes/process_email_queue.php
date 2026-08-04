<?php

/**
 * POST process_email_queue — admin worker endpoint for draining queued notification emails.
 * Body (optional): { "batch_size": number }
 */
return static function (PDO $pdo): void {
    try {
        auth_require_role('admin');
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }
        $batchSize = (int)($input['batch_size'] ?? 20);

        $notificationService = new NotificationService($pdo);
        $result = $notificationService->processEmailQueue($batchSize);

        echo json_encode([
            'status' => 'success',
            'message' => 'Email queue processed',
            'data' => $result
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to process email queue',
        ], JSON_UNESCAPED_UNICODE);
    }
};
