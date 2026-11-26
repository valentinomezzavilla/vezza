<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

// "Activar débito automático" del portal: POST con csrf y suscripcion_id → al alta del débito en Mercado Pago.
$clienteId = require_cliente();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: /clientes', true, 303);
    exit;
}
try {
    csrf_check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null);
    $id = is_string($_POST['suscripcion_id'] ?? null) && ctype_digit($_POST['suscripcion_id']) ? (int)$_POST['suscripcion_id'] : 0;
    header('Location: ' . portal_activar_debito($clienteId, $id), true, 303);
} catch (Throwable $e) {
    if (!$e instanceof HttpError) {
        error_log('[portal] ' . $e);
    }
    $aviso = $e instanceof HttpError && isset($e->campos['modalidad']) ? 'sin_mail_debito' : 'no_debito';
    header('Location: /clientes?aviso=' . $aviso, true, 303);
}
