<?php
declare(strict_types=1);

const CLIENTE_ESTADOS = ['activo', 'pausado', 'finalizado'];

function clientes_schema(): array
{
    return [
        'nombre' => ['type' => 'string', 'required' => true],
        'email' => ['type' => 'email'],
        'telefono' => ['type' => 'string', 'max' => 50],
        'rubro' => ['type' => 'string'],
        'estado' => ['type' => 'enum', 'values' => CLIENTE_ESTADOS, 'notnull' => true],
        'fecha_inicio' => ['type' => 'date'],
    ];
}

function clientes_list(array $get): array
{
    $f = filtros($get, [
        'estado' => ['type' => 'enum', 'values' => CLIENTE_ESTADOS],
        'q' => ['type' => 'string', 'max' => 100],
    ]);
    [$partes, $params] = where_eq($f, ['estado' => 'c.estado']);
    if (isset($f['q'])) {
        $partes[] = 'c.nombre LIKE ?';
        $params[] = '%' . addcslashes($f['q'], '%_\\') . '%';
    }
    $sql = "SELECT c.*,
              (SELECT COUNT(*) FROM procesos p WHERE p.cliente_id = c.id AND p.estado <> 'entregado') AS procesos_activos,
              (SELECT COUNT(*) FROM fixs x WHERE x.cliente_id = c.id AND x.estado <> 'resuelto') AS fixs_abiertos
            FROM clientes c" . sql_where($partes) . "
            ORDER BY FIELD(c.estado, 'activo', 'pausado', 'finalizado'), c.nombre, c.id";
    return q_all($sql, $params);
}

function clientes_get(int $id): array
{
    return crud_find('clientes', $id);
}

function clientes_create(array $input): array
{
    return clientes_get(crud_insert('clientes', validate($input, clientes_schema())));
}

function clientes_update(int $id, array $input): array
{
    crud_update('clientes', $id, validate($input, clientes_schema(), true));
    return clientes_get($id);
}

function clientes_delete(int $id): void
{
    crud_find('clientes', $id);
    if ((int)q_val('SELECT COUNT(*) FROM cobros WHERE cliente_id = ?', [$id]) > 0) {
        throw new HttpError(409, 'Este cliente tiene cobros registrados. Marcalo como finalizado en lugar de borrarlo.');
    }
    crud_delete('clientes', $id);
}
