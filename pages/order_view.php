<?php
require_once __DIR__ . '/../auth_check.php';
$page_title = 'Order Bill';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../tools/accounting_auto.php';

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
    SELECT method, amount
    FROM payments
    WHERE order_id = ?
    ORDER BY id DESC
    LIMIT 1
");
$payStmt->execute([$orderId]);
$payment = $payStmt->fetch(PDO::FETCH_ASSOC);

// Sum of all payments (could be multiple)
$sumStmt = $pdo->prepare("SELECT IFNULL(SUM(amount),0) AS paid FROM payments WHERE order_id = ?");
$sumStmt->execute([$orderId]);
$totals    = $sumStmt->fetch(PDO::FETCH_ASSOC);
$totalPaid = (float)($totals['paid'] ?? 0);
$balance   = max(0, (float)$order['total_amount'] - $totalPaid);

// Load active receipt template (controls bill layout)
$receiptTemplate = null;
try {
    $tplStmt = $pdo->query("SELECT * FROM receipt_templates WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    $receiptTemplate = $tplStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $receiptTemplate = null;
}

if (!$receiptTemplate) {
    // Fallback defaults if no template active
    $receiptTemplate = [
        'company_name'    => 'Starline Restaurant',
        'address'         => '123 Main Street, City',
        'phone'           => '011-1234567',
        'logo_url'        => '',
        'paper_width_mm'  => 80,
        'margins_mm'      => 3,
        'show_tax'        => 1,
        'show_service'    => 1,
        'show_table_no'   => 1,
        'show_datetime'   => 1,
        'header_lines'    => "Customer Bill",
        'footer_lines'    => "Thank you!\nCome again",
    ];
}

include __DIR__ . '/../layouts/header.php';
?>

<div class="bill-wrapper"
     style="max-width:<?= (int)($receiptTemplate['paper_width_mm'] ?? 80) ?>mm;
             padding:0 <?= (int)($receiptTemplate['margins_mm'] ?? 3) ?>mm;">

    <div class="bill-card" id="print-area">
        <!-- HEADER -->
        <div class="bill-header">
            <?php if (!empty($receiptTemplate['logo_url'])): ?>
                <div style="margin-bottom:6px;">
                    <img src="<?= htmlspecialchars($receiptTemplate['logo_url']) ?>"
                         alt="Logo"
                         style="max-height:40px;">
                </div>
            <?php endif; ?>

            <h2><?= htmlspecialchars($receiptTemplate['company_name'] ?? 'Starline Restaurant') ?></h2>

            <?php if (!empty($receiptTemplate['address'])): ?>
                <p><?= nl2br(htmlspecialchars($receiptTemplate['address'])) ?></p>
            <?php endif; ?>

            <?php if (!empty($receiptTemplate['phone'])): ?>
                <p><?= htmlspecialchars($receiptTemplate['phone']) ?></p>
            <?php endif; ?>

            <?php
            if (!empty($receiptTemplate['header_lines'])) {
                $lines = preg_split('/\r\n|\r|\n/', $receiptTemplate['header_lines']);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    echo '<p>' . htmlspecialchars($line) . '</p>';
                }
            }
            ?>
        </div>

        <!-- META -->
        <div class="bill-meta">
            <div>
                <div><strong>Order No:</strong> <?= htmlspecialchars($order['order_number']) ?></div>
                <div><strong>Type:</strong> <?= ucfirst(str_replace('_', ' ', $order['order_type'])) ?></div>

                <?php if (!empty($receiptTemplate['show_table_no'])): ?>
                    <div><strong>Table:</strong>
                        <?= $order['table_name'] ? htmlspecialchars($order['table_name']) : '-' ?>
                    </div>
                <?php endif; ?>

                <div>
                    <strong>Customer:</strong>
                    <?= $order['customer_name'] ? htmlspecialchars($order['customer_name']) : 'Walk-in' ?>
                    <?php if (!empty($order['customer_phone'])): ?>
                        (<?= htmlspecialchars($order['customer_phone']) ?>)
                    <?php endif; ?>
                </div>
            </div>
            <div style="text-align:right;">
                <?php if (!empty($receiptTemplate['show_datetime'])): ?>
                    <div><strong>Date:</strong> <?= htmlspecialchars(substr($order['created_at'], 0, 10)) ?></div>
                    <div><strong>Time:</strong> <?= htmlspecialchars(substr($order['created_at'], 11, 5)) ?></div>
                <?php endif; ?>
                <div><strong>Cashier:</strong>
                    <?= $order['cashier_name'] ? htmlspecialchars($order['cashier_name']) : '-' ?>
                </div>
            </div>
        </div>

        <!-- ITEMS -->
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
                    <td style="text-align:right;">LKR <?= number_format((float)$it['unit_price'], 2) ?></td>
                    <td style="text-align:right;">LKR <?= number_format((float)$it['total_price'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- TOTALS -->
        <div class="bill-totals">
            <div class="row">
                <span>Subtotal</span>
                <span>LKR <?= number_format((float)$order['subtotal'], 2) ?></span>
            </div>

            <div class="row">
                <span>Discount</span>
                <span>LKR <?= number_format((float)$order['discount_amount'], 2) ?></span>
            </div>

            <?php if (!empty($receiptTemplate['show_tax'])): ?>
                <div class="row">
                    <span>Tax</span>
                    <span>LKR <?= number_format((float)$order['tax_amount'], 2) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($receiptTemplate['show_service'])): ?>
                <div class="row">
                    <span>Service Charge</span>
                    <span>LKR <?= number_format((float)$order['service_charge'], 2) ?></span>
                </div>
            <?php endif; ?>

            <div class="row total">
                <span>Grand Total</span>
                <span>LKR <?= number_format((float)$order['total_amount'], 2) ?></span>
            </div>

            <div class="row">
                <span>Paid</span>
                <span>LKR <?= number_format($totalPaid, 2) ?></span>
            </div>

            <div class="row">
                <span>Balance</span>
                <span>LKR <?= number_format($balance, 2) ?></span>
            </div>

            <?php if ($payment): ?>
                <div class="row">
                    <span>Last Payment</span>
                    <span><?= ucfirst($payment['method']) ?> — LKR <?= number_format((float)$payment['amount'], 2) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- FOOTER TEXT -->
        <div class="bill-footer">
            <?php
            if (!empty($receiptTemplate['footer_lines'])) {
                $lines = preg_split('/\r\n|\r|\n/', $receiptTemplate['footer_lines']);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    echo '<p>' . htmlspecialchars($line) . '</p>';
                }
            } else {
                echo '<p>Thank you for dining with us!</p>';
            }
            ?>
        </div>

        <!-- ACTIONS (PRINT / BACK / EMAIL) -->
        <?php
        // Prefer customer's email if you have it
        $custEmailStmt = $pdo->prepare("SELECT email FROM customers WHERE id = ?");
        $custEmailStmt->execute([$order['customer_id']]);
        $custRow = $custEmailStmt->fetch(PDO::FETCH_ASSOC);
        $defaultEmail = $custRow['email'] ?? '';
        ?>
        <div class="bill-actions" style="gap:8px; flex-wrap:wrap;">
            <button onclick="window.print()" class="btn-primary">Print Bill</button>
            <a href="orders.php" class="btn-chip">Back to Orders</a>

            <form action="send_receipt.php" method="post" style="display:flex; gap:6px; align-items:center;">
                <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
                <input type="email" name="to" placeholder="customer@email"
                       value="<?= htmlspecialchars($defaultEmail) ?>"
                       class="small-input" style="width:220px;">
                <button class="btn-chip">Email Receipt</button>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../layouts/footer.php'; ?>
