<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

if (($_GET['accion'] ?? '') === 'chequear') {
    api_run(function (): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new HttpError(405, 'Método no permitido');
        }
        json_out(200, ['data' => monitor_chequear_ahora(route_id())]);
    });
    return;
}

api_resource([
    'list' => 'monitor_list',
    'get' => 'monitor_get',
    'create' => 'monitor_create',
    'update' => 'monitor_update',
    'delete' => 'monitor_delete',
]);
