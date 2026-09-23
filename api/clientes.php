<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource([
    'list' => 'clientes_list',
    'get' => 'clientes_get',
    'create' => 'clientes_create',
    'update' => 'clientes_update',
    'delete' => 'clientes_delete',
]);
