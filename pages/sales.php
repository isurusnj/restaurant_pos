<?php
require_once __DIR__ . '/../auth_check.php';
$page_title = 'Sales';

require_once __DIR__ . '/../config/db.php';

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../tools/access.php';
ensure_feature_access('sales');   // 🚫 cashier will get 403 if not allowed

$page_title = 'Sales';
require_once __DIR__ . '/../config/db.php';

// ---- Quick range helpers ----
$today      = date('Y-m-d');
$yesterday  = date('Y-m-d', strtotime('-1 day'));
$weekStart  = date('Y-m-d', strtotime('monday this week'));
$weekEnd    = date('Y-m-d', strtotime('sunday this week'));
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');

// ---- Filters from query string ----
$start    = $_GET['start'] ?? $today;
$end      = $_GET['end']   ?? $today;
$q        = trim($_GET['q'] ?? '');
$onlyPaid = isset($_GET['only_paid']) ? 1 : 0;

// Basic validation
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = $today;
if ($start > $end) {
    $tmp = $start;
    $start = $end;
    $end = $tmp;
}

// --------------------------------------------------
// 1) MAIN ORDERS QUERY (TABLE + TOTALS)
// --------------------------------------------------
$where  = [];
$params = [];

// Date range
$where[]  = "DATE(o.created_at) BETWEEN ? AND ?";
$params[] = $start;
$params[] = $end;

// Not cancelled
$where[] = "o.status <> 'cancelled'";

// Only paid?
if ($onlyPaid) {
    $where[] = "o.status = 'paid'";
}

// Search by order number or customer
if ($q !== '') {
    $where[] = "(o.order_number LIKE ? OR c.name LIKE ?)";
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
    SELECT 
        o.*,
        c.name AS customer_name,
        t.table_name,
        IFNULL(SUM(p.amount),0) AS paid_amount
    FROM orders o
    LEFT JOIN customers c ON c.id = o.customer_id
    LEFT JOIN restaurant_tables t ON t.id = o.table_id
    LEFT JOIN payments p ON p.order_id = o.id
    $whereSql
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT 500
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totals
$totalOrders   = count($orders);
$grossTotal    = 0.0;
$paidTotal     = 0.0;
$balanceTotal  = 0.0;

foreach ($orders as $o) {
    $gross = (float)$o['total_amount'];
    $paid  = (float)$o['paid_amount'];
    $bal   = max(0, $gross - $paid);

    $grossTotal   += $gross;
    $paidTotal    += $paid;
    $balanceTotal += $bal;
}

// --------------------------------------------------
// 2) BUILD DAILY TREND FROM ORDERS ARRAY
// --------------------------------------------------
$daily = []; // 'Y-m-d' => ['revenue' => ..., 'orders' => n]

foreach ($orders as $o) {
    $day = substr($o['created_at'], 0, 10); // 'YYYY-MM-DD'
    if (!isset($daily[$day])) {
        $daily[$day] = [
            'revenue' => 0.0,
            'orders'  => 0
        ];
    }
    $daily[$day]['revenue'] += (float)$o['total_amount'];
    $daily[$day]['orders']  += 1;
}

// Sort by date
ksort($daily);

// Convert to a list for easy iteration
$trend = [];
foreach ($daily as $day => $vals) {
    $trend[] = [
        'day'     => $day,
        'revenue' => $vals['revenue'],
        'orders'  => $vals['orders'],
    ];
}

// --------------------------------------------------
// 3) SIMPLE PAYMENT BREAKDOWN (PAID VS UNPAID)
// --------------------------------------------------
$paymentBreakdown = [];
if ($grossTotal > 0) {
    $paymentBreakdown[] = [
        'label'  => 'Paid',
        'amount' => $paidTotal,
        'count'  => null,
    ];
    $paymentBreakdown[] = [
        'label'  => 'Unpaid',
        'amount' => $balanceTotal,
        'count'  => null,
    ];
}

// --------------------------------------------------
// 4) CSV EXPORT
// --------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sales_' . $start . '_to_' . $end . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Sales report', $start . ' to ' . $end]);
    if ($onlyPaid) fputcsv($out, ['Filter', 'Only paid orders']);
    if ($q !== '') fputcsv($out, ['Search', $q]);
    fputcsv($out, []);
    fputcsv($out, ['Order No', 'Date/Time', 'Customer', 'Type', 'Table', 'Status', 'Total (LKR)', 'Paid (LKR)', 'Balance (LKR)']);

    foreach ($orders as $o) {
        $gross = (float)$o['total_amount'];
        $paid  = (float)$o['paid_amount'];
        $bal   = max(0, $gross - $paid);
        $customer = $o['customer_name'] ?: 'Walk-in';
        $type  = ucfirst(str_replace('_', ' ', $o['order_type']));
        $table = $o['table_name'] ?: '-';

        fputcsv($out, [
            $o['order_number'],
            $o['created_at'],
            $customer,
            $type,
            $table,
            $o['status'],
            number_format($gross, 2),
            number_format($paid, 2),
            number_format($bal, 2),
        ]);
    }

    fputcsv($out, []);
    fputcsv($out, [
        'Totals',
        '',
        '',
        '',
        '',
        '',
        number_format($grossTotal, 2),
        number_format($paidTotal, 2),
        number_format($balanceTotal, 2)
    ]);

    fclose($out);
    exit;
}

