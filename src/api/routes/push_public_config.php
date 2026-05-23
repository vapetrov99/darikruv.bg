<?php

/**
 * GET push_public_config
 *
 * Returns only public configuration for browser Firebase initialization:
 * - firebase: apiKey, projectId, etc. (from config/push.php / environment)
 * - vapid_public_key: required for Web Push token registration via messaging.getToken()
 *
 * Does NOT return service account credentials or any server-only secrets.
 */
return static function (PDO $pdo): void {
    // $pdo is unused but kept so all route handlers share the same (PDO): void signature in index.php.
    $pushConfig = require __DIR__ . '/../../config/push.php';

    echo json_encode([
        'status' => 'success',
        'data' => [
            'vapid_public_key' => $pushConfig['vapid_public_key'] ?? '',
            'firebase' => $pushConfig['firebase'] ?? []
        ]
    ], JSON_UNESCAPED_UNICODE);
};
