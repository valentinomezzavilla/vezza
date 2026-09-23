<?php
declare(strict_types=1);

const EVENTO_TIPOS = ['reunion', 'llamada', 'recordatorio', 'otro'];

const EVENTOS_SELECT = 'SELECT e.*, c.nombre AS cliente_nombre FROM eventos e LEFT JOIN clientes c ON c.id = e.cliente_id';

function eventos_schema(): array
{
    return [
        'cliente_id' => ['type' => 'fk', 'table' => 'clientes'],
        'titulo' => ['type' => 'string', 'required' => true],
        'descripcion' => ['type' => 'text'],
        'fecha_hora' => ['type' => 'datetime', 'required' => true],
        'duracion_min' => ['type' => 'int', 'min' => 1, 'max' => 1440],
        'tipo' => ['type' => 'enum', 'values' => EVENTO_TIPOS, 'notnull' => true],
    ];
}

function dia_siguiente(string $fecha): string
{
    return (new DateTimeImmutable($fecha))->modify('+1 day')->format('Y-m-d');
}

function eventos_list(array $get): array
{
    $f = filtros($get, [
        'desde' => ['type' => 'date'],
        'hasta' => ['type' => 'date'],
        'cliente_id' => ['type' => 'int'],
    ]);
    [$partes, $params] = where_eq($f, ['cliente_id' => 'e.cliente_id']);
    if (isset($f['desde'])) {
        $partes[] = 'e.fecha_hora >= ?';
        $params[] = $f['desde'] . ' 00:00:00';
    }
    if (isset($f['hasta'])) {
        $partes[] = 'e.fecha_hora < ?';
        $params[] = dia_siguiente($f['hasta']) . ' 00:00:00';
    }
    return q_all(EVENTOS_SELECT . sql_where($partes) . ' ORDER BY e.fecha_hora, e.id', $params);
}

function eventos_get(int $id): array
{
    $e = q_one(EVENTOS_SELECT . ' WHERE e.id = ?', [$id]);
    if ($e === null) {
        throw new HttpError(404, 'No encontrado');
    }
    return $e;
}

function eventos_create(array $input): array
{
    return eventos_get(crud_insert('eventos', validate($input, eventos_schema())));
}

function eventos_update(int $id, array $input): array
{
    crud_update('eventos', $id, validate($input, eventos_schema(), true));
    return eventos_get($id);
}

function eventos_delete(int $id): void
{
    crud_delete('eventos', $id);
}
