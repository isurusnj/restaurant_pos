<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../roles.php';          // <-- add this
authorize(['admin']);                            // protect page

$page_title = 'Sales Reports';
require_once __DIR__ . '/../config/db.php';

// Defaults: today
$today = date('Y-m-d');
$start = $_GET['start'] ?? $today;
$end   = $_GET['end']   ?? $today;

// Validate dates (very basic)
$start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? $start : $today;
$end   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)   ? $end   : $today;

// Build where clause
$where = "DATE(o.created_at) BETWEEN ? AND ?";

// ---- KPI cards ----
$kpiStmt = $pdo->prepare("
    SELECT
      IFNULL(SUM(o.total_amount),0) AS total_revenue,
      COUNT(*)                      AS total_orders,
      COUNT(DISTINCT o.customer_id) AS unique_customers
    FROM orders o
    WHERE $where AND o.status <> 'cancelled'
");
$kpiStmt->execute([$start, $end]);
$kpis = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_revenue' => 0, 'total_orders' => 0, 'unique_customers' => 0];

// ---- Payment breakdown ----
$payStmt = $pdo->prepare("
    SELECT p.method, IFNULL(SUM(p.amount),0) AS amount, COUNT(*) AS cnt
    FROM payments p
    JOIN orders o ON o.id = p.order_id
    WHERE $where
    GROUP BY p.method
    ORDER BY amount DESC
");
$payStmt->execute([$start, $end]);
$payRows = $payStmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Top products ----
$topStmt = $pdo->prepare("
    SELECT m.name, SUM(oi.qty) AS qty_sold, SUM(oi.total_price) AS revenue
    FROM order_items oi
    JOIN orders o   ON o.id = oi.order_id
    JOIN menu_items m ON m.id = oi.menu_item_id
    WHERE $where AND o.status <> 'cancelled'
    GROUP BY m.id, m.name
    ORDER BY qty_sold DESC
    LIMIT 10
");
$topStmt->execute([$start, $end]);
$topItems = $topStmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Daily totals ----
$trendStmt = $pdo->prepare("
    SELECT DATE(o.created_at) AS day, IFNULL(SUM(o.total_amount),0) AS revenue, COUNT(*) AS orders
    FROM orders o
    WHERE $where AND o.status <> 'cancelled'
    GROUP BY DATE(o.created_at)
    ORDER BY day ASC
");
$trendStmt->execute([$start, $end]);
$trend = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

// ---- CSV export ----
if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sales_report_' . $start . '_to_' . $end . '.csv"');
    $out = fopen('php://output', 'w');

    // KPIs
    fputcsv($out, ['Sales Report', "$start to $end"]);
    fputcsv($out, []);
    fputcsv($out, ['Total Revenue', 'Total Orders', 'Unique Customers']);
    fputcsv($out, [number_format($kpis['total_revenue'], 2), $kpis['total_orders'], $kpis['unique_customers']]);
    fputcsv($out, []);

    // Payment breakdown
    fputcsv($out, ['Payment Method', 'Amount', 'Count']);
    foreach ($payRows as $r) {
        fputcsv($out, [ucfirst($r['method']), number_format($r['amount'], 2), $r['cnt']]);
    }
    fputcsv($out, []);

    // Top items
    fputcsv($out, ['Top Items']);
    fputcsv($out, ['Item', 'Qty Sold', 'Revenue']);
    foreach ($topItems as $ti) {
        fputcsv($out, [$ti['name'], $ti['qty_sold'], number_format($ti['revenue'], 2)]);
    }
    fputcsv($out, []);

    // Daily trend
    fputcsv($out, ['Daily Trend']);
    fputcsv($out, ['Date', 'Revenue', 'Orders']);
    foreach ($trend as $d) {
        fputcsv($out, [$d['day'], number_format($d['revenue'], 2), $d['orders']]);
    }
    fclose($out);
    exit;
}

include __DIR__ . '/../layouts/header.php';  // <-- fixed path (no space)
?>

<div class="reports-header">
    <h2>Sales Reports</h2>
    <form method="get" class="report-filter">
        <label>From</label>
        <input type="date" name="start" value="<?= htmlspecialchars($start) ?>">
        <label>To</label>
        <input type="date" name="end" value="<?= htmlspecialchars($end) ?>">
        <button class="btn-chip">Apply</button>
        <a class="btn-chip" href="reports.php?start=<?= urlencode($start) ?>&end=<?= urlencode($end) ?>&export=1">Export CSV</a>
    </form>
</div>

<div class="dashboard-grid">
    <div class="card stat-card">
        <h3>Total Revenue</h3>
        <p class="stat-money">$<?= number_format($kpis['total_revenue'], 2) ?></p>
        <span class="stat-sub"><?= htmlspecialchars($start) ?> → <?= htmlspecialchars($end) ?></span>
    </div>
    <div class="card stat-card">
        <h3>Total Orders</h3>
        <p class="stat-value"><?= (int)$kpis['total_orders'] ?></p>
        <span class="stat-sub">Completed & active</span>
    </div>
    <div class="card stat-card">
        <h3>Unique Customers</h3>
        <p class="stat-value"><?= (int)$kpis['unique_customers'] ?></p>
        <span class="stat-sub">Based on customer_id</span>
    </div>

    <div class="card big-card">
        <h3>Daily Trend</h3>
        <table class="orders-table" style="margin-top:8px;">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Revenue</th>
                    <th>Orders</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$trend): ?>
                    <tr>
                        <td colspan="3" style="text-align:center;">No data.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($trend as $d): ?>
                        <tr>
                            <td><?= htmlspecialchars($d['day']) ?></td>
                            <td>$<?= number_format($d['revenue'], 2) ?></td>
                            <td><?= (int)$d['orders'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3>Payment Breakdown</h3>
        <table class="orders-table" style="margin-top:8px;">
            <thead>
                <tr>
                    <th>Method</th>
                    <th>Amount</th>
                    <th>Count</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$payRows): ?>
                    <tr>
                        <td colspan="3" style="text-align:center;">No payments.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payRows as $r): ?>
                        <tr>
                            <td><?= ucfirst($r['method']) ?></td>
                            <td>$<?= number_format($r['amount'], 2) ?></td>
                            <td><?= (int)$r['cnt'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3>Top Products</h3>
        <table class="orders-table" style="margin-top:8px;">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Qty Sold</th>
                    <th>Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$topItems): ?>
                    <tr>
                        <td colspan="3" style="text-align:center;">No items sold.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($topItems as $ti): ?>
                        <tr>
                            <td><?= htmlspecialchars($ti['name']) ?></td>
                            <td><?= (int)$ti['qty_sold'] ?></td>
                            <td>$<?= number_format($ti['revenue'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../layouts/footer.php'; ?>