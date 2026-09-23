<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource([
    'list' => 'gastos_list',
    'get' => 'gastos_get',
    'create' => 'gastos_create',
    'update' => 'gastos_update',
    'delete' => 'gastos_delete',
]);
