<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();

// Comprobante de pago listo para imprimir: /admin/comprobante?n=0001-00000042
$n = is_string($_GET['n'] ?? null) ? $_GET['n'] : '';
try {
    $c = comprobante_pago_por_numero($n);
} catch (HttpError) {
    pagina_no_encontrada('Ese comprobante no existe.', '/admin/clientes', 'Volver a clientes', 'clientes');
}
comprobante_pago_pagina($c, '/admin/clientes/' . $c['cliente_id'], 'Volver a la ficha');
