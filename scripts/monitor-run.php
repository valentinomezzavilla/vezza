<?php
declare(strict_types=1);

// Lo que corre el cron de Hostinger: chequea los servicios cuyo intervalo ya pasó y avisa si alguno cambió de estado.
// Uso: php scripts/monitor-run.php   (programarlo cada minuto; el intervalo de cada servicio lo decide el panel)
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../admin/includes/bootstrap.php';

$r = monitor_correr_vencidos();
if ($r['omitido']) {
    echo date('c') . " Otro chequeo sigue en curso; se omite esta vuelta.\n";
    exit(0);
}
printf(
    "%s Chequeados: %d · caídos: %d · avisos: %d\n",
    date('c'),
    $r['chequeados'],
    $r['caidos'],
    $r['eventos']
);
