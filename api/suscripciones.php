<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

if (($_GET['accion'] ?? '') === 'pagar') {
    api_run(function (): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new HttpError(405, 'Método no permitido');
        }
        $id = route_id() ?? throw new HttpError(404, 'No encontrado');
        json_out(201, ['data' => suscripciones_pagar($id, request_json())]);
    });
    return;
}

api_resource([
    'list' => 'suscripciones_list',
    'get' => 'suscripciones_get',
    'create' => 'suscripciones_create',
    'update' => 'suscripciones_update',
    'delete' => 'suscripciones_delete',
]);
