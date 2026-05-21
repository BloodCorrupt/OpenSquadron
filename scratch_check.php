<?php
try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=opensquadron;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "=== ALL SUBSCRIBERS ===\n";
    $stmt = $pdo->query("SELECT * FROM subscriber");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
