<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

// Acciones sobre una suscripción: POST /api/suscripciones-cliente/{id}/{accion} (ver la regla de .htaccess).
// Cada una es [función($id, $cuerpo), status de éxito].
if (($_GET['accion'] ?? '') !== '') {
    api_run(function (): void {
        $acciones = [
            'pagar' => ['suscripcion_pago_manual', 201],
            'debito' => [fn(int $id, array $cuerpo) => suscripcion_pasar_a_debito($id, ($cuerpo['inicio'] ?? '') !== '' ? (string)$cuerpo['inicio'] : null), 200],
            'pausar' => [fn(int $id) => suscripcion_cliente_pausar($id, true), 200],
            'reanudar' => [fn(int $id) => suscripcion_cliente_pausar($id, false), 200],
            'cancelar' => ['suscripcion_cliente_cancelar', 200],
            'reactivar' => ['suscripcion_cliente_reactivar', 200],
            'sincronizar' => ['suscripcion_sincronizar', 200],
        ];
        $accion = (string)$_GET['accion'];
        if (!isset($acciones[$accion])) {
            throw new HttpError(404, 'No encontrado');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new HttpError(405, 'Método no permitido');
        }
        $id = route_id() ?? throw new HttpError(404, 'No encontrado');
        [$funcion, $status] = $acciones[$accion];
        json_out($status, ['data' => $funcion($id, request_json())]);
    });
    return;
}

api_resource([
    'list' => 'suscripciones_cliente_list',
    'get' => 'suscripciones_cliente_get',
    'create' => 'suscripciones_cliente_create',
    'update' => 'suscripciones_cliente_update',
    'delete' => 'suscripciones_cliente_delete',
]);
