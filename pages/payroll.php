<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../roles.php';
authorize(['admin']); // Only admin can access payroll – change if needed
$page_title = 'Payroll';
require_once __DIR__ . '/../config/db.php';

/* -------------------------------------------------------------
 *  Ensure basic payroll tables exist
 * ----------------------------------------------------------- */
try {
    // Employees master data
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            job_title VARCHAR(150) NULL,
            monthly_salary DECIMAL(10,2) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Payroll run header (one per period)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payroll_runs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            period_start DATE NOT NULL,
            period_end   DATE NOT NULL,
            total_net    DECIMAL(12,2) NOT NULL DEFAULT 0,
            note         VARCHAR(255) NULL,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Payroll items (per employee in each run)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payroll_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            run_id INT NOT NULL,
            employee_id INT NOT NULL,
            basic_pay   DECIMAL(10,2) NOT NULL DEFAULT 0,
            allowance   DECIMAL(10,2) NOT NULL DEFAULT 0,
            deduction   DECIMAL(10,2) NOT NULL DEFAULT 0,
            net_pay     DECIMAL(10,2) NOT NULL DEFAULT 0,
            FOREIGN KEY (run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Throwable $e) {
    // Don't kill the page; just show an error
    $errors[] = 'Failed to ensure payroll tables exist: ' . $e->getMessage();
}

/* -------------------------------------------------------------
 *  Handle POST actions
 * ----------------------------------------------------------- */
$success = $success ?? '';
$errors  = $errors  ?? [];

$form = $_POST['form'] ?? '';

/* -- Save / add employee -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form === 'employee_save') {
    $empId    = (int)($_POST['id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $jobTitle = trim($_POST['job_title'] ?? '');
    $salary   = (float)($_POST['monthly_salary'] ?? 0);
    $active   = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        $errors[] = 'Employee name is required.';
    }

    if (!$errors) {
        try {
            if ($empId > 0) {
                $st = $pdo->prepare("
                    UPDATE employees
                    SET name = ?, job_title = ?, monthly_salary = ?, is_active = ?
                    WHERE id = ?
                ");
                $st->execute([$name, $jobTitle, $salary, $active, $empId]);
                $success = 'Employee updated.';
            } else {
                $st = $pdo->prepare("
                    INSERT INTO employees (name, job_title, monthly_salary, is_active)
                    VALUES (?,?,?,?)
                ");
                $st->execute([$name, $jobTitle, $salary, $active]);
                $success = 'Employee added.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Failed to save employee: ' . $e->getMessage();
        }
    }
}

/* -- Quick toggle active/inactive -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form === 'employee_toggle') {
    $empId = (int)($_POST['id'] ?? 0);
    $to    = (int)($_POST['to'] ?? 0);
    if ($empId > 0) {
        try {
            $st = $pdo->prepare("UPDATE employees SET is_active = ? WHERE id = ?");
            $st->execute([$to, $empId]);
            $success = 'Employee status updated.';
        } catch (Throwable $e) {
            $errors[] = 'Failed to update employee status: ' . $e->getMessage();
        }
    }
}

/* -- Create a payroll run -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form === 'payroll_run') {
    $periodStart = $_POST['period_start'] ?? '';
    $periodEnd   = $_POST['period_end']   ?? '';
    $note        = trim($_POST['note'] ?? '');

    // Very simple validation
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart)) {
        $errors[] = 'Invalid period start date.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd)) {
        $errors[] = 'Invalid period end date.';
    }
    if ($periodStart > $periodEnd) {
        $errors[] = 'Period start must be before end date.';
    }

    $empIds     = $_POST['emp_id']      ?? [];
    $basicArr   = $_POST['basic_pay']   ?? [];
    $allowArr   = $_POST['allowance']   ?? [];
    $dedArr     = $_POST['deduction']   ?? [];

    $items = [];
    $totalNet = 0.0;

    if (!$errors) {
        // Build items
        $count = count($empIds);
        for ($i = 0; $i < $count; $i++) {
            $eid   = (int)($empIds[$i] ?? 0);
            $basic = (float)($basicArr[$i] ?? 0);
            $all   = (float)($allowArr[$i] ?? 0);
            $ded   = (float)($dedArr[$i] ?? 0);

            // Skip completely empty rows
            if ($eid <= 0) {
                continue;
            }
            if (abs($basic) < 0.0001 && abs($all) < 0.0001 && abs($ded) < 0.0001) {
                continue;
            }

            $net = $basic + $all - $ded;
            $totalNet += $net;

            $items[] = [
                'employee_id' => $eid,
                'basic_pay'   => $basic,
                'allowance'   => $all,
                'deduction'   => $ded,
                'net_pay'     => $net,
            ];
        }

        if (!$items) {
            $errors[] = 'No payroll items were entered.';
        }
    }

    if (!$errors && $items) {
        try {
            $pdo->beginTransaction();

            $st = $pdo->prepare("
                INSERT INTO payroll_runs (period_start, period_end, total_net, note)
                VALUES (?,?,?,?)
            ");
            $st->execute([$periodStart, $periodEnd, $totalNet, $note]);
            $runId = (int)$pdo->lastInsertId();

            $itSt = $pdo->prepare("
                INSERT INTO payroll_items (run_id, employee_id, basic_pay, allowance, deduction, net_pay)
                VALUES (?,?,?,?,?,?)
            ");
            foreach ($items as $it) {
                $itSt->execute([
                    $runId,
                    $it['employee_id'],
                    $it['basic_pay'],
                    $it['allowance'],
                    $it['deduction'],
                    $it['net_pay'],
                ]);
            }

            $pdo->commit();
            $success = 'Payroll run created successfully.';
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Failed to create payroll run: ' . $e->getMessage();
        }
    }
}

/* -------------------------------------------------------------
 *  Load data for display (employees + recent payroll runs)
 * ----------------------------------------------------------- */

// Employees
$employees = [];
try {
    $employees = $pdo->query("
        SELECT * FROM employees
        ORDER BY is_active DESC, name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'Failed to load employees: ' . $e->getMessage();
}

// Recent payroll runs
$payrollRuns = [];
try {
    $st = $pdo->query("
        SELECT pr.*,
               COUNT(pi.id) AS employee_count
        FROM payroll_runs pr
        LEFT JOIN payroll_items pi ON pi.run_id = pr.id
        GROUP BY pr.id
        ORDER BY pr.created_at DESC
        LIMIT 10
    ");
    $payrollRuns = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'Failed to load payroll runs: ' . $e->getMessage();
}

include __DIR__ . '/../layouts/header.php';
?>

<?php if (!empty($errors)): ?>
    <div style="margin-bottom:10px; padding:8px 12px; border-radius:8px; background:#ffe0e0; color:#a00;">
        <?php foreach ($errors as $err): ?>
            <div><?= htmlspecialchars($err) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <div style="margin-bottom:10px; padding:8px 12px; border-radius:8px; background:#e0ffe0; color:#064e3b;">
        <?= htmlspecialchars($success) ?>
    </div>
<?php endif; ?>

<div class="dashboard-grid">
    <!-- Employees card -->
    <div class="card">
        <h3>Employees</h3>

        <form method="post" class="login-form" style="margin-top:8px;">
            <input type="hidden" name="form" value="employee_save">
            <input type="hidden" name="id" value="0">

            <div class="login-field">
                <label>Name</label>
                <input type="text" name="name" placeholder="Employee name" required>
            </div>

            <div class="login-field">
                <label>Job title / role</label>
                <input type="text" name="job_title" placeholder="Chef, Cashier, Waiter…">
            </div>

            <div class="login-field">
                <label>Monthly salary (LKR)</label>
                <input type="number" step="0.01" min="0" name="monthly_salary" value="0">
            </div>

            <div class="login-field">
                <label>
                    <input type="checkbox" name="is_active" checked>
                    Active
                </label>
            </div>

            <button class="btn-primary" type="submit">Add employee</button>
        </form>

        <div style="margin-top:14px;">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Job Title</th>
                        <th style="text-align:right;">Monthly Salary</th>
                        <th>Status</th>
                        <th style="width:100px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$employees): ?>
                        <tr>
                            <td colspan="5" style="text-align:center;">No employees yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($employees as $emp): ?>
                            <tr>
                                <td><?= htmlspecialchars($emp['name']) ?></td>
                                <td><?= htmlspecialchars($emp['job_title'] ?? '') ?></td>
                                <td style="text-align:right;"><?= number_format((float)$emp['monthly_salary'], 2) ?></td>
                                <td><?= !empty($emp['is_active']) ? 'Active' : 'Inactive' ?></td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="form" value="employee_toggle">
                                        <input type="hidden" name="id" value="<?= (int)$emp['id'] ?>">
                                        <?php if (!empty($emp['is_active'])): ?>
                                            <input type="hidden" name="to" value="0">
                                            <button class="btn-chip small" type="submit">Deactivate</button>
                                        <?php else: ?>
                                            <input type="hidden" name="to" value="1">
                                            <button class="btn-chip small" type="submit">Activate</button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Payroll run card -->
    <div class="card big-card">
        <h3>Create Payroll Run</h3>

        <form method="post" class="login-form" style="margin-top:8px;">
            <input type="hidden" name="form" value="payroll_run">

            <div class="login-field">
                <label>Period start</label>
                <input type="date" name="period_start" value="<?= htmlspecialchars(date('Y-m-01')) ?>">
            </div>

            <div class="login-field">
                <label>Period end</label>
                <input type="date" name="period_end" value="<?= htmlspecialchars(date('Y-m-t')) ?>">
            </div>

            <div class="login-field">
                <label>Note (optional)</label>
                <input type="text" name="note" placeholder="e.g. Monthly salary for March">
            </div>

            <div class="login-field">
                <label>Payroll details per employee</label>
                <p style="font-size:12px; color:#666; margin-top:4px;">
                    Default basic pay is the employee's monthly salary. You can adjust per run
                    and add allowances or deductions (e.g. bonuses, advances).
                </p>
            </div>

            <div style="max-height:260px; overflow:auto; border:1px solid #eee; border-radius:12px; padding:8px;">
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th style="text-align:right;">Basic pay</th>
                            <th style="text-align:right;">Allowance</th>
                            <th style="text-align:right;">Deduction</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$employees): ?>
                            <tr>
                                <td colspan="4" style="text-align:center;">No employees to pay.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($employees as $emp): ?>
                                <?php if (empty($emp['is_active'])) continue; ?>
                                <tr>
                                    <td>
                                        <?= htmlspecialchars($emp['name']) ?>
                                        <?php if (!empty($emp['job_title'])): ?>
                                            <div style="font-size:11px; color:#666;">
                                                <?= htmlspecialchars($emp['job_title']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <input type="hidden" name="emp_id[]" value="<?= (int)$emp['id'] ?>">
                                    </td>
                                    <td style="text-align:right;">
                                        <input type="number" step="0.01" name="basic_pay[]"
                                            value="<?= htmlspecialchars(number_format((float)$emp['monthly_salary'], 2, '.', '')) ?>"
                                            style="width:100px; text-align:right;">
                                    </td>
                                    <td style="text-align:right;">
                                        <input type="number" step="0.01" name="allowance[]"
                                            value="0.00"
                                            style="width:100px; text-align:right;">
                                    </td>
                                    <td style="text-align:right;">
                                        <input type="number" step="0.01" name="deduction[]"
                                            value="0.00"
                                            style="width:100px; text-align:right;">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <button class="btn-primary" type="submit" style="margin-top:10px;">
                Generate payroll
            </button>
        </form>

        <div style="margin-top:18px;">
            <h4 style="margin-bottom:8px;">Recent payroll runs</h4>
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>Period</th>
                        <th style="text-align:right;">Employees</th>
                        <th style="text-align:right;">Total net (LKR)</th>
                        <th>Note</th>
                        <th>Created at</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$payrollRuns): ?>
                        <tr>
                            <td colspan="6" style="text-align:center;">No payroll runs yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($payrollRuns as $run): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($run['period_start']) ?>
                                    &rarr;
                                    <?= htmlspecialchars($run['period_end']) ?>
                                </td>
                                <td style="text-align:right;"><?= (int)$run['employee_count'] ?></td>
                                <td style="text-align:right;"><?= number_format((float)$run['total_net'], 2) ?></td>
                                <td><?= htmlspecialchars($run['note'] ?? '') ?></td>
                                <td><?= htmlspecialchars($run['created_at']) ?></td>
                                <td>
                                    <a href="payroll_view.php?id=<?= (int)$run['id'] ?>" class="btn-chip small">
                                        View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

        </div>
    </div>
</div>

<?php include __DIR__ . '/../layouts/footer.php'; ?>