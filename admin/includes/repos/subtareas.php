<?php
declare(strict_types=1);

function subtareas_list(array $get): array
{
    $f = filtros($get, ['proceso_id' => ['type' => 'int', 'required' => true]]);
    return q_all('SELECT * FROM subtareas WHERE proceso_id = ? ORDER BY orden, id', [$f['proceso_id']]);
}

function subtareas_get(int $id): array
{
    return crud_find('subtareas', $id);
}

function subtareas_create(array $input): array
{
    $datos = validate($input, [
        'proceso_id' => ['type' => 'fk', 'table' => 'procesos', 'required' => true],
        'titulo' => ['type' => 'string', 'required' => true],
    ]);
    $datos['orden'] = siguiente_orden('subtareas', 'proceso_id', $datos['proceso_id']);
    return subtareas_get(crud_insert('subtareas', $datos));
}

function subtareas_update(int $id, array $input): array
{
    $datos = validate($input, [
        'titulo' => ['type' => 'string', 'required' => true],
        'completada' => ['type' => 'bool', 'notnull' => true],
        'antes_de' => ['type' => 'int', 'min' => 1],
    ], true);
    $actual = crud_find('subtareas', $id);
    $mover = array_key_exists('antes_de', $datos);
    $antesDe = $datos['antes_de'] ?? null;
    unset($datos['antes_de']);
    tx(function () use ($id, $datos, $mover, $antesDe, $actual): void {
        crud_update('subtareas', $id, $datos);
        if ($mover) {
            reordenar('subtareas', $id, 'proceso_id', (int)$actual['proceso_id'], $antesDe);
        }
    });
    return subtareas_get($id);
}

function subtareas_delete(int $id): void
{
    crud_delete('subtareas', $id);
}
