<?php
try {
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=opensquadron', 'root', '');
    $stmt = $pdo->query('SELECT * FROM facebook_comment_automation');
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo $e->getMessage();
}
