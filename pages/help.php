<?php
require_once __DIR__ . '/../auth_check.php';
$page_title = 'Help & Support';
require_once __DIR__ . '/../config/db.php';

// YOUR WHATSAPP BUSINESS NUMBER (no +, just country code + number)
$whatsapp_number = "94770000000"; // <-- CHANGE THIS

$success = '';
$error   = '';

// Try to detect logged-in user id if available
$userId = null;
if (isset($_SESSION)) {
    $userId = $_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? null);
}

/* ---------- HANDLE FORM SUBMIT ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name      = trim($_POST['name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $subject   = trim($_POST['subject'] ?? '');
    $orderRef  = trim($_POST['order_ref'] ?? '');
    $message   = trim($_POST['message'] ?? '');
    $orderId   = null;
    $orderNo   = null;
    $attachRel = null;

    if ($name === '' || $message === '') {
        $error = "Name and message are required.";
    } else {
        try {
            // Try to resolve order by order_number if provided
            if ($orderRef !== '') {
                $oStmt = $pdo->prepare("SELECT id, order_number FROM orders WHERE order_number = ? LIMIT 1");
                $oStmt->execute([$orderRef]);
                $oRow = $oStmt->fetch(PDO::FETCH_ASSOC);
                if ($oRow) {
                    $orderId = (int)$oRow['id'];
                    $orderNo = $oRow['order_number'];
                } else {
                    // Store the typed order reference as order_number even if not found
                    $orderNo = $orderRef;
                }
            }

            // Handle file upload (screenshot / attachment)
            if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
                $fileTmp  = $_FILES['attachment']['tmp_name'];
                $fileName = basename($_FILES['attachment']['name']);
                $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                if (in_array($ext, $allowedExt, true)) {
                    $uploadDir = __DIR__ . '/../uploads/support';
                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0777, true);
                    }

                    $newName = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $fileName);
                    $destAbs = $uploadDir . '/' . $newName;
                    if (move_uploaded_file($fileTmp, $destAbs)) {
                        // Relative path from web root (restaurant_pos)
                        $attachRel = 'uploads/support/' . $newName;
                    }
                }
            }

            // Save to DB
            $stmt = $pdo->prepare("
                INSERT INTO support_messages (
                    user_id, order_id, order_number,
                    name, email, subject, message, attachment,
                    status, created_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'new', NOW())
            ");
            $stmt->execute([
                $userId,
                $orderId,
                $orderNo,
                $name,
                $email ?: null,
                $subject ?: null,
                $message,
                $attachRel
            ]);

            // Build WhatsApp message text
            $waOrder = $orderNo ?: 'Not provided';
            $waAttachmentInfo = $attachRel ? ('Attachment uploaded in POS: ' . $attachRel) : 'No attachment';

            $text = "🔧 *POS Support Request*%0A"
                . "--------------------------%0A"
                . "*Name:* " . rawurlencode($name) . "%0A"
                . "*Email:* " . rawurlencode($email ?: 'Not provided') . "%0A"
                . "*Order:* " . rawurlencode($waOrder) . "%0A"
                . "*Subject:* " . rawurlencode($subject ?: 'Not provided') . "%0A"
                . "*Message:*%0A" . rawurlencode($message) . "%0A"
                . "*File:* " . rawurlencode($waAttachmentInfo) . "%0A"
                . "--------------------------%0A";

            $waUrl = "https://wa.me/" . $whatsapp_number . "?text=" . $text;

            // Redirect to WhatsApp chat
            header("Location: $waUrl");
            exit;
        } catch (Throwable $e) {
            $error = "Could not send support request: " . $e->getMessage();
        }
    }
}

include __DIR__ . '/../layouts/header.php';
?>

<div class="card" style="max-width:900px; margin:auto;">
    <h2>Help &amp; Support</h2>
    <p>If you need help, face an issue, or want to report an error, you can contact us directly.</p>

    <?php if ($error): ?>
        <div style="background:#fee2e2; color:#b91c1c; padding:8px; border-radius:8px; margin:8px 0;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- FAQ SECTION -->
    <h3 style="margin-top:20px;">Frequently Asked Questions</h3>

    <div style="margin-top:10px;">
        <details class="faq">
            <summary>POS not saving orders?</summary>
            <p>Make sure XAMPP (Apache &amp; MySQL) is running and your database connection in config/db.php is correct.</p>
        </details>

        <details class="faq">
            <summary>Why is stock not updating?</summary>
            <p>Ensure purchases and sales pages are correctly calling the auto stock update functions.</p>
        </details>

        <details class="faq">
            <summary>Receipt does not print?</summary>
            <p>Check printer connection, default printer settings, and 80mm template configuration in Settings.</p>
        </details>
    </div>

    <!-- CONTACT SUPPORT FORM -->
    <h3 style="margin-top:25px;">Contact Support</h3>
    <p>Describe the issue below and click <strong>Send &amp; Open WhatsApp</strong>. We will receive this message on our WhatsApp number.</p>

    <form method="post" enctype="multipart/form-data" style="margin-top:15px; max-width:650px;">
        <div class="login-field">
            <label>Your Name</label>
            <input type="text" name="name" required>
        </div>

        <div class="login-field">
            <label>Your Email (optional)</label>
            <input type="email" name="email">
        </div>

        <div class="login-field">
            <label>Related Order Number (optional)</label>
            <input type="text" name="order_ref" placeholder="Eg. ORD-00015">
        </div>

        <div class="login-field">
            <label>Subject (optional)</label>
            <input type="text" name="subject" placeholder="Eg. Order not saving, Printer issue, etc.">
        </div>

        <div class="login-field">
            <label>Message</label>
            <textarea name="message" rows="4" required placeholder="Describe your issue..."></textarea>
        </div>

        <div class="login-field">
            <label>Screenshot / Attachment (optional)</label>
            <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.gif,.pdf">
            <small style="color:#666;">Max a few MB. Allowed: JPG, PNG, GIF, PDF.</small>
        </div>

        <button class="btn-primary" style="margin-top:10px;">Send &amp; Open WhatsApp</button>
    </form>

    <hr style="margin:30px 0;">

    <h4>Quick WhatsApp Chat</h4>
    <p>If you just want to start a chat, click below:</p>
    <a href="https://wa.me/<?= htmlspecialchars($whatsapp_number) ?>?text=Hello%2C%20I%20need%20support%20with%20my%20POS"
        target="_blank"
        class="btn-chip"
        style="background:#25D366; color:white; padding:10px 15px; border-radius:10px;">
        💬 Chat on WhatsApp
    </a>
</div>

<style>
    .faq summary {
        font-size: 15px;
        font-weight: 600;
        padding: 8px;
        background: #f0f0ff;
        border-radius: 8px;
        cursor: pointer;
    }

    .faq p {
        padding: 10px;
        background: #fafafa;
        border-left: 3px solid #7f5dff;
        margin-top: 5px;
    }
</style>

<?php include __DIR__ . '/../layouts/footer.php'; ?>