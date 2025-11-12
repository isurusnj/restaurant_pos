<?php
session_start();
require_once __DIR__ . '/config/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 1 LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && $user['password_hash'] === md5($password)) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['role_id']   = $user['role_id'];
            $_SESSION['full_name'] = $user['name'];

            header('Location: /restaurant_pos/pages/dashboard.php');
            exit;
        } else {
            $error = 'Invalid login details.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - POS</title>
    <link rel="stylesheet" href="/restaurant_pos/public/css/style.css">
</head>
<body class="login-body">
<div class="login-box">
    <h2>Restaurant POS Login</h2>

    <?php if ($error): ?>
        <div class="login-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="login-field">
            <label>Username</label>
            <input type="text" name="username" required>
        </div>
        <div class="login-field">
            <label>Password</label>
            <input type="password" name="password" required>
        </div>
        <button type="submit" class="btn-primary full-width">Login</button>
    </form>
</div>
</body>
</html>
