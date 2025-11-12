<?php
require_once __DIR__ . '/../auth_check.php';
$page_title = 'Order Bill';
require_once __DIR__ . '/../config/db.php';

$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($orderId <= 0) {
    die('Invalid order ID.');
}

// Load order (now includes customer info)
$stmt = $pdo->prepare("
    SELECT o.*,
           t.table_name,
           u.name AS cashier_name,
           c.name AS customer_name,
           c.phone AS customer_phone
    FROM orders o
    LEFT JOIN restaurant_tables t ON o.table_id = t.id
    LEFT JOIN users u ON o.created_by = u.id
    LEFT JOIN customers c ON c.id = o.customer_id
    WHERE o.id = ?
");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die('Order not found.');
}

// Load items
$itemStmt = $pdo->prepare("
    SELECT oi.*, m.name
    FROM order_items oi
    JOIN menu_items m ON oi.menu_item_id = m.id
    WHERE oi.order_id = ?
");
$itemStmt->execute([$orderId]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

// Latest payment (optional)
$payStmt = $pdo->prepare("
    SELECT method, amount, created_at
    FROM payments
    WHERE order_id = ?
    ORDER BY id DESC
    LIMIT 1
");
$payStmt->execute([$orderId]);
$payment = $payStmt->fetch(PDO::FETCH_ASSOC);

include __DIR__ . '/../ layouts/header.php';
?>

<div class="bill-wrapper">
    <div class="bill-card" id="print-area">
        <div class="bill-header">
            <h2>Starline Restaurant</h2>
            <p>123 Main Street, City</p>
            <p>Tel: 011-1234567</p>
        </div>

        <div class="bill-meta">
            <div>
                <div><strong>Order No:</strong> <?= htmlspecialchars($order['order_number']) ?></div>
                <div><strong>Type:</strong> <?= ucfirst(str_replace('_', ' ', $order['order_type'])) ?></div>
                <div><strong>Table:</strong> <?= $order['table_name'] ? htmlspecialchars($order['table_name']) : '-' ?></div>
                <div>
                    <strong>Customer:</strong>
                    <?= $order['customer_name'] ? htmlspecialchars($order['customer_name']) : 'Walk-in' ?>
                    <?php if (!empty($order['customer_phone'])): ?>
                        (<?= htmlspecialchars($order['customer_phone']) ?>)
                    <?php endif; ?>
                </div>
            </div>
            <div style="text-align:right;">
                <div><strong>Date:</strong> <?= htmlspecialchars(substr($order['created_at'], 0, 10)) ?></div>
                <div><strong>Time:</strong> <?= htmlspecialchars(substr($order['created_at'], 11, 5)) ?></div>
                <div><strong>Cashier:</strong> <?= $order['cashier_name'] ? htmlspecialchars($order['cashier_name']) : '-' ?></div>
            </div>
        </div>

        <table class="bill-items">
            <thead>
            <tr>
                <th style="text-align:left;">Item</th>
                <th>Qty</th>
                <th>Price</th>
                <th>Total</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td><?= htmlspecialchars($it['name']) ?></td>
                    <td style="text-align:center;"><?= (int)$it['qty'] ?></td>
                    <td style="text-align:right;"><?= number_format((float)$it['unit_price'], 2) ?></td>
                    <td style="text-align:right;"><?= number_format((float)$it['total_price'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="bill-totals">
            <div class="row">
                <span>Subtotal</span>
                <span><?= number_format((float)$order['subtotal'], 2) ?></span>
            </div>
            <div class="row">
                <span>Discount</span>
                <span><?= number_format((float)$order['discount_amount'], 2) ?></span>
            </div>
            <div class="row">
                <span>Tax</span>
                <span><?= number_format((float)$order['tax_amount'], 2) ?></span>
            </div>
            <div class="row">
                <span>Service Charge</span>
                <span><?= number_format((float)$order['service_charge'], 2) ?></span>
            </div>
            <div class="row total">
                <span>Grand Total</span>
                <span><?= number_format((float)$order['total_amount'], 2) ?></span>
            </div>

            <?php if ($payment): ?>
                <div class="row">
                    <span>Payment</span>
                    <span><?= ucfirst($payment['method']) ?> — $<?= number_format((float)$payment['amount'], 2) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <div class="bill-footer">
            <p>Thank you for dining with us!</p>
            <p>Powered by Your POS System</p>
        </div>
    </div>

    <div class="bill-actions">
        <button onclick="window.print()" class="btn-primary">Print Bill</button>
        <a href="orders.php" class="btn-chip">Back to Orders</a>
    </div>
</div>

<?php include __DIR__ . '/../ layouts/footer.php'; ?>
