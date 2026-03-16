<?php

require_once __DIR__ . '/config/database.php';

try {
    $sql = "INSERT INTO users (first_name, last_name, email, password, phone, role)
            VALUES (:first_name, :last_name, :email, :password, :phone, :role)";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':first_name' => 'Ivan',
        ':last_name' => 'Petrov',
        ':email' => 'ivan@example.com',
        ':password' => password_hash('123456', PASSWORD_DEFAULT),
        ':phone' => '+359888123456',
        ':role' => 'donor'
    ]);

    echo "Test user inserted successfully!";
} catch (PDOException $e) {
    echo "Insert failed: " . htmlspecialchars($e->getMessage());
}