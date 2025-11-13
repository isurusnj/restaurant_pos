<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: orders.php'); exit;
}

$orderId    = (int)($_POST['order_id'] ?? 0);
$method     = trim($_POST['method'] ?? 'cash');
$amount     = (float)($_POST['amount'] ?? 0);
$cashGiven  = isset($_POST['cash_given']) && $_POST['cash_given'] !== '' ? (float)$_POST['cash_given'] : null;

if ($orderId <= 0 || $amount <= 0 || !in_array($method, ['cash','card','wallet','bank'], true)) {
    header('Location: order_view.php?id=' . $orderId); exit;
}

// fetch order total
$stmt = $pdo->prepare("SELECT total_amount FROM orders WHERE id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) { header('Location: orders.php'); exit; }

try {
    $pdo->beginTransaction();

    // insert payment
    $ins = $pdo->prepare("INSERT INTO payments (order_id, amount, method, notes) VALUES (?, ?, ?, ?)");
    $note = $cashGiven !== null ? ('cash_given=' . number_format($cashGiven,2)) : null;
    $ins->execute([$orderId, $amount, $method, $note]);

    // compute total paid so far
    $sum = $pdo->prepare("SELECT IFNULL(SUM(amount),0) AS paid FROM payments WHERE order_id = ?");
    $sum->execute([$orderId]);
    $paid = (float)$sum->fetchColumn();

    // update payment_status (and optionally order status)
    if ($paid + 0.0001 >= (float)$order['total_amount']) {
        $upd = $pdo->prepare("UPDATE orders SET payment_status='paid', status=IF(status='ready' OR status='served' OR status='paid','paid',status), updated_at=NOW() WHERE id = ?");
        $upd->execute([$orderId]);
    } else {
        $upd = $pdo->prepare("UPDATE orders SET payment_status='partial', updated_at=NOW() WHERE id = ?");
        $upd->execute([$orderId]);
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    // In production you might log the error
}

header('Location: order_view.php?id=' . $orderId);
exit;
