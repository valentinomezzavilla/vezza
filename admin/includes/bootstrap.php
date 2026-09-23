<?php
declare(strict_types=1);

foreach (glob(__DIR__ . '/*.php') as $archivo) {
    if (basename($archivo) !== 'bootstrap.php') {
        require_once $archivo;
    }
}
foreach (glob(__DIR__ . '/repos/*.php') ?: [] as $archivo) {
    require_once $archivo;
}

configurar_errores(PHP_SAPI);
date_default_timezone_set('America/Argentina/Buenos_Aires');

if (!env_loaded()) {
    // Producción: .env un nivel arriba de public_html. Local: raíz del repo.
    env_load_first([dirname(__DIR__, 3) . '/.env', dirname(__DIR__, 2) . '/.env']);
}
