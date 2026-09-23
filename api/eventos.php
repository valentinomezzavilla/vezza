<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource([
    'list' => 'eventos_list',
    'get' => 'eventos_get',
    'create' => 'eventos_create',
    'update' => 'eventos_update',
    'delete' => 'eventos_delete',
]);
