<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource([
    'list' => 'notas_cliente_list',
    'get' => 'notas_cliente_get',
    'create' => 'notas_cliente_create',
    'update' => 'notas_cliente_update',
    'delete' => 'notas_cliente_delete',
]);
