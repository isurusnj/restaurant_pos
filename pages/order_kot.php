<?php
require_once __DIR__ . '/../auth_check.php';
$page_title = 'KOT';
require_once __DIR__ . '/../config/db.php';

/* ---------------- LOAD ORDER ---------------- */
$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($orderId <= 0) die('Invalid order ID.');

$stmt = $pdo->prepare("
  SELECT o.*, t.table_name
  FROM orders o
  LEFT JOIN restaurant_tables t ON o.table_id = t.id
  WHERE o.id = ?
");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) die('Order not found.');

/* ---------------- LOAD KOT ITEMS ---------------- */
$itemsStmt = $pdo->prepare("
  SELECT oi.qty, m.name
  FROM order_items oi
  JOIN menu_items m ON m.id = oi.menu_item_id
  WHERE oi.order_id = ?
");
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------------- LOAD ACTIVE TEMPLATE ---------------- */
$receiptTemplate = null;
try {
    $tplStmt = $pdo->query("SELECT * FROM receipt_templates WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    $receiptTemplate = $tplStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $receiptTemplate = null;
}

/* --- Default values if no template is active --- */
if (!$receiptTemplate) {
    $receiptTemplate = [
        'kot_title'      => 'KITCHEN ORDER TICKET',
        'kot_show_table' => 1,
        'kot_show_time'  => 1,
        'paper_width_mm' => 80,
        'margins_mm'     => 3
    ];
}

/* ---------------- INCLUDE HEADER ---------------- */
include __DIR__ . '/../layouts/header.php';
?>

<div class="bill-wrapper"
    style="max-width:<?= (int)$receiptTemplate['paper_width_mm'] ?>mm;
            padding:0 <?= (int)$receiptTemplate['margins_mm'] ?>mm;">

    <div class="bill-card" id="print-area">

        <!-- KOT Header -->
        <div class="bill-header">
            <h2><?= htmlspecialchars($receiptTemplate['kot_title']) ?></h2>

            <p><strong>Order:</strong> <?= htmlspecialchars($order['order_number']) ?></p>
            <p><strong>Type:</strong> <?= ucfirst(str_replace('_', ' ', $order['order_type'])) ?></p>

            <?php if (!empty($receiptTemplate['kot_show_table'])): ?>
                <p><strong>Table:</strong>
                    <?= $order['table_name'] ? htmlspecialchars($order['table_name']) : '-' ?>
                </p>
            <?php endif; ?>

            <?php if (!empty($receiptTemplate['kot_show_time'])): ?>
                <p><strong>Date:</strong> <?= substr($order['created_at'], 0, 10) ?></p>
                <p><strong>Time:</strong> <?= substr($order['created_at'], 11, 5) ?></p>
            <?php endif; ?>
        </div>

        <!-- Item list -->
        <table class="bill-items">
            <thead>
                <tr>
                    <th style="text-align:left;">Item</th>
                    <th style="width:80px;text-align:center;">Qty</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= htmlspecialchars($it['name']) ?></td>
                        <td style="text-align:center;font-weight:700;"><?= (int)$it['qty'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="bill-footer">
            <p>— Send to kitchen —</p>
        </div>
    </div>

    <div class="bill-actions">
        <button onclick="window.print()" class="btn-primary">Print KOT</button>
        <a href="orders.php" class="btn-chip">Back to Orders</a>
    </div>
</div>

<?php include __DIR__ . '/../layouts/footer.php'; ?>