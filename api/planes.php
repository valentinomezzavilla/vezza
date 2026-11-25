<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource([
    'list' => 'planes_list',
    'get' => 'planes_get',
    'create' => 'planes_create',
    'update' => 'planes_update',
    'delete' => 'planes_delete',
]);
