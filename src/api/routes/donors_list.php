<?php

/**
 * GET donors — joins donors with users for directory-style data (admin-only).
 */
return static function (PDO $pdo): void {
    try {
        auth_require_role('admin');

        $stmt = $pdo->query("
            SELECT
                u.public_id AS user_public_id,
                d.blood_type,
                d.last_donation_date,
                d.is_available,
                d.created_at,
                u.first_name,
                u.last_name,
                u.email,
                u.phone,
                u.city,
                u.role
            FROM donors d
            INNER JOIN users u ON d.user_id = u.id
            ORDER BY d.created_at DESC
        ");

        $donors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'data' => $donors
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to fetch donors',
        ], JSON_UNESCAPED_UNICODE);
    }
};
