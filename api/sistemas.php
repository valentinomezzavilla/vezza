<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

// Generar un token nuevo: POST /api/sistemas/{id}/token (ver la regla de .htaccess). El anterior deja de funcionar.
if (($_GET['accion'] ?? '') === 'token') {
    api_run(function (): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new HttpError(405, 'Método no permitido');
        }
        $id = route_id() ?? throw new HttpError(404, 'No encontrado');
        json_out(200, ['data' => sistema_token_generar($id)]);
    });
    return;
}

api_resource([
    'list' => 'sistemas_list',
    'get' => 'sistemas_get',
    'create' => 'sistemas_create',
    'update' => 'sistemas_update',
    'delete' => 'sistemas_delete',
]);
