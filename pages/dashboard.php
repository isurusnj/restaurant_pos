<?php
require_once __DIR__ . '/../auth_check.php';
$page_title = 'Dashboard';
require_once __DIR__ . '/../config/db.php';


global $pdo;
$page_title = 'Dashboard';
require_once '../config/db.php';

// Simple metrics (you can improve later)
$totalRevenue = $pdo->query("SELECT IFNULL(SUM(amount),0) FROM payments")->fetchColumn();
$totalOrders  = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$totalCustomers = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();

include '../layouts/header.php';
?>
<div class="dashboard-grid">
    <div class="card stat-card">
        <h3>Total Revenue</h3>
        <p class="stat-money">$<?= number_format($totalRevenue, 2) ?></p>
        <span class="stat-sub">From all time</span>
    </div>
    <div class="card stat-card">
        <h3>Total Orders</h3>
        <p class="stat-value"><?= $totalOrders ?></p>
        <span class="stat-sub">From all time</span>
    </div>
    <div class="card stat-card">
        <h3>Total Customers</h3>
        <p class="stat-value"><?= $totalCustomers ?></p>
        <span class="stat-sub">From all time</span>
    </div>

    <!-- placeholders for charts – later you can use chart.js -->
    <div class="card big-card">
        <h3>Orders Overview</h3>
        <div class="placeholder-chart">Chart goes here</div>
    </div>

    <div class="card">
        <h3>Top Products</h3>
        <div class="placeholder-list">
            (Calculate from order_items later)
        </div>
    </div>
</div>
<?php include '../layouts/footer.php'; ?>