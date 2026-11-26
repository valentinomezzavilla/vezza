<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

// Transferencias informadas desde el portal (admin):
//   GET  /api/pagos-informados[?estado=pendiente&cliente_id=N]
//   POST /api/pagos-informados/{id}/confirmar           registra el pago y manda el comprobante
//   POST /api/pagos-informados/{id}/rechazar {motivo}   y le avisa al cliente
//   GET  /api/pagos-informados/{id}/archivo             el comprobante de la transferencia que subió
api_run(function (): void {
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $accion = $_GET['accion'] ?? '';
    $id = route_id();
    if ($id === null && $accion === '') {
        if ($metodo !== 'GET') {
            throw new HttpError(405, 'Método no permitido');
        }
        json_out(200, ['data' => pagos_informados_list($_GET)]);
        return;
    }
    if ($id === null) {
        throw new HttpError(404, 'No encontrado');
    }
    if ($accion === 'archivo' && $metodo === 'GET') {
        [$ruta, $mime, $ext] = pago_informado_archivo($id);
        header_remove('Content-Security-Policy');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($ruta));
        header('Content-Disposition: inline; filename="transferencia-' . $id . '.' . $ext . '"');
        readfile($ruta);
        return;
    }
    if ($metodo !== 'POST') {
        throw new HttpError(405, 'Método no permitido');
    }
    match ($accion) {
        'confirmar' => json_out(200, ['data' => pago_informado_confirmar($id)]),
        'rechazar' => json_out(200, ['data' => pago_informado_rechazar($id, request_json())]),
        default => throw new HttpError(404, 'No encontrado'),
    };
});
