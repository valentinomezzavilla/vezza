<?php
declare(strict_types=1);

const TAREA_ESTADOS = ['pendiente', 'en_curso', 'hecha'];

function tareas_schema(): array
{
    return [
        'titulo' => ['type' => 'string', 'required' => true],
        'descripcion' => ['type' => 'text'],
        'estado' => ['type' => 'enum', 'values' => TAREA_ESTADOS, 'notnull' => true],
        'prioridad' => ['type' => 'enum', 'values' => PRIORIDADES, 'notnull' => true],
        'fecha_vencimiento' => ['type' => 'date'],
    ];
}

function tareas_list(array $get): array
{
    $f = filtros($get, [
        'estado' => ['type' => 'enum', 'values' => TAREA_ESTADOS],
        'abiertas' => ['type' => 'bool'],
    ]);
    [$partes, $params] = where_eq($f, ['estado' => 'estado']);
    if (($f['abiertas'] ?? 0) === 1) {
        $partes[] = "estado <> 'hecha'";
    }
    $sql = 'SELECT * FROM tareas_personales' . sql_where($partes)
        . " ORDER BY estado = 'hecha', fecha_vencimiento IS NULL, fecha_vencimiento,
                   FIELD(prioridad, 'alta', 'media', 'baja'), id";
    return q_all($sql, $params);
}

function tareas_get(int $id): array
{
    return crud_find('tareas_personales', $id);
}

function tareas_create(array $input): array
{
    return tareas_get(crud_insert('tareas_personales', validate($input, tareas_schema())));
}

function tareas_update(int $id, array $input): array
{
    crud_update('tareas_personales', $id, validate($input, tareas_schema(), true));
    return tareas_get($id);
}

function tareas_delete(int $id): void
{
    crud_delete('tareas_personales', $id);
}
