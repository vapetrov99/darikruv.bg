<?php

/**
 * Shared blood_requests maintenance (waiting expiry).
 */
function expireStaleWaitingRequests(PDO $pdo): void
{
    $expiredStmt = $pdo->query("
        SELECT id
        FROM blood_requests
        WHERE status = 'waiting'
          AND waiting_until IS NOT NULL
          AND waiting_until < NOW()
    ");
    $expiredIds = $expiredStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$expiredIds) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($expiredIds), '?'));

    $pdo->prepare("
        UPDATE blood_requests
        SET status = 'active',
            waiting_until = NULL
        WHERE id IN ($placeholders)
    ")->execute($expiredIds);

    $pdo->prepare("
        DELETE FROM request_responses
        WHERE request_id IN ($placeholders)
          AND response_status = 'pending'
    ")->execute($expiredIds);
}
