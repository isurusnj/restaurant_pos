<?php
// Entry point redirect — always send to dashboard
header("Location: pages/dashboard.php");
exit;
require_once __DIR__ . '/auth_check.php';
$page_title = 'Dashboard';
require_once __DIR__ . '/config/db.php';

include __DIR__ . '/layouts/header.php';
?>
<div class="card">
    <h2>Welcome to Restaurant POS</h2>
    <p>Select a module from the left menu to begin (POS, Orders, Reports, Settings, etc.).</p>
</div>
<?php include __DIR__ . '/layouts/footer.php'; ?>