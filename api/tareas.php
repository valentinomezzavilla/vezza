<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource([
    'list' => 'tareas_list',
    'get' => 'tareas_get',
    'create' => 'tareas_create',
    'update' => 'tareas_update',
    'delete' => 'tareas_delete',
]);
