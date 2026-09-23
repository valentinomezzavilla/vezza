<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource([
    'list' => 'fixs_list',
    'get' => 'fixs_get',
    'create' => 'fixs_create',
    'update' => 'fixs_update',
    'delete' => 'fixs_delete',
]);
