<?php
declare(strict_types=1);

function gastos_schema(): array
{
    return [
        'suscripcion_id' => ['type' => 'fk', 'table' => 'suscripciones'],
        'concepto' => ['type' => 'string', 'required' => true],
        'categoria' => ['type' => 'string', 'max' => 100],
        'monto' => ['type' => 'decimal', 'required' => true],
        'moneda' => ['type' => 'currency', 'required' => true],
        'fecha' => ['type' => 'date', 'required' => true],
    ];
}

function gastos_list(array $get): array
{
    $f = filtros($get, [
        'desde' => ['type' => 'date'],
        'hasta' => ['type' => 'date'],
        'categoria' => ['type' => 'string', 'max' => 100],
        'suscripcion_id' => ['type' => 'int'],
    ]);
    [$partes, $params] = where_eq($f, ['categoria' => 'g.categoria', 'suscripcion_id' => 'g.suscripcion_id']);
    if (isset($f['desde'])) {
        $partes[] = 'g.fecha >= ?';
        $params[] = $f['desde'];
    }
    if (isset($f['hasta'])) {
        $partes[] = 'g.fecha <= ?';
        $params[] = $f['hasta'];
    }
    $sql = 'SELECT g.*, s.servicio AS suscripcion_servicio
            FROM gastos g LEFT JOIN suscripciones s ON s.id = g.suscripcion_id'
        . sql_where($partes) . ' ORDER BY g.fecha DESC, g.id DESC';
    return q_all($sql, $params);
}

function gastos_get(int $id): array
{
    return crud_find('gastos', $id);
}

function gastos_create(array $input): array
{
    return gastos_get(crud_insert('gastos', validate($input, gastos_schema())));
}

function gastos_update(int $id, array $input): array
{
    crud_update('gastos', $id, validate($input, gastos_schema(), true));
    return gastos_get($id);
}

function gastos_delete(int $id): void
{
    crud_delete('gastos', $id);
}
