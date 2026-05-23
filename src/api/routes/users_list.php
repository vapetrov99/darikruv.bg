<?php

/**
 * GET users — returns a simple list of users (admin-style listing; no auth middleware in this MVP).
 */
return static function (PDO $pdo): void {
    try {
        $stmt = $pdo->query("
            SELECT id, first_name, last_name, email, phone, city, role, created_at
            FROM users
            ORDER BY id DESC
        ");

        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'data' => $users
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        http_response_code(500);

        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to fetch users',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
};
