<?php

/**
 * Shared PDO instance for the whole PHP app.
 *
 * In Docker Compose the MySQL service is typically named "mysql"; credentials here match dev defaults.
 * For production, replace with environment-driven configuration and never commit real passwords.
 */

$host = 'mysql';
$dbname = 'darikruv';
$username = 'root';
$password = 'root';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}
