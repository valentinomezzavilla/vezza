<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

// Comprobante de pago del cliente, listo para imprimir: /clientes/comprobante?n=0001-00000042. Uno de otro cliente da 404.
$clienteId = require_cliente();
$n = is_string($_GET['n'] ?? null) ? $_GET['n'] : '';
try {
    $c = comprobante_pago_por_numero($n, $clienteId);
} catch (HttpError) {
    http_response_code(404);
    portal_inicio('No encontrado');
    echo '<main id="main" class="portal-main"><section class="card"><p>Ese comprobante no existe. <a href="/clientes">Volver al inicio</a></p></section></main>';
    portal_fin();
    exit;
}
comprobante_pago_pagina($c, '/clientes', 'Volver al inicio');
