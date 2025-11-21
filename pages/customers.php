<?php
require_once __DIR__ . '/../auth_check.php';
$page_title = 'Customers';
require_once __DIR__ . '/../config/db.php';

$errors = [];
$success = '';

// ADD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'add') {
    $name  = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($name === '' && $phone === '') {
        $errors[] = 'Provide at least a name or phone.';
    } else {
        $stmt = $pdo->prepare("INSERT INTO customers (name, phone, email) VALUES (?, ?, ?)");
        $stmt->execute([$name, $phone, $email]);
        $success = 'Customer added.';
    }
}

// DELETE
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id > 0) {
        $pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$id]);
        header('Location: customers.php');
        exit;
    }
}

// LIST
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $like = "%$q%";
    $stmt = $pdo->prepare("SELECT * FROM customers
                         WHERE name LIKE ? OR phone LIKE ? OR email LIKE ?
                         ORDER BY id DESC LIMIT 200");
    $stmt->execute([$like, $like, $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $rows = $pdo->query("SELECT * FROM customers ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
}

include __DIR__ . '/../layouts/header.php';
?>

<?php if ($success): ?>
    <div style="margin-bottom:10px; padding:6px 10px; border-radius:8px; background:#e0ffe0; font-size:13px;">
        <?= htmlspecialchars($success) ?>
    </div>
<?php endif; ?>
<?php if ($errors): ?>
    <div style="margin-bottom:10px; padding:6px 10px; border-radius:8px; background:#ffe0e0; font-size:13px;">
        <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="orders-header-row">
    <h2>Customers</h2>
    <form method="get" class="report-filter">
        <input type="text" name="q" placeholder="Search name/phone/email" value="<?= htmlspecialchars($q) ?>">
        <button class="btn-chip">Search</button>
    </form>
</div>

<div class="card" style="margin-bottom:12px;">
    <h3 style="margin-bottom:8px;">Add Customer</h3>
    <form method="post" class="menu-manage-grid" style="grid-template-columns: repeat(4,1fr); gap:10px;">
        <input type="hidden" name="form" value="add">
        <div class="login-field"><label>Name</label><input type="text" name="name"></div>
        <div class="login-field"><label>Phone</label><input type="text" name="phone"></div>
        <div class="login-field"><label>Email</label><input type="email" name="email"></div>
        <div style="align-self:end;"><button class="btn-primary">Add</button></div>
    </form>
</div>

<table class="orders-table">
    <thead>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Phone</th>
            <th>Email</th>
            <th>Loyalty</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$rows): ?>
            <tr>
                <td colspan="6" style="text-align:center;">No customers.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($rows as $c): ?>
                <tr>
                    <td><?= $c['id'] ?></td>
                    <td><?= htmlspecialchars($c['name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($c['phone'] ?? '') ?></td>
                    <td><?= htmlspecialchars($c['email'] ?? '') ?></td>
                    <td><?= (int)$c['loyalty_points'] ?></td>
                    <td>
                        <a class="btn-chip small" href="customers.php?delete=<?= $c['id'] ?>"
                            onclick="return confirm('Delete this customer?')">Delete</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<?php include __DIR__ . '/../layouts/footer.php'; ?>