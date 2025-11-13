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
$totals = $sumStmt->fetch(PDO::FETCH_ASSOC);
$totalPaid = (float)($totals['paid'] ?? 0);
$balance   = max(0, (float)$order['total_amount'] - $totalPaid);


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
            <div class="row">
                <span>Paid</span>
                <span><?= number_format($totalPaid, 2) ?></span>
            </div>
            <div class="row">
                <span>Balance</span>
                <span><?= number_format($balance, 2) ?></span>
            </div>


            <?php if ($payment): ?>
                <div class="row">
                    <span>Payment</span>
                    <span><?= ucfirst($payment['method']) ?> — $<?= number_format((float)$payment['amount'], 2) ?></span>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($balance > 0): ?>
            <div class="card" style="margin-top:10px; padding:10px; border-radius:12px; background:#f8fafc;">
                <h4 style="margin:0 0 8px;">Record Payment</h4>
                <form action="pay.php" method="post" id="pay-form">
                    <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:8px; align-items:end;">
                        <div class="login-field">
                            <label>Method</label>
                            <select name="method" required>
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="wallet">Wallet</option>
                                <option value="bank">Bank</option>
                            </select>
                        </div>
                        <div class="login-field">
                            <label>Amount</label>
                            <input type="number" step="0.01" name="amount" id="pay-amount"
                                   value="<?= htmlspecialchars(number_format($balance,2,'.','')) ?>" required>
                        </div>
                        <div class="login-field">
                            <label>Cash Given (for change)</label>
                            <input type="number" step="0.01" name="cash_given" id="cash-given" placeholder="optional">
                        </div>
                    </div>
                    <div style="display:flex; justify-content:space-between; margin-top:6px; font-size:13px;">
                        <div>Balance: <strong>$<span id="balance-live"><?= number_format($balance,2) ?></span></strong></div>
                        <div>Change: <strong>$<span id="change-live">0.00</span></strong></div>
                    </div>
                    <button class="btn-primary" style="margin-top:8px;">Save Payment</button>
                </form>
            </div>

            <script>
                const amountEl = document.getElementById('pay-amount');
                const cashEl   = document.getElementById('cash-given');
                const balEl    = document.getElementById('balance-live');
                const changeEl = document.getElementById('change-live');
                const origBal  = <?= json_encode($balance) ?>;

                function recalc() {
                    const amt   = parseFloat(amountEl.value || '0');
                    const cash  = parseFloat(cashEl.value   || '0');
                    const newBal = Math.max(0, (origBal - (isNaN(amt)?0:amt)));
                    const change = Math.max(0, (isNaN(cash)?0:cash) - (isNaN(amt)?0:amt));
                    balEl.textContent   = newBal.toFixed(2);
                    changeEl.textContent= change.toFixed(2);
                }
                amountEl.addEventListener('input', recalc);
                cashEl.addEventListener('input', recalc);
                recalc();
            </script>
        <?php endif; ?>



        <div class="bill-footer">
            <p>Thank you for dining with us!</p>
            <p>Powered by Your POS System</p>
        </div>
    </div>

    <div class="bill-actions">
        <button onclick="window.print()" class="btn-primary">Print Bill</button>
        <a href="orders.php" class="btn-chip">Back to Orders</a>
    </div>
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

<?php include __DIR__ . '/../ layouts/footer.php'; ?>
