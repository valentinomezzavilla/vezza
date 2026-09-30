<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_run(function (): void {
    $crudo = $_GET['cliente_id'] ?? null;
    if (!is_string($crudo) || !ctype_digit($crudo) || (int)$crudo < 1) {
        throw new HttpError(404, 'No encontrado');
    }
    $clienteId = (int)$crudo;
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($metodo === 'GET') {
        json_out(200, ['data' => acceso_cliente_get($clienteId)]);
        return;
    }
    if ($metodo !== 'POST') {
        throw new HttpError(405, 'Método no permitido');
    }
    $accion = $_GET['accion'] ?? '';
    if ($accion === 'invitar') {
        json_out(201, ['data' => acceso_cliente_invitar($clienteId, request_json())]);
        return;
    }
    if ($accion === 'activo') {
        $in = validate(request_json(), ['activo' => ['type' => 'bool', 'required' => true]]);
        json_out(200, ['data' => acceso_cliente_set_activo($clienteId, (bool)$in['activo'])]);
        return;
    }
    throw new HttpError(404, 'No encontrado');
});
