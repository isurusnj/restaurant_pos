<?php
require_once __DIR__ . '/../auth_check.php';
$page_title = 'Orders';
require_once __DIR__ . '/../config/db.php';

// --- UPDATE STATUS (from buttons) ---
$allowedStatuses = ['pending','in_progress','ready','served','paid','cancelled'];

if (isset($_GET['id'], $_GET['status'])) {
    $id = (int)$_GET['id'];
    $newStatus = $_GET['status'];

    if ($id > 0 && in_array($newStatus, $allowedStatuses, true)) {
        $stmt = $pdo->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newStatus, $id]);
    }
    header('Location: orders.php');
    exit;
}

// --- LOAD ORDERS ---
$filterStatus = $_GET['filter'] ?? 'all';
$params = [];

$baseSql = "
    SELECT o.*, t.table_name, c.name AS customer_name
    FROM orders o
    LEFT JOIN restaurant_tables t ON o.table_id = t.id
    LEFT JOIN customers c ON c.id = o.customer_id
";

if ($filterStatus !== 'all' && in_array($filterStatus, $allowedStatuses, true)) {
    $baseSql .= " WHERE o.status = ? ";
    $params[] = $filterStatus;
}

$baseSql .= " ORDER BY o.id DESC LIMIT 50 ";

$stmt = $pdo->prepare($baseSql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../ layouts/header.php';
?>

<div class="orders-header-row">
    <h2>Orders</h2>
    <div class="orders-filter">
        <a href="orders.php?filter=all"          class="chip <?= $filterStatus==='all' ? 'chip-active' : '' ?>">All</a>
        <a href="orders.php?filter=pending"      class="chip <?= $filterStatus==='pending' ? 'chip-active' : '' ?>">Pending</a>
        <a href="orders.php?filter=in_progress"  class="chip <?= $filterStatus==='in_progress' ? 'chip-active' : '' ?>">In Progress</a>
        <a href="orders.php?filter=ready"        class="chip <?= $filterStatus==='ready' ? 'chip-active' : '' ?>">Ready</a>
        <a href="orders.php?filter=served"       class="chip <?= $filterStatus==='served' ? 'chip-active' : '' ?>">Served</a>
        <a href="orders.php?filter=paid"         class="chip <?= $filterStatus==='paid' ? 'chip-active' : '' ?>">Paid</a>
        <a href="orders.php?filter=cancelled"    class="chip <?= $filterStatus==='cancelled' ? 'chip-active' : '' ?>">Cancelled</a>
    </div>
</div>

<table class="orders-table">
    <thead>
    <tr>
        <th>#</th>
        <th>Order No</th>
        <th>Customer</th>
        <th>Type</th>
        <th>Table</th>
        <th>Total</th>
        <th>Status</th>
        <th>Created</th>
        <th>Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php if (!$orders): ?>
        <tr><td colspan="9" style="text-align:center;">No orders yet.</td></tr>
    <?php else: ?>
        <?php foreach ($orders as $o): ?>
            <tr>
                <td><?= (int)$o['id'] ?></td>
                <td><?= htmlspecialchars($o['order_number']) ?></td>
                <td><?= $o['customer_name'] ? htmlspecialchars($o['customer_name']) : 'Walk-in' ?></td>
                <td><?= ucfirst(str_replace('_',' ', $o['order_type'])) ?></td>
                <td><?= $o['table_name'] ? htmlspecialchars($o['table_name']) : '-' ?></td>
                <td>$<?= number_format((float)$o['total_amount'], 2) ?></td>
                <td><?= ucfirst($o['status']) ?></td>
                <td><?= htmlspecialchars($o['created_at']) ?></td>
                <td class="orders-actions">
                    <a href="order_view.php?id=<?= (int)$o['id'] ?>" class="btn-chip small">View / Bill</a>
                    <a href="orders.php?id=<?= (int)$o['id'] ?>&status=in_progress" class="btn-chip small">In Progress</a>
                    <a href="orders.php?id=<?= (int)$o['id'] ?>&status=ready"       class="btn-chip small">Ready</a>
                    <a href="orders.php?id=<?= (int)$o['id'] ?>&status=served"      class="btn-chip small">Served</a>
                    <a href="orders.php?id=<?= (int)$o['id'] ?>&status=paid"        class="btn-chip small">Paid</a>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<?php include __DIR__ . '/../ layouts/footer.php'; ?>
