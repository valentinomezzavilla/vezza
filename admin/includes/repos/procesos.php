<?php
declare(strict_types=1);

const PROCESO_ESTADOS = ['por_hacer', 'en_curso', 'en_revision', 'entregado'];
const PRIORIDADES = ['baja', 'media', 'alta'];

const PROCESOS_SELECT = "SELECT p.*, c.nombre AS cliente_nombre,
        (SELECT COUNT(*) FROM subtareas s WHERE s.proceso_id = p.id) AS subtareas_total,
        (SELECT COUNT(*) FROM subtareas s WHERE s.proceso_id = p.id AND s.completada = 1) AS subtareas_hechas
    FROM procesos p JOIN clientes c ON c.id = p.cliente_id";

function procesos_schema(bool $alta): array
{
    $schema = [
        'titulo' => ['type' => 'string', 'required' => true],
        'descripcion' => ['type' => 'text'],
        'estado' => ['type' => 'enum', 'values' => PROCESO_ESTADOS, 'notnull' => true],
        'prioridad' => ['type' => 'enum', 'values' => PRIORIDADES, 'notnull' => true],
        'fecha_inicio' => ['type' => 'date'],
        'fecha_entrega_estimada' => ['type' => 'date'],
    ];
    if ($alta) {
        return ['cliente_id' => ['type' => 'fk', 'table' => 'clientes', 'required' => true]] + $schema;
    }
    $schema['antes_de'] = ['type' => 'int', 'min' => 1];
    return $schema;
}

function procesos_list(array $get): array
{
    $f = filtros($get, [
        'cliente_id' => ['type' => 'int'],
        'estado' => ['type' => 'enum', 'values' => PROCESO_ESTADOS],
    ]);
    [$partes, $params] = where_eq($f, ['cliente_id' => 'p.cliente_id', 'estado' => 'p.estado']);
    $sql = PROCESOS_SELECT . sql_where($partes)
        . " ORDER BY FIELD(p.estado, 'por_hacer', 'en_curso', 'en_revision', 'entregado'), p.orden, p.id";
    return q_all($sql, $params);
}

function procesos_get(int $id): array
{
    $p = q_one(PROCESOS_SELECT . ' WHERE p.id = ?', [$id]);
    if ($p === null) {
        throw new HttpError(404, 'No encontrado');
    }
    return $p;
}

function procesos_validar_fechas(?string $inicio, ?string $entrega): void
{
    if ($inicio !== null && $entrega !== null && $entrega < $inicio) {
        throw new HttpError(422, 'Revisá los datos marcados', ['fecha_entrega_estimada' => 'No puede ser anterior al inicio']);
    }
}

function procesos_create(array $input): array
{
    $datos = validate($input, procesos_schema(true));
    procesos_validar_fechas($datos['fecha_inicio'] ?? null, $datos['fecha_entrega_estimada'] ?? null);
    $datos['orden'] = siguiente_orden('procesos', 'estado', $datos['estado'] ?? 'por_hacer');
    return procesos_get(crud_insert('procesos', $datos));
}

function procesos_update(int $id, array $input): array
{
    $datos = validate($input, procesos_schema(false), true);
    $actual = crud_find('procesos', $id);
    $mover = array_key_exists('antes_de', $datos);
    $antesDe = $datos['antes_de'] ?? null;
    unset($datos['antes_de']);
    procesos_validar_fechas(
        array_key_exists('fecha_inicio', $datos) ? $datos['fecha_inicio'] : $actual['fecha_inicio'],
        array_key_exists('fecha_entrega_estimada', $datos) ? $datos['fecha_entrega_estimada'] : $actual['fecha_entrega_estimada'],
    );
    $estado = $datos['estado'] ?? $actual['estado'];
    tx(function () use ($id, $datos, $mover, $antesDe, $estado, $actual): void {
        crud_update('procesos', $id, $datos);
        if ($mover || $estado !== $actual['estado']) {
            reordenar('procesos', $id, 'estado', $estado, $antesDe);
        }
    });
    return procesos_get($id);
}

function procesos_delete(int $id): void
{
    crud_delete('procesos', $id);
}

function validar_proceso_de_cliente(?int $procesoId, ?int $clienteId): void
{
    if ($procesoId === null) {
        return;
    }
    $duenio = q_val('SELECT cliente_id FROM procesos WHERE id = ?', [$procesoId]);
    if ($duenio === null || (int)$duenio !== (int)$clienteId) {
        throw new HttpError(422, 'Revisá los datos marcados', ['proceso_id' => 'Ese proceso no es de este cliente']);
    }
}
