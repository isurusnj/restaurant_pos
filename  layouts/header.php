<?php
if (!isset($page_title)) { $page_title = 'POS System'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link rel="stylesheet" href="/restaurant_pos/public/css/style.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand">Starline</div>
        <nav>
            <a href="/restaurant_pos/pages/menu.php">Menu</a>
            <a href="/restaurant_pos/pages/dashboard.php">Dashboard</a>
            <a href="/restaurant_pos/pages/pos.php">POS</a>
            <a href="/restaurant_pos/pages/orders.php">Orders</a>
            <a href="#">Sales</a>
            <a href="#">Accounting</a>
            <a href="#">Purchase</a>
            <a href="/restaurant_pos/pages/kitchen.php">Kitchen</a>
            <a href="/restaurant_pos/pages/inventory.php">Inventory</a>
            <a href="/restaurant_pos/pages/customers.php">Customers</a>
            <a href="#">Payroll</a>
            <a href="/restaurant_pos/pages/reports.php">Reports</a>
            <a href="#">Settings</a>
            <a href="#">Help</a>
        </nav>
    </aside>
    <main class="main-content">
        <header class="topbar">
            <div class="welcome">
                Welcome, <?= isset($_SESSION['full_name']) ? htmlspecialchars($_SESSION['full_name']) : 'User' ?> 👋
            </div>
            <div class="topbar-right">
                <a href="/restaurant_pos/logout.php" class="logout-link">Logout</a>
            </div>
        </header>

        <section class="content">
