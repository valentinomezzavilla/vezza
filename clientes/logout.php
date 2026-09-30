<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

session_boot_cliente();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: /clientes', true, 303);
    exit;
}
try {
    csrf_check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null);
} catch (HttpError) {
    header('Location: /clientes', true, 303);
    exit;
}
cliente_logout();
header('Location: /clientes/login', true, 303);
