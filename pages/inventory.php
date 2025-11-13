<?php
require_once __DIR__ . '/../auth_check.php';
$page_title = 'Inventory';
require_once __DIR__ . '/../config/db.php';

$errors = [];
$success = '';

// Restock / Adjust
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'move') {
    $itemId = (int)($_POST['menu_item_id'] ?? 0);
    $qty    = (int)($_POST['qty'] ?? 0);          // +ve restock, -ve adjust down
    $note   = trim($_POST['note'] ?? '');

    if ($itemId <= 0 || $qty === 0) {
        $errors[] = 'Choose an item and non-zero quantity.';
    } else {
        try {
            $pdo->beginTransaction();

            // Update stock if tracked
            $upd = $pdo->prepare("UPDATE menu_items SET stock_qty = IFNULL(stock_qty,0) + ? WHERE id = ?");
            $upd->execute([$qty, $itemId]);

            // Insert move
            $reason = $qty > 0 ? 'restock' : 'adjust';
            $ins = $pdo->prepare("
        INSERT INTO inventory_moves (menu_item_id, change_qty, reason, ref_order_id, note)
        VALUES (?, ?, ?, NULL, ?)
      ");
            $ins->execute([$itemId, $qty, $reason, $note]);

            $pdo->commit();
            $success = 'Inventory updated.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Error: ' . $e->getMessage();
        }
    }
}

// Low stock list
$low = $pdo->query("
  SELECT id, name, sku, stock_qty, low_stock_threshold
  FROM menu_items
  WHERE stock_qty IS NOT NULL
    AND stock_qty <= low_stock_threshold
  ORDER BY stock_qty ASC, name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Items for dropdown
$items = $pdo->query("
  SELECT id, name, sku, stock_qty FROM menu_items
  ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../ layouts/header.php';
?>

<?php if ($success): ?>
    <div style="margin-bottom:10px; padding:6px 10px; border-radius:8px; background:#e0ffe0; font-size:13px;"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
    <div style="margin-bottom:10px; padding:6px 10px; border-radius:8px; background:#ffe0e0; font-size:13px;">
        <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="orders-header-row">
    <h2>Inventory</h2>
</div>

<div class="card" style="margin-bottom:12px;">
    <h3 style="margin-bottom:8px;">Restock / Adjust</h3>
    <form method="post" class="menu-manage-grid" style="grid-template-columns: 2fr 1fr 3fr 1fr; gap:10px;">
        <input type="hidden" name="form" value="move">
        <div class="login-field">
            <label>Item</label>
            <select name="menu_item_id" required>
                <option value="">Select item</option>
                <?php foreach ($items as $mi): ?>
                    <?php
                    $label = $mi['name'] . (isset($mi['sku']) && $mi['sku'] ? " ({$mi['sku']})" : '');
                    $label .= ' — Stock: ' . (is_null($mi['stock_qty']) ? 'Not tracked' : (int)$mi['stock_qty']);
                    ?>
                    <option value="<?= $mi['id'] ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="login-field">
            <label>Qty (+add / −deduct)</label>
            <input type="number" name="qty" required>
        </div>
        <div class="login-field">
            <label>Note (optional)</label>
            <input type="text" name="note" placeholder="invoice #, reason, etc.">
        </div>
        <div style="align-self:end;"><button class="btn-primary">Save</button></div>
    </form>
</div>

<div class="card">
    <h3 style="margin-bottom:8px;">Low Stock</h3>
    <table class="orders-table">
        <thead><tr><th>Item</th><th>SKU</th><th>Stock</th><th>Threshold</th></tr></thead>
        <tbody>
        <?php if (!$low): ?>
            <tr><td colspan="4" style="text-align:center;">No low-stock items 🎉</td></tr>
        <?php else: ?>
            <?php foreach ($low as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['name']) ?></td>
                    <td><?= htmlspecialchars($r['sku'] ?? '') ?></td>
                    <td><?= is_null($r['stock_qty']) ? '-' : (int)$r['stock_qty'] ?></td>
                    <td><?= (int)$r['low_stock_threshold'] ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/../ layouts/footer.php'; ?>
