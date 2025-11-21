<?php
if (session_status() === PHP_SESSION_NONE) {
// auth_check.php (example)
session_start();
    if (!isset($_SESSION['user_id'])) {
        header('Location: /restaurant_pos/login.php'); exit;
    }
    require_once __DIR__ . '/config/db.php';
    $st = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $st->execute([$_SESSION['user_id']]);
    $_SESSION['role'] = strtolower(trim((string)$st->fetchColumn() ?: 'cashier'));

}
