<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

// Last 24h, only active kitchen statuses
$allowed = ['pending','in_progress','ready'];
$params  = $allowed;

$sql = "
  SELECT
    o.id, o.order_number, o.order_type, o.status, o.table_id, o.created_at,
    t.table_name
  FROM orders o
  LEFT JOIN restaurant_tables t ON t.id = o.table_id
  WHERE o.status IN (?,?,?)
    AND o.created_at >= NOW() - INTERVAL 1 DAY
  ORDER BY o.id DESC
  LIMIT 60
";
$orders = [];
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load items for these orders
if ($rows) {
    $ids = array_column($rows, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));

    $it = $pdo->prepare("
    SELECT oi.order_id, oi.qty, m.name
    FROM order_items oi
    JOIN menu_items m ON m.id = oi.menu_item_id
    WHERE oi.order_id IN ($in)
    ORDER BY m.name
  ");
    $it->execute($ids);
    $items = $it->fetchAll(PDO::FETCH_ASSOC);

    // group items by order_id
    $byOrder = [];
    foreach ($items as $r) {
        $byOrder[$r['order_id']][] = ['name'=>$r['name'], 'qty'=>(int)$r['qty']];
    }

    foreach ($rows as $r) {
        $r['items'] = $byOrder[$r['id']] ?? [];
        $orders[] = $r;
    }
}

echo json_encode([
    'ok' => true,
    'now' => date('c'),
    'orders' => $orders
]);
