<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource([
    'list' => 'cobros_list',
    'get' => 'cobros_get',
    'create' => 'cobros_create',
    'update' => 'cobros_update',
    'delete' => 'cobros_delete',
]);
