<?php
// 403.php – Access denied page

require_once __DIR__ . '/../auth_check.php';
$page_title = 'Access denied';

// If you use roles, you can read it from the session:
$role = $_SESSION['role'] ?? 'unknown';

include __DIR__ . '/../layouts/header.php';
?>

<div class="card">
    <h2>Access denied</h2>
    <p>Your role (<?= htmlspecialchars($role) ?>) doesn’t allow access to this page.</p>

    <p style="margin-top:6px;">
        If you think this is a mistake, ask an admin to update your role
        from <strong>Settings → Roles &amp; Permissions</strong>.
    </p>

    <p style="margin-top:10px;">
        <a href="index.php" class="btn-chip">Back to dashboard</a>
    </p>
</div>

<?php include __DIR__ . '/../layouts/footer.php'; ?>