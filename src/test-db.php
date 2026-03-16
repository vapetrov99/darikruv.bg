<?php

require_once __DIR__ . '/config/database.php';

try {
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "<h1>Database connection successful!</h1>";
    echo "<h2>Tables in database:</h2>";
    echo "<ul>";

    foreach ($tables as $table) {
        echo "<li>" . htmlspecialchars($table) . "</li>";
    }

    echo "</ul>";
} catch (PDOException $e) {
    echo "Query failed: " . htmlspecialchars($e->getMessage());
}