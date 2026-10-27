<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource([
    'list' => 'suscripciones_cliente_list',
    'get' => 'suscripciones_cliente_get',
    'create' => 'suscripciones_cliente_create',
    'update' => 'suscripciones_cliente_update',
    'delete' => 'suscripciones_cliente_delete',
]);
