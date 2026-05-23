<?php

/**
 * POST create_request_comment — appends a comment; payload uses name/text aliases for the frontend.
 * Same SHOW COLUMNS fallback as the list route for backwards-compatible inserts.
 */
return static function (PDO $pdo): void {
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        $requestId = (int)($input['request_id'] ?? 0);
        $authorName = trim($input['name'] ?? '');
        $commentText = trim($input['text'] ?? '');
        $isDonor = (int)(!empty($input['is_donor']));
        $contactPhone = trim($input['contact_phone'] ?? '');

        if ($requestId < 1 || $authorName === '' || $commentText === '') {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'request_id, name and text are required'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $existsStmt = $pdo->prepare("
            SELECT id
            FROM blood_requests
            WHERE id = :id
            LIMIT 1
        ");
        $existsStmt->execute([':id' => $requestId]);
        if (!$existsStmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'Blood request not found'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $hasMetadataColumns = false;
        $columnsStmt = $pdo->query("SHOW COLUMNS FROM request_comments");
        if ($columnsStmt) {
            $columnNames = array_map(
                static fn(array $column): string => $column['Field'],
                $columnsStmt->fetchAll(PDO::FETCH_ASSOC)
            );
            $hasMetadataColumns = in_array('is_donor', $columnNames, true)
                && in_array('contact_phone', $columnNames, true);
        }

        if ($hasMetadataColumns) {
            $insertStmt = $pdo->prepare("
                INSERT INTO request_comments (
                    request_id,
                    author_name,
                    comment_text,
                    is_donor,
                    contact_phone
                )
                VALUES (
                    :request_id,
                    :author_name,
                    :comment_text,
                    :is_donor,
                    :contact_phone
                )
            ");
            $insertStmt->execute([
                ':request_id' => $requestId,
                ':author_name' => $authorName,
                ':comment_text' => $commentText,
                ':is_donor' => $isDonor,
                ':contact_phone' => $contactPhone !== '' ? $contactPhone : null
            ]);
        } else {
            $insertStmt = $pdo->prepare("
                INSERT INTO request_comments (request_id, author_name, comment_text)
                VALUES (:request_id, :author_name, :comment_text)
            ");
            $insertStmt->execute([
                ':request_id' => $requestId,
                ':author_name' => $authorName,
                ':comment_text' => $commentText
            ]);
        }

        $commentId = (int)$pdo->lastInsertId();

        $commentQuery = $hasMetadataColumns
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
                WHERE id = :id
                LIMIT 1
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
                WHERE id = :id
                LIMIT 1
            ";

        $commentStmt = $pdo->prepare($commentQuery);
        $commentStmt->execute([':id' => $commentId]);
        $comment = $commentStmt->fetch(PDO::FETCH_ASSOC);

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'message' => 'Comment created successfully',
            'data' => $comment
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to create comment',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
};
