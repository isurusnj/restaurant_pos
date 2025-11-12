<?php

session_start();

require_once __DIR__ . '/../auth_check.php';
$page_title = 'Dashboard';
require_once __DIR__ . '/../config/db.php';


$page_title = 'POS';

require_once __DIR__ . '/../config/db.php';

// ---------- LOAD CATEGORIES & MENU ----------
$categories = $pdo->query("SELECT * FROM categories ORDER BY sort_order, name")
    ->fetchAll(PDO::FETCH_ASSOC);

$currentCategoryId = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;

if ($currentCategoryId > 0) {
    $stmt = $pdo->prepare(
        "SELECT * FROM menu_items WHERE is_active = 1 AND category_id = ? ORDER BY name"
    );
    $stmt->execute([$currentCategoryId]);
} else {
    $stmt = $pdo->query("SELECT * FROM menu_items WHERE is_active = 1 ORDER BY name");
}
$menuItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------- LOAD TABLES ----------
$tables = $pdo->query("SELECT * FROM restaurant_tables ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// ---------- LOAD CUSTOMERS (for select) ----------
$customers = $pdo->query("SELECT id, name, phone FROM customers ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);


// ---------- RECENT ORDERS FOR TOP CARDS ----------
$recentOrders = $pdo->query("
    SELECT o.*, t.table_name
    FROM orders o
    LEFT JOIN restaurant_tables t ON o.table_id = t.id
    ORDER BY o.id DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);


// ---------- INIT CART ----------
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// ADD ITEM
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $id    = (int)$_POST['item_id'];
    $name  = $_POST['item_name'];
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

// ---------- PLACE ORDER ----------
// ---------- PLACE ORDER ----------
$message = '';

// keep last entered values (or defaults)
$discountInput    = isset($_POST['discount']) ? (float)$_POST['discount'] : 0;
$taxPercent       = isset($_POST['tax_percent']) ? (float)$_POST['tax_percent'] : 0;
$servicePercent   = isset($_POST['service_percent']) ? (float)$_POST['service_percent'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'place_order') {
    $orderType = $_POST['order_type'] ?? 'dine_in';
    $tableId   = !empty($_POST['table_id']) ? (int)$_POST['table_id'] : null;
    $customerId      = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
    $newCustName     = trim($_POST['new_cust_name'] ?? '');
    $newCustPhone    = trim($_POST['new_cust_phone'] ?? '');


    if (empty($_SESSION['cart'])) {
        $message = 'Cart is empty.';
    } else {
        try {
            $pdo->beginTransaction();

            // calculate subtotal from cart
            $subtotal = 0;
            foreach ($_SESSION['cart'] as $row) {
                $subtotal += $row['qty'] * $row['price'];
            }

            // sanitize inputs
            $discount = max(0, $discountInput);
            if ($discount > $subtotal) {
                $discount = $subtotal;
            }

            $taxBase      = $subtotal - $discount;
            $tax          = max(0, $taxBase * $taxPercent / 100);
            $service      = max(0, $taxBase * $servicePercent / 100);
            $total        = $subtotal - $discount + $tax + $service;


            // simple order number
            $orderNumber = 'ORD' . time();

            // insert order
            $stmt = $pdo->prepare("
                INSERT INTO orders (order_number, customer_id, table_id, order_type, status, subtotal,
                                    discount_amount, tax_amount, service_charge, total_amount,
                                    payment_status, created_by)
                VALUES (?, NULL, ?, ?, 'pending', ?, ?, ?, ?, ?, 'paid', 1)
");
            $stmt->execute([
                $orderNumber,
                $tableId,
                $orderType,
                $subtotal,
                $discount,
                $tax,
                $service,
                $total
            ]);


            $orderId = $pdo->lastInsertId();

            // insert items
            $itemStmt = $pdo->prepare("
                INSERT INTO order_items (order_id, menu_item_id, qty, unit_price, total_price)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($_SESSION['cart'] as $id => $row) {
                $lineTotal = $row['qty'] * $row['price'];
                $itemStmt->execute([$orderId, $id, $row['qty'], $row['price'], $lineTotal]);
            }

            // payment record
            $payStmt = $pdo->prepare("
                INSERT INTO payments (order_id, amount, method)
                VALUES (?, ?, 'cash')
            ");
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
          <span>
            <?= $o['table_name'] ? 'Table ' . htmlspecialchars($o['table_name']) : 'No table' ?>
          </span>
                        <span class="order-time"><?= $o['created_at'] ?></span>
                    </div>
                    <div class="order-card-row">
                        <span class="order-total">$<?= number_format($o['total_amount'], 2) ?></span>
                        <span class="status-pill <?= $badgeClass ?>"><?= ucfirst($status) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>


        <div class="menu-filters">
            <a href="pos.php" class="chip <?= $currentCategoryId === 0 ? 'chip-active' : '' ?>">All</a>
            <?php foreach ($categories as $cat): ?>
                <a href="pos.php?cat=<?= $cat['id'] ?>"
                   class="chip <?= $currentCategoryId === (int)$cat['id'] ? 'chip-active' : '' ?>">
                    <?= htmlspecialchars($cat['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="menu-grid">
            <?php foreach ($menuItems as $item): ?>
                <div class="menu-card">
                    <div class="menu-img">
                        <?php if ($item['image']): ?>
                            <img src="/restaurant_pos/public/img/<?= htmlspecialchars($item['image']) ?>" alt="">
                        <?php else: ?>
                            <div class="img-placeholder">Img</div>
                        <?php endif; ?>
                    </div>
                    <div class="menu-info">
                        <div class="menu-title"><?= htmlspecialchars($item['name']) ?></div>
                        <div class="menu-price">$<?= number_format($item['price'], 2) ?></div>
                    </div>
                    <form method="post">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                        <input type="hidden" name="item_name" value="<?= htmlspecialchars($item['name']) ?>">
                        <input type="hidden" name="item_price" value="<?= $item['price'] ?>">
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
                        <option value="<?= $t['id'] ?>">
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
                        ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($label) ?></option>
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
                    ...
                </div>
            <?php endforeach;

            // calculate figures for display
            $displayDiscount = 0;
            $displayTax      = 0;
            $displayService  = 0;
            $grandTotal      = 0;

            if ($subtotal > 0) {
                $displayDiscount = max(0, $discountInput);
                if ($displayDiscount > $subtotal) {
                    $displayDiscount = $subtotal;
                }
                $taxBase         = $subtotal - $displayDiscount;
                $displayTax      = max(0, $taxBase * $taxPercent / 100);
                $displayService  = max(0, $taxBase * $servicePercent / 100);
                $grandTotal      = $subtotal - $displayDiscount + $displayTax + $displayService;
            }
            ?>

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
                        <span class="total-amount">$<?= number_format($subtotal, 2) ?></span>
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
                        <span class="total-amount">$<?= number_format($grandTotal, 2) ?></span>
                    </div>


                    <button type="submit" class="btn-primary full-width">Place Order</button>

                </form>
            </div>


        </div>
    </div>
</div>

<?php include __DIR__ . '/../ layouts/footer.php'; ?>
