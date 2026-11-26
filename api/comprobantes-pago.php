<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

// Comprobantes de pago del admin:
//   GET  /api/comprobantes-pago?cliente_id=N        los del cliente (vigentes y anulados)
//   POST /api/comprobantes-pago {cobro_id}          emitir el de un cobro pagado que no tiene, y mandarlo
//   POST /api/comprobantes-pago/{id}/reenviar       volver a mandarlo por mail (ver la regla de .htaccess)
api_run(function (): void {
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $accion = $_GET['accion'] ?? '';
    if ($accion === 'reenviar') {
        if ($metodo !== 'POST') {
            throw new HttpError(405, 'Método no permitido');
        }
        $id = route_id() ?? throw new HttpError(404, 'No encontrado');
        $aviso = comprobante_pago_enviar($id);
        json_out(200, ['data' => ['comprobante' => comprobante_pago_get($id), 'aviso' => $aviso]]);
        return;
    }
    if ($accion !== '' || route_id() !== null) {
        throw new HttpError(404, 'No encontrado');
    }
    if ($metodo === 'GET') {
        $f = validate($_GET, ['cliente_id' => ['type' => 'fk', 'table' => 'clientes', 'required' => true]]);
        json_out(200, ['data' => comprobantes_pago_de_cliente($f['cliente_id'])]);
        return;
    }
    if ($metodo === 'POST') {
        $in = validate(request_json(), ['cobro_id' => ['type' => 'fk', 'table' => 'cobros', 'required' => true]]);
        json_out(201, ['data' => comprobante_pago_emitir_y_enviar($in['cobro_id'])]);
        return;
    }
    throw new HttpError(405, 'Método no permitido');
});
