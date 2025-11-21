<?php
function current_role(): string {
    return $_SESSION['role'] ?? 'cashier';
}

function authorize(array $allowed): void {
    $role = current_role();
    if (!in_array($role, $allowed, true)) {
        header('Location: /restaurant_pos/pages/403.php'); // or show message
        exit;
    }
}
