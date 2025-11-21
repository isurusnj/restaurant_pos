<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../roles.php';
authorize(['admin']); // Only admin can access settings

$page_title = 'Settings';
require_once __DIR__ . '/../config/db.php';

$success = '';
$errors  = [];
$roleName = 'cashier'; // default to avoid undefined warnings

/* ---- Introspect users schema ---- */
try {
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $colStmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users'
    ");
    $colStmt->execute([$dbName]);
    $cols = array_map('strtolower', array_column($colStmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME'));
} catch (Throwable $e) {
    $cols = [];
}

$hasEmail     = in_array('email', $cols, true);
$hasUsername  = in_array('username', $cols, true);
$hasPwdHash   = in_array('password_hash', $cols, true);
$hasPwd       = in_array('password', $cols, true);
$pwdColumn    = $hasPwdHash ? 'password_hash' : ($hasPwd ? 'password' : null);
$hasRoleText  = in_array('role', $cols, true);
$hasRoleId    = in_array('role_id', $cols, true);
$hasStatus    = in_array('status', $cols, true);

/* ---- roles table presence ---- */
$hasRolesTable = (bool)$pdo->query("
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roles'
")->fetchColumn();

$defaultRoleNames = ['admin', 'manager', 'cashier', 'kitchen'];

if ($hasRoleId && $hasRolesTable) {
    // ensure default roles exist
    foreach ($defaultRoleNames as $rn) {
        $s = $pdo->prepare("INSERT IGNORE INTO roles (name) VALUES (?)");
        $s->execute([$rn]);
    }
    $roles = $pdo->query("SELECT id,name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} else {
    // fallback to simple text roles
    $roles = array_map(fn($n) => ['id' => $n, 'name' => $n], $defaultRoleNames);
}

/* -------------------------------------------------------------
 * Update role for existing user
 * ----------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'update_role') {
    $uid      = (int)($_POST['user_id'] ?? 0);
    $roleName = trim($_POST['role'] ?? 'cashier');

    if ($uid <= 0) {
        $errors[] = 'Please select a user.';
    } elseif ($roleName === '') {
        $errors[] = 'Invalid role.';
    } else {
        try {
            if ($hasRoleId && $hasRolesTable) {
                $st = $pdo->prepare("SELECT id FROM roles WHERE name=? LIMIT 1");
                $st->execute([$roleName]);
                $rid = $st->fetchColumn();
                if (!$rid) {
                    $pdo->prepare("INSERT INTO roles (name) VALUES (?)")->execute([$roleName]);
                    $rid = (int)$pdo->lastInsertId();
                }
                $up = $pdo->prepare("UPDATE users SET role_id=? WHERE id=?");
                $up->execute([$rid, $uid]);
            } elseif ($hasRoleText) {
                $up = $pdo->prepare("UPDATE users SET role=? WHERE id=?");
                $up->execute([$roleName, $uid]);
            } else {
                throw new Exception('No role field in users table.');
            }
            $success = 'Role updated.';
        } catch (Throwable $e) {
            $errors[] = 'Failed to update role: ' . $e->getMessage();
        }
    }
}

/* -------------------------------------------------------------
 * Create user
 * ----------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'create_user') {

    $name      = trim($_POST['name'] ?? '');
    $username  = $hasUsername ? trim($_POST['username'] ?? '') : '';
    $email     = $hasEmail ? trim($_POST['email'] ?? '') : '';
    $password  = (string)($_POST['password'] ?? '');
    $roleName  = trim($_POST['role'] ?? 'cashier');

    // Validation
    if ($name === '')          $errors[] = 'Name is required.';
    if ($hasUsername && $username === '') $errors[] = 'Username is required.';
    if ($password === '')      $errors[] = 'Password is required.';
    if ($hasEmail) {
        if ($email === '') {
            $errors[] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email.';
        }
    }
    if ($pwdColumn === null) {
        $errors[] = 'No password/password_hash column in users table.';
    }

    // Unique username check (if column exists)
    if ($hasUsername && $username !== '') {
        $check = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $check->execute([$username]);
        if ($check->fetch()) {
            $errors[] = 'Username already exists.';
        }
    }

    // Determine normalised role name
    $roleNameLower = strtolower($roleName);
    if (!in_array($roleNameLower, ['admin', 'manager', 'cashier', 'kitchen'], true)) {
        $roleNameLower = 'cashier';
    }

    // Resolve role_id or role text
    $roleField = null;
    $roleValue = null;
    if (!$errors) {
        try {
            if ($hasRoleId && $hasRolesTable) {
                $st = $pdo->prepare("SELECT id FROM roles WHERE name=? LIMIT 1");
                $st->execute([$roleNameLower]);
                $rid = $st->fetchColumn();
                if (!$rid) {
                    $pdo->prepare("INSERT INTO roles (name) VALUES (?)")->execute([$roleNameLower]);
                    $rid = (int)$pdo->lastInsertId();
                }
                $roleField = 'role_id';
                $roleValue = (int)$rid;
            } elseif ($hasRoleText) {
                $roleField = 'role';
                $roleValue = $roleNameLower;
            }
        } catch (Throwable $e) {
            $errors[] = 'Failed to resolve role: ' . $e->getMessage();
        }
    }

    // Insert user
    if (!$errors) {
        // Hash password depending on available column
        if ($pwdColumn === 'password_hash') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
        } else {
            // Legacy: plain `password` column, use md5
            $hash = md5($password);
        }

        $fields = ['name', $pwdColumn];
        $values = [$name, $hash];

        if ($roleField !== null && $roleValue !== null) {
            $fields[] = $roleField;
            $values[] = $roleValue;
        }

        if ($hasEmail) {
            $fields[] = 'email';
            $values[] = $email;
        }

        if ($hasUsername) {
            $fields[] = 'username';
            $values[] = $username;
        }

        if ($hasStatus) {
            $fields[] = 'status';
            $values[] = 1; // active
        }

        $ph  = implode(',', array_fill(0, count($fields), '?'));
        $sql = "INSERT INTO users (" . implode(',', $fields) . ") VALUES ($ph)";

        try {
            $ins = $pdo->prepare($sql);
            $ins->execute($values);
            $success = 'User created.';
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                if ($hasUsername && strpos($e->getMessage(), 'username') !== false)      $errors[] = 'Username already exists.';
                elseif ($hasEmail && strpos($e->getMessage(), 'email') !== false)        $errors[] = 'Email already exists.';
                else                                                                     $errors[] = 'Duplicate value.';
            } else {
                $errors[] = 'Failed to create user: ' . $e->getMessage();
            }
        }
    }
}

/* -------------------------------------------------------------
 * Receipt / KOT template builder
 * ----------------------------------------------------------- */
try {
    // Create table on the fly if it does not exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS receipt_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description VARCHAR(255) NULL,
            company_name VARCHAR(150) NOT NULL,
            address TEXT NULL,
            phone VARCHAR(50) NULL,
            logo_url VARCHAR(255) NULL,
            paper_width_mm INT NOT NULL DEFAULT 80,
            margins_mm INT NOT NULL DEFAULT 3,
            show_item_images TINYINT(1) NOT NULL DEFAULT 1,
            show_tax TINYINT(1) NOT NULL DEFAULT 1,
            show_service TINYINT(1) NOT NULL DEFAULT 1,
            show_table_no TINYINT(1) NOT NULL DEFAULT 1,
            show_datetime TINYINT(1) NOT NULL DEFAULT 1,
            header_lines TEXT NULL,
            footer_lines TEXT NULL,
            kot_title VARCHAR(150) DEFAULT 'KITCHEN ORDER TICKET',
            kot_show_table TINYINT(1) NOT NULL DEFAULT 1,
            kot_show_time TINYINT(1) NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Throwable $e) {
    // Do not break settings page if table creation fails
    $errors[] = 'Warning: could not ensure receipt_templates table exists: ' . $e->getMessage();
}

// Handle POST for receipt templates
$currentTemplateId = 0;
$receiptTemplate   = [
    'id'              => 0,
    'name'            => '',
    'description'     => '',
    'company_name'    => '',
    'address'         => '',
    'phone'           => '',
    'logo_url'        => '',
    'paper_width_mm'  => 80,
    'margins_mm'      => 3,
    'show_item_images' => 1,
    'show_tax'        => 1,
    'show_service'    => 1,
    'show_table_no'   => 1,
    'show_datetime'   => 1,
    'header_lines'    => "Customer Bill",
    'footer_lines'    => "Thank you!\nCome again",
    'kot_title'       => 'KITCHEN ORDER TICKET',
    'kot_show_table'  => 1,
    'kot_show_time'   => 1,
    'is_active'       => 0,
];

// Load templates list
$allTemplates = [];
try {
    $st = $pdo->query("SELECT * FROM receipt_templates ORDER BY is_active DESC, name ASC");
    $allTemplates = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'Failed to load receipt templates: ' . $e->getMessage();
}

// Process form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'receipt_template') {
    $action = $_POST['action'] ?? '';
    $currentTemplateId = (int)($_POST['template_id'] ?? 0);

    // For save/save_new we read all fields
    if ($action === 'save' || $action === 'save_new') {
        $name        = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $company     = trim($_POST['company_name'] ?? '');
        $address     = trim($_POST['address'] ?? '');
        $phone       = trim($_POST['phone'] ?? '');
        $logo        = trim($_POST['logo_url'] ?? '');
        $width       = (int)($_POST['paper_width_mm'] ?? 80);
        $margins     = (int)($_POST['margins_mm'] ?? 3);
        $showImg     = isset($_POST['show_item_images']) ? 1 : 0;
        $showTax     = isset($_POST['show_tax']) ? 1 : 0;
        $showService = isset($_POST['show_service']) ? 1 : 0;
        $showTable   = isset($_POST['show_table_no']) ? 1 : 0;
        $showDT      = isset($_POST['show_datetime']) ? 1 : 0;
        $headerLines = (string)($_POST['header_lines'] ?? '');
        $footerLines = (string)($_POST['footer_lines'] ?? '');
        $kotTitle    = trim($_POST['kot_title'] ?? 'KITCHEN ORDER TICKET');
        $kotShowTbl  = isset($_POST['kot_show_table']) ? 1 : 0;
        $kotShowTime = isset($_POST['kot_show_time']) ? 1 : 0;

        if ($name === '')     $errors[] = 'Receipt template: name is required.';
        if ($company === '')  $errors[] = 'Receipt template: company name is required.';
        if ($width < 50 || $width > 90)      $errors[] = 'Paper width should be between 50 and 90 mm.';
        if ($margins < 0 || $margins > 20)   $errors[] = 'Margins should be between 0 and 20 mm.';

        if (!$errors) {
            try {
                if ($action === 'save' && $currentTemplateId > 0) {
                    // Update existing
                    $sql = "UPDATE receipt_templates
                            SET name=?, description=?, company_name=?, address=?, phone=?, logo_url=?,
                                paper_width_mm=?, margins_mm=?, show_item_images=?, show_tax=?, show_service=?,
                                show_table_no=?, show_datetime=?, header_lines=?, footer_lines=?,
                                kot_title=?, kot_show_table=?, kot_show_time=?
                            WHERE id=?";
                    $st = $pdo->prepare($sql);
                    $st->execute([
                        $name,
                        $description,
                        $company,
                        $address,
                        $phone,
                        $logo,
                        $width,
                        $margins,
                        $showImg,
                        $showTax,
                        $showService,
                        $showTable,
                        $showDT,
                        $headerLines,
                        $footerLines,
                        $kotTitle,
                        $kotShowTbl,
                        $kotShowTime,
                        $currentTemplateId
                    ]);
                    $success = 'Receipt template updated.';
                } else {
                    // Insert new
                    $sql = "INSERT INTO receipt_templates
                            (name, description, company_name, address, phone, logo_url,
                             paper_width_mm, margins_mm, show_item_images, show_tax, show_service,
                             show_table_no, show_datetime, header_lines, footer_lines,
                             kot_title, kot_show_table, kot_show_time, is_active)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)";
                    $st = $pdo->prepare($sql);
                    $st->execute([
                        $name,
                        $description,
                        $company,
                        $address,
                        $phone,
                        $logo,
                        $width,
                        $margins,
                        $showImg,
                        $showTax,
                        $showService,
                        $showTable,
                        $showDT,
                        $headerLines,
                        $footerLines,
                        $kotTitle,
                        $kotShowTbl,
                        $kotShowTime
                    ]);
                    $currentTemplateId = (int)$pdo->lastInsertId();
                    $success = 'New receipt template created.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Failed to save receipt template: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'activate' && $currentTemplateId > 0) {
        // Mark as active
        try {
            $pdo->exec("UPDATE receipt_templates SET is_active = 0");
            $st = $pdo->prepare("UPDATE receipt_templates SET is_active = 1 WHERE id = ?");
            $st->execute([$currentTemplateId]);
            $success = 'Receipt template set as active.';
        } catch (Throwable $e) {
            $errors[] = 'Failed to set active template: ' . $e->getMessage();
        }
    } elseif ($action === 'select') {
        // just change currentTemplateId
    }
}

// Reload templates list after possible changes
try {
    $st = $pdo->query("SELECT * FROM receipt_templates ORDER BY is_active DESC, name ASC");
    $allTemplates = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'Failed to reload receipt templates: ' . $e->getMessage();
}

// Determine which template to show in the form
if ($currentTemplateId > 0 && $allTemplates) {
    foreach ($allTemplates as $tpl) {
        if ((int)$tpl['id'] === $currentTemplateId) {
            $receiptTemplate = $tpl;
            break;
        }
    }
} else {
    foreach ($allTemplates as $tpl) {
        if (!empty($tpl['is_active'])) {
            $receiptTemplate = $tpl;
            $currentTemplateId = (int)$tpl['id'];
            break;
        }
    }
}

/* -------------------------------------------------------------
 * Load users list for Roles & Permissions
 * ----------------------------------------------------------- */
if ($hasRoleId && $hasRolesTable) {
    $select = ['u.id', 'u.name', 'r.name AS role_name'];
    if ($hasEmail)    $select[] = 'u.email';
    if ($hasUsername) $select[] = 'u.username';
    $sql = "SELECT " . implode(',', $select) . "
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            ORDER BY u.name";
    $users = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} else {
    $select = ['id', 'name'];
    if ($hasRoleText) $select[] = 'role';
    if ($hasEmail)    $select[] = 'email';
    if ($hasUsername) $select[] = 'username';
    $sql = "SELECT " . implode(',', $select) . " FROM users ORDER BY name";
    $users = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

include __DIR__ . '/../layouts/header.php';
?>

<?php if ($success): ?>
    <div style="margin-bottom:10px; padding:8px 12px; border-radius:8px; background:#e0ffe0;">
        <?= htmlspecialchars($success) ?>
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div style="margin-bottom:10px; padding:8px 12px; border-radius:8px; background:#ffe0e0;">
        <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="menu-manage-grid" style="align-items:start;">
    <div class="card">
        <h3 style="margin-bottom:8px;">Roles &amp; Permissions</h3>
        <form method="post" class="menu-manage-grid" style="grid-template-columns:2fr 1fr 1fr; gap:10px;">
            <input type="hidden" name="form" value="update_role">
            <div class="login-field">
                <label>User</label>
                <select name="user_id" required>
                    <option value="">Select user</option>
                    <?php foreach ($users as $u): ?>
                        <?php
                        $label = ($u['name'] ?: 'No Name');
                        if ($hasUsername) {
                            $label .= ' — ' . ($u['username'] ?? '');
                        }
                        if ($hasEmail) {
                            $label .= ' — ' . ($u['email'] ?? '');
                        }
                        $label .= ' — ' . ($u['role_name'] ?? ($u['role'] ?? ''));
                        ?>
                        <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="login-field">
                <label>Role</label>
                <select name="role" required>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= htmlspecialchars($r['name']) ?>"><?= htmlspecialchars($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="align-self:end;">
                <button class="btn-primary">Save</button>
            </div>
        </form>

        <div style="margin-top:12px;">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <?php if ($hasUsername): ?><th>Username</th><?php endif; ?>
                        <?php if ($hasEmail): ?><th>Email</th><?php endif; ?>
                        <th>Role</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$users): ?>
                        <tr>
                            <td colspan="<?= 3 + ($hasUsername ? 1 : 0) + ($hasEmail ? 1 : 0) ?>" style="text-align:center;">No users.</td>
                        </tr>
                        <?php else: foreach ($users as $u): ?>
                            <tr>
                                <td><?= (int)$u['id'] ?></td>
                                <td><?= htmlspecialchars($u['name'] ?: '-') ?></td>
                                <?php if ($hasUsername): ?><td><?= htmlspecialchars($u['username'] ?? '') ?></td><?php endif; ?>
                                <?php if ($hasEmail): ?><td><?= htmlspecialchars($u['email'] ?? '') ?></td><?php endif; ?>
                                <td><?= htmlspecialchars($u['role_name'] ?? ($u['role'] ?? '')) ?></td>
                            </tr>
                    <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3 style="margin-bottom:8px;">Create User</h3>
        <form method="post">
            <input type="hidden" name="form" value="create_user">

            <div class="login-field">
                <label>Name</label>
                <input type="text" name="name" required>
            </div>

            <?php if ($hasUsername): ?>
                <div class="login-field">
                    <label>Username (unique)</label>
                    <input type="text" name="username" required>
                </div>
            <?php endif; ?>

            <?php if ($hasEmail): ?>
                <div class="login-field">
                    <label>Email</label>
                    <input type="email" name="email" required>
                </div>
            <?php endif; ?>

            <div class="login-field">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>

            <div class="login-field">
                <label>Role</label>
                <select name="role" required>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= htmlspecialchars($r['name']) ?>"><?= htmlspecialchars($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button class="btn-primary full-width">Create</button>
        </form>
    </div>
</div>

<div class="card">
    <h3>Receipt &amp; KOT Template</h3>

    <!-- Template picker -->
    <form method="post" class="login-form" style="margin-bottom:12px;">
        <input type="hidden" name="form" value="receipt_template">
        <input type="hidden" name="action" value="select">
        <div class="login-field">
            <label>Choose existing template</label>
            <select name="template_id" onchange="this.form.submit()">
                <option value="0">– New / Blank –</option>
                <?php foreach ($allTemplates as $tpl): ?>
                    <option value="<?= (int)$tpl['id'] ?>"
                        <?= (isset($receiptTemplate['id']) && (int)$receiptTemplate['id'] === (int)$tpl['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($tpl['name']) ?>
                        <?= !empty($tpl['is_active']) ? ' (active)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <!-- Template edit form -->
    <form method="post" class="login-form">
        <input type="hidden" name="form" value="receipt_template">
        <input type="hidden" name="template_id" value="<?= (int)$receiptTemplate['id'] ?>">

        <div class="login-field">
            <label>Template name</label>
            <input type="text" name="name"
                value="<?= htmlspecialchars($receiptTemplate['name'] ?? '') ?>"
                placeholder="Main Bill 80mm" required>
        </div>

        <div class="login-field">
            <label>Description (optional)</label>
            <input type="text" name="description"
                value="<?= htmlspecialchars($receiptTemplate['description'] ?? '') ?>"
                placeholder="e.g. Default receipt for dine-in">
        </div>

        <div class="login-field">
            <label>Company / Restaurant name</label>
            <input type="text" name="company_name"
                value="<?= htmlspecialchars($receiptTemplate['company_name'] ?? '') ?>"
                placeholder="Your Restaurant" required>
        </div>

        <div class="login-field">
            <label>Address</label>
            <textarea name="address" rows="2"
                placeholder="Address"><?= htmlspecialchars($receiptTemplate['address'] ?? '') ?></textarea>
        </div>

        <div class="login-field">
            <label>Telephone</label>
            <input type="text" name="phone"
                value="<?= htmlspecialchars($receiptTemplate['phone'] ?? '') ?>"
                placeholder="+94...">
        </div>

        <div class="login-field">
            <label>Logo URL (optional)</label>
            <input type="text" name="logo_url"
                value="<?= htmlspecialchars($receiptTemplate['logo_url'] ?? '') ?>"
                placeholder="http://...">
        </div>

        <div class="login-field">
            <label>Paper width (mm)</label>
            <input type="number" name="paper_width_mm" min="50" max="90"
                value="<?= (int)($receiptTemplate['paper_width_mm'] ?? 80) ?>">
            <small style="display:block;color:#666;margin-top:4px;">
                Typical thermal rolls are 80mm. Use 58mm if your printer is smaller.
            </small>
        </div>

        <div class="login-field">
            <label>Margins (mm)</label>
            <input type="number" name="margins_mm" min="0" max="20"
                value="<?= (int)($receiptTemplate['margins_mm'] ?? 3) ?>">
        </div>

        <div class="login-field">
            <label>
                <input type="checkbox" name="show_item_images"
                    <?= !empty($receiptTemplate['show_item_images']) ? 'checked' : '' ?>>
                Show item images
            </label>
        </div>

        <div class="login-field">
            <label>
                <input type="checkbox" name="show_tax"
                    <?= !empty($receiptTemplate['show_tax']) ? 'checked' : '' ?>>
                Show tax line
            </label>
        </div>

        <div class="login-field">
            <label>
                <input type="checkbox" name="show_service"
                    <?= !empty($receiptTemplate['show_service']) ? 'checked' : '' ?>>
                Show service charge line
            </label>
        </div>

        <div class="login-field">
            <label>
                <input type="checkbox" name="show_table_no"
                    <?= !empty($receiptTemplate['show_table_no']) ? 'checked' : '' ?>>
                Show table number
            </label>
        </div>

        <div class="login-field">
            <label>
                <input type="checkbox" name="show_datetime"
                    <?= !empty($receiptTemplate['show_datetime']) ? 'checked' : '' ?>>
                Show date &amp; time
            </label>
        </div>

        <div class="login-field">
            <label>Header lines (one per line)</label>
            <textarea name="header_lines" rows="3"><?= htmlspecialchars($receiptTemplate['header_lines'] ?? '') ?></textarea>
        </div>

        <div class="login-field">
            <label>Footer lines (one per line)</label>
            <textarea name="footer_lines" rows="3"><?= htmlspecialchars($receiptTemplate['footer_lines'] ?? '') ?></textarea>
        </div>

        <h4>KOT options</h4>

        <div class="login-field">
            <label>KOT title</label>
            <input type="text" name="kot_title"
                value="<?= htmlspecialchars($receiptTemplate['kot_title'] ?? 'KITCHEN ORDER TICKET') ?>">
        </div>

        <div class="login-field">
            <label>
                <input type="checkbox" name="kot_show_table"
                    <?= !empty($receiptTemplate['kot_show_table']) ? 'checked' : '' ?>>
                Show table number on KOT
            </label>
        </div>

        <div class="login-field">
            <label>
                <input type="checkbox" name="kot_show_time"
                    <?= !empty($receiptTemplate['kot_show_time']) ? 'checked' : '' ?>>
                Show time on KOT
            </label>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
            <button class="btn-primary" type="submit" name="action" value="save">
                Save template
            </button>
            <button class="btn-primary" type="submit" name="action" value="save_new">
                Save as new
            </button>
            <?php if (!empty($receiptTemplate['id'])): ?>
                <button class="btn-primary" type="submit" name="action" value="activate">
                    Set as active
                </button>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../layouts/footer.php'; ?>