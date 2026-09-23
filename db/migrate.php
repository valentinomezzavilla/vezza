<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../admin/includes/bootstrap.php';

$aplicadas = migrar(db());
echo $aplicadas
    ? 'Aplicadas: ' . implode(', ', $aplicadas) . PHP_EOL
    : 'No hay migraciones pendientes.' . PHP_EOL;
