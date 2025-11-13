<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../config/db.php';

$orderId = (int)($_POST['order_id'] ?? 0);
$to      = trim($_POST['to'] ?? '');

if ($orderId <= 0 || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    header('Location: order_view.php?id=' . $orderId); exit;
}

// Pull order, items, payments (similar to order_view)
$stmt = $pdo->prepare("
  SELECT o.*, t.table_name, u.name AS cashier_name, c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email
  FROM orders o
  LEFT JOIN restaurant_tables t ON o.table_id = t.id
  LEFT JOIN users u ON o.created_by = u.id
  LEFT JOIN customers c ON c.id = o.customer_id
  WHERE o.id = ?
");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) { header('Location: orders.php'); exit; }

$it = $pdo->prepare("
  SELECT oi.*, m.name
  FROM order_items oi
  JOIN menu_items m ON m.id = oi.menu_item_id
  WHERE oi.order_id = ?
");
$it->execute([$orderId]);
$items = $it->fetchAll(PDO::FETCH_ASSOC);

$sum = $pdo->prepare("SELECT IFNULL(SUM(amount),0) AS paid FROM payments WHERE order_id = ?");
$sum->execute([$orderId]);
$totalPaid = (float)$sum->fetchColumn();
$balance   = max(0, (float)$order['total_amount'] - $totalPaid);

// Build a simple HTML receipt
ob_start();
?>
    <!DOCTYPE html>
    <html>
    <body style="font-family:Arial,Helvetica,sans-serif;">
    <h2 style="margin:0 0 6px;">Starline Restaurant</h2>
    <div style="font-size:13px; color:#444;">123 Main Street, City • Tel: 011-1234567</div>
    <hr>
    <div style="font-size:14px;">
        <div><strong>Order:</strong> <?= htmlspecialchars($order['order_number']) ?></div>
        <div><strong>Date:</strong> <?= htmlspecialchars($order['created_at']) ?></div>
        <div><strong>Type:</strong> <?= htmlspecialchars(ucfirst(str_replace('_',' ', $order['order_type']))) ?></div>
        <div><strong>Table:</strong> <?= $order['table_name'] ? htmlspecialchars($order['table_name']) : '-' ?></div>
        <div><strong>Customer:</strong>
            <?= $order['customer_name'] ? htmlspecialchars($order['customer_name']) : 'Walk-in' ?>
            <?php if ($order['customer_phone']) echo '(' . htmlspecialchars($order['customer_phone']) . ')'; ?>
        </div>
    </div>
    <table cellpadding="6" cellspacing="0" border="0" style="margin-top:10px; width:100%; font-size:14px;">
        <thead><tr style="background:#f3f4f6;">
            <th align="left">Item</th><th align="center">Qty</th><th align="right">Price</th><th align="right">Total</th>
        </tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
            <tr>
                <td><?= htmlspecialchars($it['name']) ?></td>
                <td align="center"><?= (int)$it['qty'] ?></td>
                <td align="right"><?= number_format((float)$it['unit_price'],2) ?></td>
                <td align="right"><?= number_format((float)$it['total_price'],2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div style="margin-top:8px; font-size:14px;">
        <div>Subtotal: <strong><?= number_format((float)$order['subtotal'],2) ?></strong></div>
        <div>Discount: <strong><?= number_format((float)$order['discount_amount'],2) ?></strong></div>
        <div>Tax: <strong><?= number_format((float)$order['tax_amount'],2) ?></strong></div>
        <div>Service: <strong><?= number_format((float)$order['service_charge'],2) ?></strong></div>
        <div style="font-size:16px;">Grand Total: <strong><?= number_format((float)$order['total_amount'],2) ?></strong></div>
        <div>Paid: <strong><?= number_format($totalPaid,2) ?></strong></div>
        <div>Balance: <strong><?= number_format($balance,2) ?></strong></div>
    </div>
    <hr>
    <div style="font-size:12px; color:#666;">Thanks for dining with us!</div>
    </body>
    </html>
<?php
$html = ob_get_clean();

// ---- Option A: Quick mail() ----
$usePhpMailer = false; // set true to use PHPMailer (recommended)

if (!$usePhpMailer) {
    $subject = 'Your receipt - ' . $order['order_number'];
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8\r\n";
    // You can set a from address configured in php.ini sendmail_from
    $headers .= "From: Starline Restaurant <no-reply@localhost>\r\n";

    @mail($to, $subject, $html, $headers);

    header('Location: order_view.php?id=' . $orderId);
    exit;
}

// ---- Option B: PHPMailer (recommended) ----
/*
require_once __DIR__ . '/../vendor/autoload.php'; // composer require phpmailer/phpmailer

$mail = new PHPMailer\PHPMailer\PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com'; // your SMTP
    $mail->SMTPAuth   = true;
    $mail->Username   = 'your@gmail.com';
    $mail->Password   = 'app-password';
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom('your@gmail.com', 'Starline Restaurant');
    $mail->addAddress($to);

    $mail->isHTML(true);
    $mail->Subject = 'Your receipt - ' . $order['order_number'];
    $mail->Body    = $html;

    $mail->send();
} catch (Exception $e) {
    // Optionally log error: $mail->ErrorInfo
}
header('Location: order_view.php?id=' . $orderId);
exit;
*/
