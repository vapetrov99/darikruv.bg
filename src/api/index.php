<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$route = $_GET['route'] ?? '';

if ($method === 'GET' && $route === 'users') {
    try {
        $stmt = $pdo->query("
            SELECT id, first_name, last_name, email, phone, role, created_at
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

    exit;
}

http_response_code(404);

echo json_encode([
    'status' => 'error',
    'message' => 'Route not found'
], JSON_UNESCAPED_UNICODE);