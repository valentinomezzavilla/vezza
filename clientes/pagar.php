<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

// "Pagar con Mercado Pago" del portal: POST con csrf y suscripcion_id → al link de pago de Mercado Pago. Cualquier error vuelve
// al inicio con un aviso fijo (el detalle queda en el log).
$clienteId = require_cliente();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: /clientes', true, 303);
    exit;
}
try {
    csrf_check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null);
    $id = is_string($_POST['suscripcion_id'] ?? null) && ctype_digit($_POST['suscripcion_id']) ? (int)$_POST['suscripcion_id'] : 0;
    header('Location: ' . portal_pagar_mp($clienteId, $id), true, 303);
} catch (Throwable $e) {
    if (!$e instanceof HttpError) {
        error_log('[portal] ' . $e);
    }
    header('Location: /clientes?aviso=' . ($e instanceof HttpError && $e->status === 429 ? 'muchos' : 'no_mp'), true, 303);
}
