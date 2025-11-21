<?php
require_once __DIR__ . '/../auth_check.php';
$page_title = 'Purchase';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../tools/accounting_auto.php';


$success = '';
$error   = '';

/* Load menu items for dropdown */
$menuItems = $pdo->query("
    SELECT id, name
    FROM menu_items
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

$menuById = [];
foreach ($menuItems as $mi) {
    $menuById[(int)$mi['id']] = $mi['name'];
}

/* ---------------- SAVE PURCHASE (with stock update) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {
        $supplier = trim($_POST['supplier'] ?? '');
        $method   = $_POST['payment_method'] ?? 'cash';  // cash, card, bank, wallet
        $date     = $_POST['purchase_date'] ?? date('Y-m-d');
        $menuIds  = $_POST['menu_item_id'] ?? [];
        $qtys     = $_POST['qty'] ?? [];
        $prices   = $_POST['price'] ?? [];

        if ($supplier === '') {
            throw new Exception("Supplier is required.");
        }

        if (empty($menuIds)) {
            throw new Exception("Add at least one item.");
        }

        /* --- Calculate total --- */
        $total = 0;
        $lines = [];

        foreach ($menuIds as $i => $mid) {
            $mid = (int)$mid;
            if ($mid <= 0) {
                // No selected item in this row -> ignore whole row
                continue;
            }

            $q = isset($qtys[$i]) ? (float)$qtys[$i] : 0;
            $p = isset($prices[$i]) ? (float)$prices[$i] : 0;

            // Ignore lines with no quantity or negative price
            if ($q <= 0 || $p < 0) {
                continue;
            }

            $lineTotal = $q * $p;
            $total += $lineTotal;

            $lines[] = [
                'menu_item_id' => $mid,
                'name'         => $menuById[$mid] ?? ('Item #' . $mid),
                'qty'          => $q,
                'price'        => $p,
                'total'        => $lineTotal
            ];
        }

        if ($total <= 0 || empty($lines)) {
            throw new Exception("Total amount must be greater than 0 and at least one valid line is required.");
        }

        /* --- Insert purchase header --- */
        // table: purchases(id, supplier, total_amount, payment_method, created_at)
        $stmt = $pdo->prepare("
            INSERT INTO purchases (supplier, total_amount, payment_method, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$supplier, $total, $method]);
        $purchaseId = (int)$pdo->lastInsertId();

        /* --- Insert purchase items + update stock --- */
        // table: purchase_items(id, purchase_id, menu_item_id, item_name, qty, unit_price, total_price)
        $lineStmt  = $pdo->prepare("
            INSERT INTO purchase_items (purchase_id, menu_item_id, item_name, qty, unit_price, total_price)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stockStmt = $pdo->prepare("
    UPDATE menu_items
    SET stock_qty = COALESCE(stock_qty, 0) + ?
    WHERE id = ?
");


        foreach ($lines as $ln) {
            $lineStmt->execute([
                $purchaseId,
                $ln['menu_item_id'],
                $ln['name'],
                $ln['qty'],
                $ln['price'],
                $ln['total']
            ]);

            // Auto increase stock
            $stockStmt->execute([
                $ln['qty'],
                $ln['menu_item_id']
            ]);
        }

        /* ---- AUTO ACCOUNTING ---- */
        try {
            record_purchase_journal($pdo, $purchaseId, $total, $method);
        } catch (Throwable $ex) {
            // Don't break the page if accounting fails.
        }

        $success = "Purchase recorded successfully. (Total LKR " . number_format($total, 2) . ")";
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

/* ---------------- LOAD RECENT PURCHASES (for list below) ---------------- */
$purchases = $pdo->query("
    SELECT id, supplier, total_amount, payment_method, created_at
    FROM purchases
    ORDER BY id DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../layouts/header.php';
?>

<div class="card">
    <h2 style="margin-bottom:10px;">New Purchase</h2>

    <?php if ($success): ?>
        <div style="background:#e6ffe6; color:#088a08; padding:8px; border-radius:8px; margin-bottom:10px;">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div style="background:#ffe0e0; color:#b30000; padding:8px; border-radius:8px; margin-bottom:10px;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="post">

        <div class="login-field">
            <label>Supplier Name</label>
            <input type="text" name="supplier" required>
        </div>

        <div class="login-field">
            <label>Purchase Date</label>
            <input type="date" name="purchase_date" value="<?= date('Y-m-d') ?>" required>
        </div>

        <div class="login-field">
            <label>Payment Method</label>
            <select name="payment_method" required>
                <option value="cash">Cash</option>
                <option value="card">Card</option>
                <option value="bank">Bank</option>
                <option value="wallet">Wallet</option>
            </select>
        </div>

        <h3 style="margin:15px 0 8px;">Items</h3>

        <table class="orders-table" id="purchase-table">
            <thead>
                <tr>
                    <th>Menu Item</th>
                    <th style="width:80px;">Qty</th>
                    <th style="width:120px;">Unit Price (LKR)</th>
                    <th style="width:120px;">Total (LKR)</th>
                    <th style="width:60px;">Remove</th>
                </tr>
            </thead>
            <tbody id="purchase-body">
                <tr>
                    <td>
                        <select name="menu_item_id[]">
                            <option value="">-- Select item --</option>
                            <?php foreach ($menuItems as $mi): ?>
                                <option value="<?= (int)$mi['id'] ?>"><?= htmlspecialchars($mi['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="number" step="0.01" name="qty[]"></td>
                    <td><input type="number" step="0.01" name="price[]"></td>
                    <td class="line-total">0.00</td>
                    <td>
                        <button type="button" class="btn-chip small remove-row">X</button>
                    </td>
                </tr>
            </tbody>
        </table>

        <button type="button" class="btn-chip" onclick="addRow()">Add Item</button>

        <div style="margin-top:14px; font-size:16px;">
            <strong>Total: LKR <span id="grand-total">0.00</span></strong>
        </div>

        <button class="btn-primary" style="margin-top:15px;">Save Purchase</button>
    </form>
</div>

<div class="card" style="margin-top:16px;">
    <h2>Recent Purchases</h2>

    <?php if (!$purchases): ?>
        <p>No purchases found.</p>
    <?php else: ?>
        <table class="orders-table" style="margin-top:10px;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Supplier</th>
                    <th>Total (LKR)</th>
                    <th>Method</th>
                    <th>Date</th>
                    <th>View</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($purchases as $p): ?>
                    <tr>
                        <td><?= (int)$p['id'] ?></td>
                        <td><?= htmlspecialchars($p['supplier']) ?></td>
                        <td><?= number_format($p['total_amount'], 2) ?></td>
                        <td><?= ucfirst($p['payment_method']) ?></td>
                        <td><?= htmlspecialchars($p['created_at']) ?></td>
                        <td>
                            <a href="purchase_view.php?id=<?= (int)$p['id'] ?>" class="btn-chip small">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
    function addRow() {
        let template = document.querySelector('#purchase-body tr');
        let clone = template.cloneNode(true);

        // Clear inputs and reset select + total
        clone.querySelectorAll('input').forEach(i => i.value = '');
        clone.querySelector('.line-total').textContent = '0.00';
        let sel = clone.querySelector('select[name="menu_item_id[]"]');
        if (sel) sel.selectedIndex = 0;

        document.querySelector('#purchase-body').appendChild(clone);
    }

    // Update line totals + grand total
    document.addEventListener('input', function(e) {
        if (e.target.name === 'qty[]' || e.target.name === 'price[]') {
            let row = e.target.closest('tr');
            let qtyInput = row.querySelector('input[name="qty[]"]');
            let priceInput = row.querySelector('input[name="price[]"]');

            if (!qtyInput || !priceInput) return;

            let qty = parseFloat(qtyInput.value) || 0;
            let price = parseFloat(priceInput.value) || 0;
            let total = qty * price;
            row.querySelector('.line-total').textContent = total.toFixed(2);

            updateGrandTotal();
        }
    });

    function updateGrandTotal() {
        let sum = 0;
        document.querySelectorAll('.line-total').forEach(t => {
            sum += parseFloat(t.textContent) || 0;
        });
        document.getElementById('grand-total').textContent = sum.toFixed(2);
    }

    // Remove row (but keep at least one row in table)
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-row')) {
            const rows = document.querySelectorAll('#purchase-body tr');
            const row = e.target.closest('tr');

            if (rows.length === 1) {
                // If it's the only row, just clear it
                row.querySelectorAll('input').forEach(i => i.value = '');
                let sel = row.querySelector('select[name="menu_item_id[]"]');
                if (sel) sel.selectedIndex = 0;
                row.querySelector('.line-total').textContent = '0.00';
            } else {
                row.remove();
            }
            updateGrandTotal();
        }
    });
</script>

<?php include __DIR__ . '/../layouts/footer.php'; ?>