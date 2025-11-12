<?php
$host = 'localhost';
$db   = 'restaurant_pos';
$user = 'root';       // XAMPP default
$pass = '';          // XAMPP default (empty)

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB Connection failed: " . $e->getMessage());
}
