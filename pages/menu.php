<?php
require_once __DIR__ . '/../auth_check.php';
$page_title = 'Menu Management';
require_once __DIR__ . '/../config/db.php';

$errors = [];
$success = '';

// --- Add Category ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'category') {
    $name = trim($_POST['name'] ?? '');
    $sort = (int)($_POST['sort_order'] ?? 0);

    if ($name === '') {
        $errors[] = 'Category name is required.';
    } else {
        $stmt = $pdo->prepare("INSERT INTO categories (name, sort_order) VALUES (?, ?)");
        $stmt->execute([$name, $sort]);
        $success = 'Category added.';
    }
}

// --- Delete Category ---
if (isset($_GET['delete_cat'])) {
    $id = (int)$_GET['delete_cat'];
    if ($id > 0) {
        $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->execute([$id]);
        header('Location: menu.php');
        exit;
    }
}

// --- Add Menu Item ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'item') {
    $name        = trim($_POST['item_name'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    $price       = (float)($_POST['price'] ?? 0);
    $desc        = trim($_POST['description'] ?? '');
    $active      = isset($_POST['is_active']) ? 1 : 0;
    $image       = trim($_POST['image'] ?? '');

    if ($name === '' || $category_id <= 0 || $price <= 0) {
        $errors[] = 'Item name, category and price are required.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO menu_items (category_id, name, description, price, image, is_active)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$category_id, $name, $desc, $price, $image, $active]);
        $success = 'Menu item added.';
    }
}

// --- Delete Menu Item ---
if (isset($_GET['delete_item'])) {
    $id = (int)$_GET['delete_item'];
    if ($id > 0) {
        $stmt = $pdo->prepare("DELETE FROM menu_items WHERE id = ?");
        $stmt->execute([$id]);
        header('Location: menu.php');
        exit;
    }
}

// load data
$categories = $pdo->query("SELECT * FROM categories ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);

$items = $pdo->query("
    SELECT m.*, c.name AS category_name
    FROM menu_items m
    JOIN categories c ON m.category_id = c.id
    ORDER BY c.sort_order, c.name, m.name
")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../ layouts/header.php';
?>

<?php if ($success): ?>
    <div style="margin-bottom:10px; padding:6px 10px; border-radius:8px; background:#e0ffe0; font-size:13px;">
        <?= htmlspecialchars($success) ?>
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div style="margin-bottom:10px; padding:6px 10px; border-radius:8px; background:#ffe0e0; font-size:13px;">
        <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="menu-manage-grid">
    <!-- CATEGORY SIDE -->
    <div class="card">
        <h3 style="margin-bottom:8px;">Categories</h3>

        <form method="post" style="margin-bottom:10px;">
            <input type="hidden" name="form" value="category">
            <div class="login-field">
                <label>Name</label>
                <input type="text" name="name" required>
            </div>
            <div class="login-field">
                <label>Sort Order</label>
                <input type="number" name="sort_order" value="0">
            </div>
            <button type="submit" class="btn-primary full-width">Add Category</button>
        </form>

        <table class="orders-table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Sort</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$categories): ?>
                <tr><td colspan="4" style="text-align:center;">No categories.</td></tr>
            <?php endif; ?>
            <?php foreach ($categories as $c): ?>
                <tr>
                    <td><?= $c['id'] ?></td>
                    <td><?= htmlspecialchars($c['name']) ?></td>
                    <td><?= $c['sort_order'] ?></td>
                    <td>
                        <a href="menu.php?delete_cat=<?= $c['id'] ?>"
                           onclick="return confirm('Delete this category?');"
                           class="btn-chip small">Delete</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- MENU ITEMS SIDE -->
    <div class="card">
        <h3 style="margin-bottom:8px;">Menu Items</h3>

        <form method="post" style="margin-bottom:10px;">
            <input type="hidden" name="form" value="item">

            <div class="login-field">
                <label>Name</label>
                <input type="text" name="item_name" required>
            </div>

            <div class="login-field">
                <label>Category</label>
                <select name="category_id" required>
                    <option value="">Select category</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="login-field">
                <label>Price</label>
                <input type="number" step="0.01" name="price" required>
            </div>

            <div class="login-field">
                <label>Description</label>
                <textarea name="description" rows="2" style="resize:vertical; padding:6px 8px; border-radius:8px; border:1px solid #ddd;"></textarea>
            </div>

            <div class="login-field">
                <label>Image file name (optional)</label>
                <input type="text" name="image" placeholder="example: cappuccino.jpg">
            </div>

            <div class="login-field" style="flex-direction:row; align-items:center; gap:6px;">
                <input type="checkbox" name="is_active" checked id="is_active">
                <label for="is_active">Active</label>
            </div>

            <button type="submit" class="btn-primary full-width">Add Item</button>
        </form>

        <table class="orders-table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Item</th>
                <th>Category</th>
                <th>Price</th>
                <th>Active</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$items): ?>
                <tr><td colspan="6" style="text-align:center;">No items.</td></tr>
            <?php endif; ?>
            <?php foreach ($items as $m): ?>
                <tr>
                    <td><?= $m['id'] ?></td>
                    <td><?= htmlspecialchars($m['name']) ?></td>
                    <td><?= htmlspecialchars($m['category_name']) ?></td>
                    <td>$<?= number_format($m['price'], 2) ?></td>
                    <td><?= $m['is_active'] ? 'Yes' : 'No' ?></td>
                    <td>
                        <a href="menu.php?delete_item=<?= $m['id'] ?>"
                           onclick="return confirm('Delete this item?');"
                           class="btn-chip small">Delete</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../ layouts/footer.php'; ?>
