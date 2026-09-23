<?php
declare(strict_types=1);

const FIX_ESTADOS = ['reportado', 'en_progreso', 'resuelto'];

const FIXS_SELECT = "SELECT f.*, c.nombre AS cliente_nombre, p.titulo AS proceso_titulo
    FROM fixs f
    JOIN clientes c ON c.id = f.cliente_id
    LEFT JOIN procesos p ON p.id = f.proceso_id";

function fixs_schema(bool $alta): array
{
    $schema = [
        'cliente_id' => ['type' => 'fk', 'table' => 'clientes', 'required' => true],
        'proceso_id' => ['type' => 'fk', 'table' => 'procesos'],
        'titulo' => ['type' => 'string', 'required' => true],
        'descripcion' => ['type' => 'text'],
        'estado' => ['type' => 'enum', 'values' => FIX_ESTADOS, 'notnull' => true],
        'prioridad' => ['type' => 'enum', 'values' => PRIORIDADES, 'notnull' => true],
        'fecha_reportado' => ['type' => 'date', 'notnull' => true],
        'fecha_resuelto' => ['type' => 'date'],
    ];
    if (!$alta) {
        $schema['antes_de'] = ['type' => 'int', 'min' => 1];
    }
    return $schema;
}

function fixs_list(array $get): array
{
    $f = filtros($get, [
        'cliente_id' => ['type' => 'int'],
        'estado' => ['type' => 'enum', 'values' => FIX_ESTADOS],
    ]);
    [$partes, $params] = where_eq($f, ['cliente_id' => 'f.cliente_id', 'estado' => 'f.estado']);
    $sql = FIXS_SELECT . sql_where($partes)
        . " ORDER BY FIELD(f.estado, 'reportado', 'en_progreso', 'resuelto'), f.orden, f.id";
    return q_all($sql, $params);
}

function fixs_get(int $id): array
{
    $f = q_one(FIXS_SELECT . ' WHERE f.id = ?', [$id]);
    if ($f === null) {
        throw new HttpError(404, 'No encontrado');
    }
    return $f;
}

function fixs_normalizar(array $datos, ?array $actual): array
{
    $clienteId = $datos['cliente_id'] ?? ($actual['cliente_id'] ?? null);
    $procesoId = array_key_exists('proceso_id', $datos) ? $datos['proceso_id'] : ($actual['proceso_id'] ?? null);
    validar_proceso_de_cliente($procesoId === null ? null : (int)$procesoId, $clienteId === null ? null : (int)$clienteId);

    $estado = $datos['estado'] ?? ($actual['estado'] ?? 'reportado');
    if ($estado === 'resuelto') {
        $resuelto = array_key_exists('fecha_resuelto', $datos) ? $datos['fecha_resuelto'] : ($actual['fecha_resuelto'] ?? null);
        $datos['fecha_resuelto'] = $resuelto ?? hoy();
    } else {
        $datos['fecha_resuelto'] = null;
    }
    return $datos;
}

function fixs_create(array $input): array
{
    $datos = fixs_normalizar(validate($input, fixs_schema(true)), null);
    $datos['fecha_reportado'] ??= hoy();
    $datos['orden'] = siguiente_orden('fixs', 'estado', $datos['estado'] ?? 'reportado');
    return fixs_get(crud_insert('fixs', $datos));
}

function fixs_update(int $id, array $input): array
{
    $actual = crud_find('fixs', $id);
    $datos = validate($input, fixs_schema(false), true);
    $mover = array_key_exists('antes_de', $datos);
    $antesDe = $datos['antes_de'] ?? null;
    unset($datos['antes_de']);
    $datos = fixs_normalizar($datos, $actual);
    $estado = $datos['estado'] ?? $actual['estado'];
    tx(function () use ($id, $datos, $mover, $antesDe, $estado, $actual): void {
        crud_update('fixs', $id, $datos);
        if ($mover || $estado !== $actual['estado']) {
            reordenar('fixs', $id, 'estado', $estado, $antesDe);
        }
    });
    return fixs_get($id);
}

function fixs_delete(int $id): void
{
    crud_delete('fixs', $id);
}
