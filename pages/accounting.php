<?php
require_once __DIR__ . '/../auth_check.php';
$page_title = 'Accounting';
require_once __DIR__ . '/../config/db.php';

/* ------------------ Ensure Tables ------------------ */
try {
    // Chart of Accounts
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(20) NOT NULL,
            name VARCHAR(150) NOT NULL,
            type ENUM('asset','liability','equity','income','expense') NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Journal entry header
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS journal_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entry_date DATE NOT NULL,
            description VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Journal lines
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS journal_lines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entry_id INT NOT NULL,
            account_id INT NOT NULL,
            debit DECIMAL(12,2) DEFAULT 0,
            credit DECIMAL(12,2) DEFAULT 0,
            FOREIGN KEY (entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
            FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
        );
    ");
} catch (Exception $e) {
    $err = $e->getMessage();
}

include __DIR__ . '/../layouts/header.php';
?>

<div class="dashboard-grid">
    <a href="chart_of_accounts.php" class="card" style="text-decoration:none;">
        <h3>Chart of Accounts</h3>
        <p style="margin-top:6px;font-size:13px;color:#777;">Manage account categories</p>
    </a>

    <a href="journal.php" class="card" style="text-decoration:none;">
        <h3>Journal Entries</h3>
        <p style="margin-top:6px;font-size:13px;color:#777;">Record expenses, income, adjustments</p>
    </a>

    <a href="cashbook.php" class="card" style="text-decoration:none;">
        <h3>Cashbook</h3>
        <p style="margin-top:6px;font-size:13px;color:#777;">Daily cash in/out</p>
    </a>

    <a href="trial_balance.php" class="card" style="text-decoration:none;">
        <h3>Trial Balance</h3>
        <p style="margin-top:6px;font-size:13px;color:#777;">All account balances</p>
    </a>

    <a href="profit_loss.php" class="card" style="text-decoration:none;">
        <h3>Profit & Loss</h3>
        <p style="margin-top:6px;font-size:13px;color:#777;">Income, expenses, net profit</p>
    </a>

    <a href="balance_sheet.php" class="card" style="text-decoration:none;">
        <h3>Balance Sheet</h3>
        <p style="margin-top:6px;font-size:13px;color:#777;">Assets, liabilities, equity</p>
    </a>
</div>

<?php include __DIR__ . '/../layouts/footer.php'; ?>