include __DIR__ . '/../layouts/header.php';
?>

<div class="reports-header">
    <h2>Sales</h2>
    <form method="get" class="report-filter">
        <label>From</label>
        <input type="date" name="start" value="<?= htmlspecialchars($start) ?>">
        <label>To</label>
        <input type="date" name="end" value="<?= htmlspecialchars($end) ?>">

        <label>Search</label>
        <input type="text" name="q" placeholder="Order no or customer" value="<?= htmlspecialchars($q) ?>">

        <label style="display:flex;align-items:center;gap:4px;">
            <input type="checkbox" name="only_paid" value="1" <?= $onlyPaid ? 'checked' : '' ?>>
            Only paid
        </label>

        <button class="btn-chip" type="submit">Apply</button>
        <a class="btn-chip" href="sales.php">Reset</a>
        <a class="btn-chip"
            href="sales.php?start=<?= urlencode($start) ?>&end=<?= urlencode($end) ?>&q=<?= urlencode($q) ?>&only_paid=<?= $onlyPaid ?>&export=csv">
            Export CSV
        </a>
    </form>

    <div style="margin-top:8px; font-size:12px;">
        <span style="margin-right:6px;">Quick ranges:</span>
        <a href="sales.php?start=<?= $today ?>&end=<?= $today ?>" class="btn-chip small">Today</a>
        <a href="sales.php?start=<?= $yesterday ?>&end=<?= $yesterday ?>" class="btn-chip small">Yesterday</a>
        <a href="sales.php?start=<?= $weekStart ?>&end=<?= $weekEnd ?>" class="btn-chip small">This week</a>
        <a href="sales.php?start=<?= $monthStart ?>&end=<?= $monthEnd ?>" class="btn-chip small">This month</a>
    </div>
</div>

<div class="dashboard-grid">
    <div class="card stat-card">
        <h3>Total Orders</h3>
        <p class="stat-value"><?= (int)$totalOrders ?></p>
        <span class="stat-sub"><?= htmlspecialchars($start) ?> → <?= htmlspecialchars($end) ?></span>
    </div>
    <div class="card stat-card">
        <h3>Gross Sales</h3>
        <p class="stat-money">LKR <?= number_format($grossTotal, 2) ?></p>
        <span class="stat-sub">Sum of order totals</span>
    </div>
    <div class="card stat-card">
        <h3>Payments received</h3>
        <p class="stat-money">LKR <?= number_format($paidTotal, 2) ?></p>
        <span class="stat-sub">Amount paid</span>
    </div>
    <div class="card stat-card">
        <h3>Outstanding balance</h3>
        <p class="stat-money">LKR <?= number_format($balanceTotal, 2) ?></p>
        <span class="stat-sub">Unpaid amounts</span>
    </div>
</div>

