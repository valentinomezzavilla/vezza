<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

// Lo consultan los sistemas de los clientes (módulo "Acceder a VEZZA") con su token: GET /api/sistema-cuenta.
sistema_api_run();
