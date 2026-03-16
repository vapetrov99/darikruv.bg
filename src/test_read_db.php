<?php

require_once __DIR__ . '/config/database.php';

try {
    $stmt = $pdo->query("SELECT id, first_name, last_name, email, phone, role, created_at FROM users ORDER BY id DESC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<h1>Users</h1>";

    if (!$users) {
        echo "<p>No users found.</p>";
        exit;
    }

    echo "<ul>";

    foreach ($users as $user) {
        echo "<li>";
        echo htmlspecialchars($user['id']) . " - ";
        echo htmlspecialchars($user['first_name']) . " ";
        echo htmlspecialchars($user['last_name']) . " - ";
        echo htmlspecialchars($user['email']) . " - ";
        echo htmlspecialchars($user['role']). " - ";
        echo htmlspecialchars($user['phone']);
        echo "</li>";
    }

    echo "</ul>";
} catch (PDOException $e) {
    echo "Select failed: " . htmlspecialchars($e->getMessage());
}