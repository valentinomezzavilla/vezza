<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

session_boot();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: /admin', true, 303);
    exit;
}
try {
    $token = $_POST['csrf'] ?? null;
    csrf_check(is_string($token) ? $token : null);
} catch (HttpError) {
    header('Location: /admin', true, 303);
    exit;
}
auth_logout();
header('Location: /admin-login', true, 303);
