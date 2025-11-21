<?php
// Auth (starts session inside)
require_once __DIR__ . '/../auth_check.php';

$page_title = 'POS';
require_once __DIR__ . '/../config/db.php';

/* ------------------------ HELPER: UNIQUE ORDER NUMBER ------------------------ */
function generate_order_number(PDO $pdo): string {
    do {
        $orderNumber = 'ORD' . date('YmdHis') . mt_rand(100, 999);
        $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE order_number = ?");
        $st->execute([$orderNumber]);
        $exists = $st->fetchColumn() > 0;
    } while ($exists);
    return $orderNumber;
}

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

// Recent orders (top cards) – ORDER BY updated_at so latest edited order comes first
$recentOrders = $pdo->query("
    SELECT o.*, t.table_name
    FROM orders o
    LEFT JOIN restaurant_tables t ON o.table_id = t.id
    ORDER BY o.updated_at DESC, o.id DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

/* ------------------------ CART & ACTIVE ORDER SESSION ------------------------ */
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Active order (when resuming)
$activeOrderId      = $_SESSION['active_order_id']      ?? 0;
$activeOrderNumber  = $_SESSION['active_order_number']  ?? '';
$activeOrderType    = $_SESSION['active_order_type']    ?? '';
$activeTableId      = $_SESSION['active_table_id']      ?? null;
$activeCustomerId   = $_SESSION['active_customer_id']   ?? null;

/* ------------------------ RESUME PENDING DINE-IN ORDER ------------------------ */
if (isset($_GET['resume'])) {
    $resumeId = (int)$_GET['resume'];

    if ($resumeId > 0) {
        $st = $pdo->prepare("
            SELECT o.*, t.table_name
            FROM orders o
            LEFT JOIN restaurant_tables t ON o.table_id = t.id
            WHERE o.id = ?
        ");
        $st->execute([$resumeId]);
        $ord = $st->fetch(PDO::FETCH_ASSOC);

        // Only allow resume for dine-in + pending
        if ($ord && $ord['order_type'] === 'dine_in' && $ord['status'] === 'pending') {
            $itSt = $pdo->prepare("
                SELECT oi.menu_item_id, oi.qty, oi.unit_price, m.name
                FROM order_items oi
                JOIN menu_items m ON m.id = oi.menu_item_id
                WHERE oi.order_id = ?
            ");
            $itSt->execute([$resumeId]);
            $rows = $itSt->fetchAll(PDO::FETCH_ASSOC);

            // Load into cart
            $_SESSION['cart'] = [];
            foreach ($rows as $r) {
                $mid = (int)$r['menu_item_id'];
                $_SESSION['cart'][$mid] = [
                    'name'  => $r['name'],
                    'price' => (float)$r['unit_price'],
                    'qty'   => (int)$r['qty'],
                ];
            }

            $_SESSION['active_order_id']      = $resumeId;
            $_SESSION['active_order_number']  = $ord['order_number'];
            $_SESSION['active_order_type']    = $ord['order_type'];
            $_SESSION['active_table_id']      = $ord['table_id'];
            $_SESSION['active_customer_id']   = $ord['customer_id'];
        }
    }

    header('Location: pos.php');
    exit;
}

/* ------------------------ CART OPS ------------------------ */

// ADD ITEM
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $id    = (int)$_POST['item_id'];
    $name  = (string)$_POST['item_name'];
    $price = (float)$_POST['item_price'];

    if (!isset($_SESSION['cart'][$id])) {
        $_SESSION['cart'][$id] = ['name' => $name, 'price' => $price, 'qty' => 0];
    }
    $_SESSION['cart'][$id]['qty']++;
    header('Location: pos.php');
    exit;
}

// REMOVE ITEM
if (isset($_GET['remove'])) {
    $removeId = (int)$_GET['remove'];
    unset($_SESSION['cart'][$removeId]);
    header('Location: pos.php');
    exit;
}

// Quantity +/-
if (isset($_GET['inc'])) {
    $id = (int)$_GET['inc'];
    if (isset($_SESSION['cart'][$id])) {
        $_SESSION['cart'][$id]['qty']++;
    }
    header('Location: pos.php');
    exit;
}
if (isset($_GET['dec'])) {
    $id = (int)$_GET['dec'];
    if (isset($_SESSION['cart'][$id])) {
        $_SESSION['cart'][$id]['qty']--;
        if ($_SESSION['cart'][$id]['qty'] <= 0) {
            unset($_SESSION['cart'][$id]);
        }
    }
    header('Location: pos.php');
    exit;
}

// Clear cart (also clear active order)
if (isset($_GET['clear_cart'])) {
    $_SESSION['cart'] = [];
    unset(
        $_SESSION['active_order_id'],
        $_SESSION['active_order_number'],
        $_SESSION['active_order_type'],
        $_SESSION['active_table_id'],
        $_SESSION['active_customer_id']
    );
    header('Location: pos.php');
    exit;
}

/* ------------------------ PLACE / UPDATE ORDER ------------------------ */
$message = '';

// keep last entered values (or defaults)
$discountInput  = isset($_POST['discount']) ? (float)$_POST['discount'] : 0;
$taxPercent     = isset($_POST['tax_percent']) ? (float)$_POST['tax_percent'] : 0;
$servicePercent = isset($_POST['service_percent']) ? (float)$_POST['service_percent'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'place_order') {
    $orderType    = $_POST['order_type'] ?? 'dine_in'; // 'dine_in' or 'take_away'
    $tableId      = !empty($_POST['table_id']) ? (int)$_POST['table_id'] : null;
    $customerId   = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
    $newCustName  = trim($_POST['new_cust_name']  ?? '');
    $newCustPhone = trim($_POST['new_cust_phone'] ?? '');
    $submitMode   = $_POST['submit_mode'] ?? 'place_only'; // place_only / place_pay

    $existingOrderId = !empty($_POST['existing_order_id']) ? (int)$_POST['existing_order_id'] : 0;
    $isUpdate        = $existingOrderId > 0;

    if (empty($_SESSION['cart'])) {
        $message = 'Cart is empty.';
    } else {
        try {
            $pdo->beginTransaction();

            // calculate totals from cart
            $subtotal = 0;
            foreach ($_SESSION['cart'] as $row) {
                $subtotal += $row['qty'] * $row['price'];
            }

            $discount = max(0, $discountInput);
            if ($discount > $subtotal) {
                $discount = $subtotal;
            }

            $taxBase = $subtotal - $discount;
            $tax     = max(0, $taxBase * $taxPercent / 100);
            $service = max(0, $taxBase * $servicePercent / 100);
            $total   = $subtotal - $discount + $tax + $service;

            // STOCK PRECHECK (simple, conservative)
            $needed = [];
            foreach ($_SESSION['cart'] as $id => $row) {
                $needed[(int)$id] = ($needed[(int)$id] ?? 0) + (int)$row['qty'];
            }
            if ($needed) {
                $ids = implode(',', array_fill(0, count($needed), '?'));
                $st = $pdo->prepare("SELECT id, name, stock_qty FROM menu_items WHERE id IN ($ids) FOR UPDATE");
                $st->execute(array_keys($needed));
                $byId = [];
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $byId[(int)$r['id']] = $r;
                }

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

            $isDineIn   = ($orderType === 'dine_in');
            $isTakeAway = ($orderType === 'take_away');

            // Determine statuses based on mode
            if ($submitMode === 'place_pay') {
                $status        = 'paid';
                $paymentStatus = 'paid';
            } else {
                // place_only
                $status        = 'pending';
                $paymentStatus = 'unpaid';
            }

            // INSERT or UPDATE order
            if ($isUpdate) {
                // Update existing order (same ID, same order_number)
                $ordSt = $pdo->prepare("SELECT * FROM orders WHERE id = ? FOR UPDATE");
                $ordSt->execute([$existingOrderId]);
                $existingOrder = $ordSt->fetch(PDO::FETCH_ASSOC);

                if (!$existingOrder) {
                    // Fallback: treat as new
                    $isUpdate     = false;
                    $existingOrderId = 0;
                    $orderNumber  = generate_order_number($pdo);
                } else {
                    $orderNumber = $existingOrder['order_number'];

                    $up = $pdo->prepare("
                        UPDATE orders
                        SET customer_id = ?, table_id = ?, order_type = ?, status = ?,
                            subtotal = ?, discount_amount = ?, tax_amount = ?, service_charge = ?,
                            total_amount = ?, payment_status = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $up->execute([
                        $customerId,
                        $tableId,
                        $orderType,
                        $status,
                        $subtotal,
                        $discount,
                        $tax,
                        $service,
                        $total,
                        $paymentStatus,
                        $existingOrderId
                    ]);

                    $orderId = $existingOrderId;
                }
            }

            // If not update (new order)
            if (!$isUpdate) {
                $orderNumber = generate_order_number($pdo);

                $stmt = $pdo->prepare("
                    INSERT INTO orders (
                        order_number, customer_id, table_id, order_type, status,
                        subtotal, discount_amount, tax_amount, service_charge,
                        total_amount, payment_status, created_by, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");

                $stmt->execute([
                    $orderNumber,
                    $customerId,
                    $tableId,
                    $orderType,
                    $status,
                    $subtotal,
                    $discount,
                    $tax,
                    $service,
                    $total,
                    $paymentStatus,
                    $_SESSION['user_id'] ?? 1
                ]);

                $orderId = (int)$pdo->lastInsertId();
            }

            // Prepare statements for items & stock
            $selExistingItems = $pdo->prepare("
                SELECT id, menu_item_id, qty, unit_price
                FROM order_items
                WHERE order_id = ?
            ");

            $itemUpdateStmt = $pdo->prepare("
                UPDATE order_items
                SET qty = ?, total_price = ?
                WHERE id = ?
            ");

            $itemInsertStmt = $pdo->prepare("
                INSERT INTO order_items (order_id, menu_item_id, qty, unit_price, total_price)
                VALUES (?, ?, ?, ?, ?)
            ");

            $updStock = $pdo->prepare("
                UPDATE menu_items
                SET stock_qty = stock_qty - ?
                WHERE id = ? AND stock_qty IS NOT NULL
            ");

            $insMove = $pdo->prepare("
                INSERT INTO inventory_moves (menu_item_id, change_qty, reason, ref_order_id, note)
                VALUES (?, ?, 'sale', ?, ?)
            ");

            // Load existing items map (only meaningful if update)
            $existingItems = [];
            if ($isUpdate) {
                $selExistingItems->execute([$orderId]);
                foreach ($selExistingItems->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $existingItems[(int)$row['menu_item_id']] = $row;
                }
            }

            // Upsert items and handle stock
            foreach ($_SESSION['cart'] as $id => $row) {
                $menuId  = (int)$id;
                $newQty  = (int)$row['qty'];
                $price   = (float)$row['price'];

                if ($isUpdate && isset($existingItems[$menuId])) {
                    // Update existing item row
                    $old      = $existingItems[$menuId];
                    $oldQty   = (int)$old['qty'];
                    $uPrice   = (float)$old['unit_price']; // keep old unit price
                    $deltaQty = $newQty - $oldQty;

                    if ($deltaQty < 0) {
                        throw new Exception("Cannot reduce quantity for {$row['name']} on existing order. Please cancel and create a new order if you need to reduce.");
                    }

                    $newTotal = $newQty * $uPrice;
                    $itemUpdateStmt->execute([$newQty, $newTotal, $old['id']]);

                    if ($deltaQty > 0) {
                        $updStock->execute([$deltaQty, $menuId]);
                        $insMove->execute([$menuId, -$deltaQty, $orderId, $orderNumber]);
                    }
                } else {
                    // Brand new item (new order OR new item on existing order)
                    $lineTotal = $newQty * $price;
                    $itemInsertStmt->execute([$orderId, $menuId, $newQty, $price, $lineTotal]);
                    $updStock->execute([$newQty, $menuId]);
                    $insMove->execute([$menuId, -$newQty, $orderId, $orderNumber]);
                }
            }

            // Handle payment for Place & Pay
            if ($submitMode === 'place_pay') {
                // Remove old payments if updating
                $pdo->prepare("DELETE FROM payments WHERE order_id = ?")->execute([$orderId]);

                $method   = 'cash';
                $cashStmt = $pdo->prepare("INSERT INTO payments (order_id, amount, method) VALUES (?, ?, ?)");
                $cashStmt->execute([$orderId, $total, $method]);
            }

            $pdo->commit();

            // Clear cart & active order
            $_SESSION['cart'] = [];
            unset(
                $_SESSION['active_order_id'],
                $_SESSION['active_order_number'],
                $_SESSION['active_order_type'],
                $_SESSION['active_table_id'],
                $_SESSION['active_customer_id']
            );

            if ($submitMode === 'place_pay') {
                $message = "Order {$orderNumber} placed & paid successfully.";
            } else {
                $message = "Order {$orderNumber} placed/updated as pending.";
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = 'Error placing order: ' . $e->getMessage();
        }
    }
}

// Re-read active order vars (may have changed)
$activeOrderId      = $_SESSION['active_order_id']      ?? 0;
$activeOrderNumber  = $_SESSION['active_order_number']  ?? '';
$activeOrderType    = $_SESSION['active_order_type']    ?? '';
$activeTableId      = $_SESSION['active_table_id']      ?? null;
$activeCustomerId   = $_SESSION['active_customer_id']   ?? null;

include __DIR__ . '/../layouts/header.php';
?>
<style>
    /* Make the text boxes in the cart summary wider & taller */
    .cart-summary .small-input {
        width: 140px;
        padding: 8px 10px;
        height: 38px;
        border-radius: 8px;
        border: 1px solid #d1d5db;
        box-sizing: border-box;
        font-size: 14px;
    }
    .order-card-clickable {
        cursor: pointer;
    }
</style>

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

                // Only allow resume for dine-in + pending
                $canResume = ($o['order_type'] === 'dine_in' && $status === 'pending');
                ?>
                <div
                        class="order-card <?= $canResume ? 'order-card-clickable' : '' ?>"
                    <?php if ($canResume): ?>
                        onclick="window.location.href='pos.php?resume=<?= (int)$o['id'] ?>'"
                    <?php endif; ?>
                >
                    <div class="order-card-row">
                        <span class="order-name">
                            <?= htmlspecialchars($o['order_number']) ?>
                        </span>
                        <span class="order-type-pill">
                            <?= strtoupper(str_replace('_',' ', $o['order_type'])) ?>
                        </span>
                    </div>
                    <div class="order-card-row small">
                        <span>
                            <?= $o['table_name'] ? 'Table ' . htmlspecialchars($o['table_name']) : 'No table' ?>
                        </span>
                        <span class="order-time"><?= htmlspecialchars($o['created_at']) ?></span>
                    </div>
                    <div class="order-card-row">
                        <span class="order-total">LKR <?= number_format((float)$o['total_amount'], 2) ?></span>
                        <span class="status-pill <?= $badgeClass ?>"><?= ucfirst($status) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
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

        <!-- MENU GRID -->
        <div class="menu-grid">
            <?php foreach ($menuItems as $item): ?>
                <?php
                // Optional low-stock badge (only if your DB has stock fields)
                $stockBadge = '';
                if (array_key_exists('stock_qty', $item) && $item['stock_qty'] !== null) {
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
                        <div class="menu-price">LKR <?= number_format((float)$item['price'], 2) ?></div>
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
        <!-- Only Dine In / Take Away buttons -->
        <div class="order-type-toggle">
            <button type="button"
                    class="pill small order-type-btn active"
                    data-type="dine_in">
                Dine In
            </button>
            <button type="button"
                    class="pill small order-type-btn"
                    data-type="take_away">
                Take Away
            </button>
        </div>

        <div class="order-cart">
            <h3>Current Order</h3>

            <?php if ($activeOrderId && $activeOrderNumber): ?>
                <div style="font-size:13px; margin-bottom:6px; color:#374151;">
                    Editing existing order:
                    <strong><?= htmlspecialchars($activeOrderNumber) ?></strong>
                </div>
            <?php endif; ?>

            <div class="table-select-row">
                <span>Table</span>
                <select form="place-order-form" name="table_id" class="order-type-select">
                    <option value="">No Table</option>
                    <?php foreach ($tables as $t): ?>
                        <option value="<?= (int)$t['id'] ?>"
                            <?= ($activeTableId && (int)$activeTableId === (int)$t['id']) ? 'selected' : '' ?>>
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
                        <?php
                        $label = trim(($c['name'] ?: 'No Name') . ($c['phone'] ? ' — ' . $c['phone'] : ''));
                        $selected = ($activeCustomerId && (int)$activeCustomerId === (int)$c['id']) ? 'selected' : '';
                        ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $selected ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
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
                            <span class="each">@ LKR <?= number_format((float)$row['price'], 2) ?></span>
                        </div>
                    </div>
                    <div class="cart-actions">
                        <span class="cart-line-total">LKR <?= number_format((float)$lineTotal, 2) ?></span>
                        <a class="btn-remove" href="pos.php?remove=<?= (int)$id ?>" title="Remove">✕</a>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php
            // figures for display
            $displayDiscount = 0;
            $displayTax      = 0;
            $displayService  = 0;
            $grandTotal      = 0;

            if ($subtotal > 0) {
                $displayDiscount = max(0, $discountInput);
                if ($displayDiscount > $subtotal) {
                    $displayDiscount = $subtotal;
                }

                $taxBase        = $subtotal - $displayDiscount;
                $displayTax     = max(0, $taxBase * $taxPercent / 100);
                $displayService = max(0, $taxBase * $servicePercent / 100);
                $grandTotal     = $subtotal - $displayDiscount + $displayTax + $displayService;
            }
            ?>

            <?php if (!empty($_SESSION['cart'])): ?>
                <div style="text-align:right; margin:6px 0;">
                    <a href="pos.php?clear_cart=1" class="btn-chip small" onclick="return confirm('Clear all items & active order?')">Clear Cart</a>
                </div>
            <?php endif; ?>

            <div class="cart-summary">
                <form method="post" id="place-order-form">
                    <input type="hidden" name="action" value="place_order">
                    <input type="hidden" name="existing_order_id" value="<?= (int)$activeOrderId ?>">

                    <div class="row">
                        <span>Order Type</span>
                        <!-- Synced with top buttons via JS -->
                        <select name="order_type" id="order-type-select" class="order-type-select">
                            <option value="dine_in" <?= $activeOrderType === 'dine_in' ? 'selected' : '' ?>>Dine In</option>
                            <option value="take_away" <?= $activeOrderType === 'take_away' ? 'selected' : '' ?>>Take Away</option>
                        </select>
                    </div>

                    <div class="row">
                        <span>Subtotal</span>
                        <span class="total-amount">LKR <?= number_format((float)$subtotal, 2) ?></span>
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
                        <span class="total-amount" id="grand-total-display">
                            LKR <?= number_format((float)$grandTotal, 2) ?>
                        </span>
                    </div>

                    <!-- Cash Given -->
                    <div class="row">
                        <span>Cash Given</span>
                        <input type="number"
                               step="0.01"
                               name="cash_given"
                               id="cash_given"
                               class="small-input"
                               placeholder="0.00">
                    </div>

                    <!-- Change -->
                    <div class="row">
                        <span>Change</span>
                        <span class="total-amount" id="change-display">LKR 0.00</span>
                    </div>

                    <div style="display:flex; gap:8px; margin-top:10px; flex-wrap:wrap;">
                        <button type="submit"
                                name="submit_mode"
                                value="place_only"
                                class="btn-chip"
                                style="flex:1; min-width:120px;">
                            Place Order
                        </button>

                        <button type="submit"
                                name="submit_mode"
                                value="place_pay"
                                class="btn-primary"
                                style="flex:1; min-width:120px;">
                            Place &amp; Pay
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>

<script>
    (function () {
        // Grand total passed from PHP
        const grandTotal = <?= json_encode((float)$grandTotal) ?>;

        const cashInput   = document.getElementById('cash_given');
        const changeLabel = document.getElementById('change-display');

        if (cashInput && changeLabel) {
            function recalcChange() {
                const cash = parseFloat(cashInput.value || '0');
                let change = cash - grandTotal;

                if (isNaN(change) || change < 0) {
                    change = 0;
                }

                changeLabel.textContent = 'LKR ' + change.toFixed(2);
            }

            cashInput.addEventListener('input', recalcChange);
        }

        // Sync order type buttons with select and table enable/disable
        const orderTypeButtons = document.querySelectorAll('.order-type-btn');
        const orderTypeSelect  = document.getElementById('order-type-select');
        const tableSelect      = document.querySelector('select[name="table_id"]');

        function setOrderType(type) {
            if (orderTypeSelect) {
                orderTypeSelect.value = type;
            }

            orderTypeButtons.forEach(btn => {
                if (btn.dataset.type === type) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });

            if (tableSelect) {
                if (type === 'take_away') {
                    tableSelect.value = '';
                    tableSelect.disabled = true;
                } else {
                    tableSelect.disabled = false;
                }
            }
        }

        orderTypeButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const type = btn.dataset.type || 'dine_in';
                setOrderType(type);
            });
        });

        // Initial state
        setOrderType(orderTypeSelect ? orderTypeSelect.value : 'dine_in');
    })();
</script>

<?php include __DIR__ . '/../layouts/footer.php'; ?>
