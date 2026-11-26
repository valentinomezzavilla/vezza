<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

// Lo consultan los sistemas de los clientes para copiar sus comprobantes de pago: GET /api/sistema-comprobantes?desde={cursor}.
sistema_api_run('comprobantes');
