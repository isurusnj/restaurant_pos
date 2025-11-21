<?php
session_start();
require_once __DIR__ . '/config/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else {
        // Adjust columns to your users table
        $stmt = $pdo->prepare("
            SELECT id, name, username, password_hash, role, status
            FROM users
            WHERE username = ?
            LIMIT 1
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = 'Invalid username or password.';
        } elseif (isset($user['status']) && (int)$user['status'] === 0) {
            $error = 'This account is disabled. Please contact admin.';
        } else {
            $stored = (string)($user['password_hash'] ?? '');
            $ok     = false;

            if ($stored !== '') {
                // Support old MD5-style hashes AND new password_hash() hashes
                if (preg_match('/^[a-f0-9]{32}$/i', $stored)) {
                    // legacy: column contains md5(password)
                    $ok = (md5($password) === $stored);
                } else {
                    // modern: column contains password_hash()
                    $ok = password_verify($password, $stored);
                }
            }

            if (!$ok) {
                $error = 'Invalid username or password.';
            } else {
                // Save session
                $_SESSION['user_id']   = (int)$user['id'];
                $_SESSION['username']  = $user['username'] ?? '';
                $_SESSION['full_name'] = $user['name'] ?? '';
                $_SESSION['role']      = strtolower($user['role'] ?? 'cashier');

                // ✅ FIXED REDIRECT: go to a REAL page that exists
                // We know from your project that this file exists:
                //   /restaurant_pos/pages/dashboard.php
                header('Location: /restaurant_pos/pages/dashboard.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Login - POS</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <!-- Use your existing CSS -->
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
                <input type="text" name="username" required autocomplete="username">
            </div>
            <div class="login-field">
                <label>Password</label>
                <input type="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn-primary full-width" style="margin-top:10px;">Login</button>
        </form>
    </div>
</body>

</html>