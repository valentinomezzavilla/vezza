<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource([
    'list' => 'procesos_list',
    'get' => 'procesos_get',
    'create' => 'procesos_create',
    'update' => 'procesos_update',
    'delete' => 'procesos_delete',
]);
