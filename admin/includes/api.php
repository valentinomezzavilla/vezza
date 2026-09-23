<?php
declare(strict_types=1);

function api_run(callable $fn): void
{
    send_panel_headers();
    try {
        session_boot();
        require_admin_api();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
            csrf_check(is_string($token) ? $token : null);
        }
        $fn();
    } catch (HttpError $e) {
        $cuerpo = ['error' => $e->getMessage()];
        if ($e->campos) {
            $cuerpo['campos'] = $e->campos;
        }
        json_out($e->status, $cuerpo);
    } catch (Throwable $e) {
        error_log('[panel] ' . $e);
        json_out(500, ['error' => 'Error interno']);
    }
}

function api_resource(array $h): void
{
    api_run(function () use ($h): void {
        $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $id = route_id();
        $mapa = $id === null
            ? ['GET' => 'list', 'POST' => 'create']
            : ['GET' => 'get', 'PUT' => 'update', 'DELETE' => 'delete'];
        $accion = $mapa[$metodo] ?? null;
        if ($accion === null || !isset($h[$accion])) {
            throw new HttpError(405, 'Método no permitido');
        }
        switch ($accion) {
            case 'list':
                json_out(200, ['data' => $h['list']($_GET)]);
                break;
            case 'get':
                json_out(200, ['data' => $h['get']($id)]);
                break;
            case 'create':
                json_out(201, ['data' => $h['create'](request_json())]);
                break;
            case 'update':
                json_out(200, ['data' => $h['update']($id, request_json())]);
                break;
            case 'delete':
                $h['delete']($id);
                json_out(204);
                break;
        }
    });
}