<!-- Charts row -->
<div class="dashboard-grid" style="margin-top:16px;">
    <div class="card">
        <h3>Daily sales trend</h3>
        <?php if (empty($trend)): ?>
            <p style="font-size:13px; color:#666; margin-top:8px;">No orders in this period.</p>
        <?php else: ?>
            <?php
            $maxRevenue = 0;
            foreach ($trend as $d) {
                if ($d['revenue'] > $maxRevenue) $maxRevenue = $d['revenue'];
            }
            ?>
            <div class="mini-bar-chart">
                <?php foreach ($trend as $d): ?>
                    <?php $pct = $maxRevenue > 0 ? ($d['revenue'] / $maxRevenue) * 100 : 0; ?>
                    <div class="bar-row">
                        <span class="bar-label"><?= htmlspecialchars($d['day']) ?></span>
                        <div class="bar-track">
                            <div class="bar-fill" style="width:<?= number_format($pct, 1) ?>%;"></div>
                        </div>
                        <span class="bar-value">
                            LKR <?= number_format((float)$d['revenue'], 0) ?><br>
                            <span style="font-size:11px; color:#666;"><?= (int)$d['orders'] ?> orders</span>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Paid vs Unpaid</h3>
        <?php if (empty($paymentBreakdown)): ?>
            <p style="font-size:13px; color:#666; margin-top:8px;">No payment data in this period.</p>
        <?php else: ?>
            <?php
            $maxPay = 0;
            foreach ($paymentBreakdown as $p) {
                if ($p['amount'] > $maxPay) $maxPay = $p['amount'];
            }
            ?>
            <div class="mini-bar-chart">
                <?php foreach ($paymentBreakdown as $p): ?>
                    <?php $pct = $maxPay > 0 ? ($p['amount'] / $maxPay) * 100 : 0; ?>
                    <div class="bar-row">
                        <span class="bar-label"><?= htmlspecialchars($p['label']) ?></span>
                        <div class="bar-track">
                            <div class="bar-fill" style="width:<?= number_format($pct, 1) ?>%;"></div>
                        </div>
                        <span class="bar-value">
                            LKR <?= number_format((float)$p['amount'], 0) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Orders table -->
<div class="card" style="margin-top:16px;">
    <h3>Orders in this period</h3>
    <table class="orders-table" style="margin-top:8px;">
        <thead>
            <tr>
                <th>#</th>
                <th>Order No</th>
                <th>Customer</th>
                <th>Type</th>
                <th>Table</th>
                <th style="text-align:right;">Total (LKR)</th>
                <th style="text-align:right;">Paid (LKR)</th>
                <th style="text-align:right;">Balance</th>
                <th>Status</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$orders): ?>
                <tr>
                    <td colspan="11" style="text-align:center;">No orders for this period.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($orders as $o): ?>
                    <?php
                    $gross  = (float)$o['total_amount'];
                    $paid   = (float)$o['paid_amount'];
                    $bal    = max(0, $gross - $paid);
                    $customer = $o['customer_name'] ?: 'Walk-in';
                    $type  = ucfirst(str_replace('_', ' ', $o['order_type']));
                    $table = $o['table_name'] ?: '-';
                    ?>
                    <tr>
                        <td><?= (int)$o['id'] ?></td>
                        <td><?= htmlspecialchars($o['order_number']) ?></td>
                        <td><?= htmlspecialchars($customer) ?></td>
                        <td><?= htmlspecialchars($type) ?></td>
                        <td><?= htmlspecialchars($table) ?></td>
                        <td style="text-align:right;"><?= number_format($gross, 2) ?></td>
                        <td style="text-align:right;"><?= number_format($paid, 2) ?></td>
                        <td style="text-align:right;"><?= number_format($bal, 2) ?></td>
                        <td><?= ucfirst($o['status']) ?></td>
                        <td><?= htmlspecialchars($o['created_at']) ?></td>
                        <td class="orders-actions">
                            <a href="order_view.php?id=<?= (int)$o['id'] ?>" class="btn-chip small">Bill</a>
                            <a href="order_kot.php?id=<?= (int)$o['id'] ?>" class="btn-chip small">KOT</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/../layouts/footer.php'; ?>