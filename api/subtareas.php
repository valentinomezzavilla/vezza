<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource([
    'list' => 'subtareas_list',
    'get' => 'subtareas_get',
    'create' => 'subtareas_create',
    'update' => 'subtareas_update',
    'delete' => 'subtareas_delete',
]);
