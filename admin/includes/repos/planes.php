<?php
declare(strict_types=1);

function planes_schema(): array
{
    return [
        'nombre' => ['type' => 'string', 'required' => true],
        'descripcion' => ['type' => 'text'],
        'monto' => ['type' => 'decimal', 'required' => true],
        'moneda' => ['type' => 'currency', 'required' => true],
        'frecuencia' => ['type' => 'enum', 'values' => ['mensual', 'anual'], 'required' => true],
        'activo' => ['type' => 'bool', 'notnull' => true],
        'orden' => ['type' => 'int', 'notnull' => true],
    ];
}

function planes_list(array $get): array
{
    return q_all('SELECT * FROM planes ORDER BY orden, id');
}

function planes_get(int $id): array
{
    return crud_find('planes', $id);
}

function planes_create(array $input): array
{
    return planes_get(crud_insert('planes', validate($input, planes_schema())));
}

function planes_update(int $id, array $input): array
{
    crud_update('planes', $id, validate($input, planes_schema(), true));
    return planes_get($id);
}

function planes_delete(int $id): void
{
    crud_find('planes', $id);
    if ((int)q_val('SELECT COUNT(*) FROM suscripciones_cliente WHERE plan_id = ?', [$id]) > 0) {
        throw new HttpError(409, 'Hay suscripciones de clientes que usan este plan. Desactivalo en lugar de borrarlo.');
    }
    crud_delete('planes', $id);
}
