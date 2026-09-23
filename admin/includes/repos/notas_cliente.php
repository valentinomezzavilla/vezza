<?php
declare(strict_types=1);

function notas_cliente_list(array $get): array
{
    $f = filtros($get, ['cliente_id' => ['type' => 'int', 'required' => true]]);
    return q_all('SELECT * FROM notas_cliente WHERE cliente_id = ? ORDER BY created_at DESC, id DESC', [$f['cliente_id']]);
}

function notas_cliente_get(int $id): array
{
    return crud_find('notas_cliente', $id);
}

function notas_cliente_create(array $input): array
{
    $datos = validate($input, [
        'cliente_id' => ['type' => 'fk', 'table' => 'clientes', 'required' => true],
        'contenido' => ['type' => 'text', 'required' => true],
    ]);
    return notas_cliente_get(crud_insert('notas_cliente', $datos));
}

function notas_cliente_update(int $id, array $input): array
{
    crud_update('notas_cliente', $id, validate($input, ['contenido' => ['type' => 'text', 'required' => true]], true));
    return notas_cliente_get($id);
}

function notas_cliente_delete(int $id): void
{
    crud_delete('notas_cliente', $id);
}
