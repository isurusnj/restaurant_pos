<?php
// Auth (starts session inside)
require_once __DIR__ . '/../auth_check.php';

$page_title = 'POS';
require_once __DIR__ . '/../config/db.php';

/* ------------------------ LOAD DATA ------------------------ */

// Categories & Menu
$categories = $pdo->query("SELECT * FROM categories ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
$currentCategoryId = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;

if ($currentCategoryId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE is_active = 1 AND category_id = ? ORDER BY name");
    $stmt->execute([$currentCategoryId]);
} else {
    $stmt = $pdo->query("SELECT * FROM menu_items WHERE is_active = 1 ORDER BY name");
}
$menuItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Tables (for dine-in)
$tables = $pdo->query("SELECT * FROM restaurant_tables ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// Customers (dropdown)
$customers = $pdo->query("SELECT id, name, phone FROM customers ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);

// Recent orders (top cards)
$recentOrders = $pdo->query("
    SELECT o.*, t.table_name
    FROM orders o
    LEFT JOIN restaurant_tables t ON o.table_id = t.id
    ORDER BY o.id DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

/* ------------------------ CART OPS ------------------------ */
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// ADD ITEM
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $id    = (int)$_POST['item_id'];
    $name  = (string)$_POST['item_name'];
    $price = (float)$_POST['item_price'];

    if (!isset($_SESSION['cart'][$id])) {
        $_SESSION['cart'][$id] = ['name' => $name, 'price' => $price, 'qty' => 0];
    }
    $_SESSION['cart'][$id]['qty']++;
    header('Location: pos.php'); exit;
}

// REMOVE ITEM
if (isset($_GET['remove'])) {
    $removeId = (int)$_GET['remove'];
    unset($_SESSION['cart'][$removeId]);
    header('Location: pos.php'); exit;
}
// Quantity +/-
if (isset($_GET['inc'])) {
    $id = (int)$_GET['inc'];
    if (isset($_SESSION['cart'][$id])) $_SESSION['cart'][$id]['qty']++;
    header('Location: pos.php'); exit;
}
if (isset($_GET['dec'])) {
    $id = (int)$_GET['dec'];
    if (isset($_SESSION['cart'][$id])) {
        $_SESSION['cart'][$id]['qty']--;
        if ($_SESSION['cart'][$id]['qty'] <= 0) unset($_SESSION['cart'][$id]);
    }
    header('Location: pos.php'); exit;
}

// Clear cart
if (isset($_GET['clear_cart'])) {
    $_SESSION['cart'] = [];
    header('Location: pos.php'); exit;
}


/* ------------------------ PLACE ORDER ------------------------ */
$message = '';

// keep last entered values (or defaults)
$discountInput  = isset($_POST['discount']) ? (float)$_POST['discount'] : 0;
$taxPercent     = isset($_POST['tax_percent']) ? (float)$_POST['tax_percent'] : 0;
$servicePercent = isset($_POST['service_percent']) ? (float)$_POST['service_percent'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'place_order') {
    $orderType   = $_POST['order_type'] ?? 'dine_in';
    $tableId     = !empty($_POST['table_id']) ? (int)$_POST['table_id'] : null;
    $customerId  = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
    $newCustName  = trim($_POST['new_cust_name']  ?? '');
    $newCustPhone = trim($_POST['new_cust_phone'] ?? '');

    if (empty($_SESSION['cart'])) {
        $message = 'Cart is empty.';
    } else {
        try {
            $pdo->beginTransaction();

            // calculate totals
            $subtotal = 0;
            foreach ($_SESSION['cart'] as $row) {
                $subtotal += $row['qty'] * $row['price'];
            }

            $discount = max(0, $discountInput);
            if ($discount > $subtotal) $discount = $subtotal;

            $taxBase = $subtotal - $discount;
            $tax     = max(0, $taxBase * $taxPercent / 100);
            $service = max(0, $taxBase * $servicePercent / 100);
            $total   = $subtotal - $discount + $tax + $service;

            // STOCK PRECHECK (locks rows to avoid oversell)
            $needed = [];
            foreach ($_SESSION['cart'] as $id => $row) {
                $needed[(int)$id] = ($needed[$id] ?? 0) + (int)$row['qty'];
            }
            if ($needed) {
                $ids = implode(',', array_fill(0, count($needed), '?'));
                $st = $pdo->prepare("SELECT id, name, stock_qty FROM menu_items WHERE id IN ($ids) FOR UPDATE");
                $st->execute(array_keys($needed));
                $byId = [];
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $byId[(int)$r['id']] = $r; }

                foreach ($needed as $mid => $qty) {
                    $r = $byId[$mid] ?? null;
                    if ($r && $r['stock_qty'] !== null && (int)$r['stock_qty'] < $qty) {
                        throw new Exception("Insufficient stock: {$r['name']} (have {$r['stock_qty']}, need $qty)");
                    }
                }
            }

            // Quick-add customer if none selected but provided
            if (!$customerId && ($newCustName !== '' || $newCustPhone !== '')) {
                $ins = $pdo->prepare("INSERT INTO customers (name, phone) VALUES (?, ?)");
                $ins->execute([$newCustName ?: null, $newCustPhone ?: null]);
                $customerId = (int)$pdo->lastInsertId();
            }

            // simple order number
            $orderNumber = 'ORD' . time();

            // insert order (USE $customerId instead of NULL)
            $stmt = $pdo->prepare("
                INSERT INTO orders (
                    order_number, customer_id, table_id, order_type, status,
                    subtotal, discount_amount, tax_amount, service_charge,
                    total_amount, payment_status, created_by
                ) VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, 'paid', ?)
            ");
            $stmt->execute([
                $orderNumber,
                $customerId,
                $tableId,
                $orderType,
                $subtotal,
                $discount,
                $tax,
                $service,
                $total,
                $_SESSION['user_id'] ?? 1
            ]);
            $orderId = (int)$pdo->lastInsertId();

            // insert items + deduct stock + log movement
            $itemStmt = $pdo->prepare("
              INSERT INTO order_items (order_id, menu_item_id, qty, unit_price, total_price)
              VALUES (?, ?, ?, ?, ?)
            ");
            $updStock = $pdo->prepare("UPDATE menu_items SET stock_qty = stock_qty - ? WHERE id = ? AND stock_qty IS NOT NULL");
            $insMove  = $pdo->prepare("
              INSERT INTO inventory_moves (menu_item_id, change_qty, reason, ref_order_id, note)
              VALUES (?, ?, 'sale', ?, ?)
            ");

            foreach ($_SESSION['cart'] as $id => $row) {
                $qty       = (int)$row['qty'];
                $lineTotal = $qty * $row['price'];

                $itemStmt->execute([$orderId, $id, $qty, $row['price'], $lineTotal]);
                $updStock->execute([$qty, $id]); // only affects tracked items
                $insMove->execute([$id, -$qty, $orderId, $orderNumber]);
            }

            // payment record (single cash for now)
            $payStmt = $pdo->prepare("INSERT INTO payments (order_id, amount, method) VALUES (?, ?, 'cash')");
            $payStmt->execute([$orderId, $total]);

            $pdo->commit();
            $_SESSION['cart'] = [];
            $message = 'Order placed successfully: ' . $orderNumber;
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = 'Error placing order: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../ layouts/header.php';
?>

<?php if ($message): ?>
    <div style="margin-bottom:10px; padding:8px 12px; border-radius:8px; background:#e0ffe0;">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<div class="pos-layout">
    <!-- LEFT SIDE: MENU -->
    <div class="pos-main">
        <!-- Recent orders -->
        <div class="order-line">
            <?php foreach ($recentOrders as $o): ?>
                <?php
                $status = $o['status'];
                $badgeClass = 'badge-grey';
                if ($status === 'pending')     $badgeClass = 'badge-yellow';
                if ($status === 'in_progress') $badgeClass = 'badge-blue';
                if ($status === 'ready')       $badgeClass = 'badge-green';
                if ($status === 'paid')        $badgeClass = 'badge-dark';
                ?>
                <div class="order-card">
                    <div class="order-card-row">
                        <span class="order-name"><?= htmlspecialchars($o['order_number']) ?></span>
                        <span class="order-type-pill"><?= strtoupper(str_replace('_',' ', $o['order_type'])) ?></span>
                    </div>
                    <div class="order-card-row small">
                        <span><?= $o['table_name'] ? 'Table ' . htmlspecialchars($o['table_name']) : 'No table' ?></span>
                        <span class="order-time"><?= htmlspecialchars($o['created_at']) ?></span>
                    </div>
                    <div class="order-card-row">
                        <span class="order-total">$<?= number_format((float)$o['total_amount'], 2) ?></span>
                        <span class="status-pill <?= $badgeClass ?>"><?= ucfirst($status) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="menu-search" style="margin:8px 0 4px;">
            <input type="text" id="menuSearch" placeholder="Search items..."
                   style="width:100%; padding:10px 12px; border:1px solid #ddd; border-radius:10px;">
        </div>


        <!-- Category chips -->
        <div class="menu-filters">
            <a href="pos.php" class="chip <?= $currentCategoryId === 0 ? 'chip-active' : '' ?>">All</a>
            <?php foreach ($categories as $cat): ?>
                <a href="pos.php?cat=<?= $cat['id'] ?>"
                   class="chip <?= $currentCategoryId === (int)$cat['id'] ? 'chip-active' : '' ?>">
                    <?= htmlspecialchars($cat['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- MENU GRID (this is the "menu grid card" you asked about) -->
        <div class="menu-grid">
            <?php foreach ($menuItems as $item): ?>
                <?php
                // Optional: show low stock badge if stock fields exist
                $hasStock = array_key_exists('stock_qty', $item);
                $stockBadge = '';
                if ($hasStock && $item['stock_qty'] !== null) {
                    $low = ($item['stock_qty'] <= (int)($item['low_stock_threshold'] ?? 0));
                    $stockBadge = '<div class="stock-badge'.($low?' low':'').'">Stock: '.(int)$item['stock_qty'].($low?' • Low':'').'</div>';
                }
                ?>
                <div class="menu-card">
                    <div class="menu-img">
                        <?php if (!empty($item['image'])): ?>
                            <img src="/restaurant_pos/public/img/<?= htmlspecialchars($item['image']) ?>" alt="">
                        <?php else: ?>
                            <div class="img-placeholder">Img</div>
                        <?php endif; ?>
                    </div>

                    <div class="menu-info">
                        <div class="menu-title"><?= htmlspecialchars($item['name']) ?></div>
                        <div class="menu-price">$<?= number_format((float)$item['price'], 2) ?></div>
                        <?= $stockBadge ?>
                    </div>

                    <form method="post">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                        <input type="hidden" name="item_name" value="<?= htmlspecialchars($item['name']) ?>">
                        <input type="hidden" name="item_price" value="<?= (float)$item['price'] ?>">
                        <button type="submit" class="btn-add">+</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- RIGHT SIDE: CART -->
    <div class="pos-sidebar">
        <div class="order-type-toggle">
            <button class="pill small active">Dine In</button>
            <button class="pill small">Take Away</button>
            <button class="pill small">Delivery</button>
        </div>

        <div class="order-cart">
            <h3>Current Order</h3>

            <div class="table-select-row">
                <span>Table</span>
                <select form="place-order-form" name="table_id" class="order-type-select">
                    <option value="">No Table</option>
                    <?php foreach ($tables as $t): ?>
                        <option value="<?= (int)$t['id'] ?>">
                            <?= htmlspecialchars($t['table_name']) ?> (<?= (int)$t['capacity'] ?> pax)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="table-select-row" style="margin-top:6px;">
                <span>Customer</span>
                <select form="place-order-form" name="customer_id" class="order-type-select" style="max-width: 200px;">
                    <option value="">Walk-in</option>
                    <?php foreach ($customers as $c): ?>
                        <?php $label = trim(($c['name'] ?: 'No Name') . ($c['phone'] ? ' — ' . $c['phone'] : '')); ?>
                        <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="table-select-row" style="gap:6px;">
                <span>Add</span>
                <input form="place-order-form" type="text" name="new_cust_name"  placeholder="Name"  class="small-input" style="width:120px;">
                <input form="place-order-form" type="text" name="new_cust_phone" placeholder="Phone" class="small-input" style="width:120px;">
            </div>

            <?php
            $subtotal = 0;
            foreach ($_SESSION['cart'] as $id => $row):
                $lineTotal = $row['qty'] * $row['price'];
                $subtotal += $lineTotal;
                ?>
                <div class="cart-item">
                    <div>
                        <div class="cart-name"><?= htmlspecialchars($row['name']) ?></div>
                        <div class="cart-qty">
                            <a class="qty-btn" href="pos.php?dec=<?= (int)$id ?>">−</a>
                            <span class="qty-val"><?= (int)$row['qty'] ?></span>
                            <a class="qty-btn" href="pos.php?inc=<?= (int)$id ?>">+</a>
                            <span class="each">@ $<?= number_format((float)$row['price'], 2) ?></span>
                        </div>
                    </div>
                    <div class="cart-actions">
                        <span class="cart-line-total">$<?= number_format((float)$lineTotal, 2) ?></span>
                        <a class="btn-remove" href="pos.php?remove=<?= (int)$id ?>" title="Remove">✕</a>
                    </div>
                </div>
            <?php endforeach; ?>


            // figures for display
            $displayDiscount = 0;
            $displayTax      = 0;
            $displayService  = 0;
            $grandTotal      = 0;

            if ($subtotal > 0) {
                $displayDiscount = max(0, $discountInput);
                if ($displayDiscount > $subtotal) $displayDiscount = $subtotal;

                $taxBase        = $subtotal - $displayDiscount;
                $displayTax     = max(0, $taxBase * $taxPercent / 100);
                $displayService = max(0, $taxBase * $servicePercent / 100);
                $grandTotal     = $subtotal - $displayDiscount + $displayTax + $displayService;
            }
            ?>

            <?php if (!empty($_SESSION['cart'])): ?>
                <div style="text-align:right; margin:6px 0;">
                    <a href="pos.php?clear_cart=1" class="btn-chip small" onclick="return confirm('Clear all items?')">Clear Cart</a>
                </div>
            <?php endif; ?>


            <div class="cart-summary">
                <form method="post" id="place-order-form">
                    <input type="hidden" name="action" value="place_order">

                    <div class="row">
                        <span>Order Type</span>
                        <select name="order_type" class="order-type-select">
                            <option value="dine_in">Dine In</option>
                            <option value="take_away">Take Away</option>
                            <option value="delivery">Delivery</option>
                        </select>
                    </div>

                    <div class="row">
                        <span>Subtotal</span>
                        <span class="total-amount">$<?= number_format((float)$subtotal, 2) ?></span>
                    </div>

                    <div class="row">
                        <span>Discount</span>
                        <input type="number" step="0.01" name="discount"
                               value="<?= htmlspecialchars($discountInput) ?>"
                               class="small-input">
                    </div>

                    <div class="row">
                        <span>Tax %</span>
                        <input type="number" step="0.01" name="tax_percent"
                               value="<?= htmlspecialchars($taxPercent) ?>"
                               class="small-input">
                    </div>

                    <div class="row">
                        <span>Service %</span>
                        <input type="number" step="0.01" name="service_percent"
                               value="<?= htmlspecialchars($servicePercent) ?>"
                               class="small-input">
                    </div>

                    <div class="row">
                        <span>Grand Total</span>
                        <span class="total-amount">$<?= number_format((float)$grandTotal, 2) ?></span>
                    </div>

                    <button type="submit" class="btn-primary full-width">Place Order</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../ layouts/footer.php'; ?>
