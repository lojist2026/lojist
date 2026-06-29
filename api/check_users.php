<?php
require_once __DIR__ . "/../app/bootstrap.php";
$pdo = db();
$stmt = $pdo->query("SELECT email, password_hash, role FROM users");
$users = $stmt->fetchAll();
echo "<pre>";
print_r($users);
echo "</pre>";
