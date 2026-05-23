<?php

/**
 * GET request_comments?request_id= — lists comments for a request.
 * Introspects request_comments columns so older DBs without is_donor/contact_phone still work.
 */
return static function (PDO $pdo): void {
    try {
        $requestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;

        if ($requestId < 1) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Valid request_id is required'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $hasMetadataColumns = false;
        // Schema evolution: newer installs have donor metadata on comments.
        $columnsStmt = $pdo->query("SHOW COLUMNS FROM request_comments");
        if ($columnsStmt) {
            $columnNames = array_map(
                static fn(array $column): string => $column['Field'],
                $columnsStmt->fetchAll(PDO::FETCH_ASSOC)
            );
            $hasMetadataColumns = in_array('is_donor', $columnNames, true)
                && in_array('contact_phone', $columnNames, true);
        }

        $query = $hasMetadataColumns
            ? "
                SELECT
                    id,
                    request_id,
                    author_name AS name,
                    comment_text AS text,
                    is_donor,
                    contact_phone,
                    created_at
                FROM request_comments
                WHERE request_id = :request_id
                ORDER BY created_at DESC
            "
            : "
                SELECT
                    id,
                    request_id,
                    author_name AS name,
                    comment_text AS text,
                    0 AS is_donor,
                    NULL AS contact_phone,
                    created_at
                FROM request_comments
                WHERE request_id = :request_id
                ORDER BY created_at DESC
            ";

        $stmt = $pdo->prepare($query);
        $stmt->execute([':request_id' => $requestId]);

        echo json_encode([
            'status' => 'success',
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to fetch comments',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
};
