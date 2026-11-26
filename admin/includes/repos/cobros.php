<?php
declare(strict_types=1);

const COBRO_ESTADOS = ['pendiente', 'pagado'];

function cobros_schema(): array
{
    return [
        'cliente_id' => ['type' => 'fk', 'table' => 'clientes', 'required' => true],
        'proceso_id' => ['type' => 'fk', 'table' => 'procesos'],
        'concepto' => ['type' => 'string'],
        'monto' => ['type' => 'decimal', 'required' => true],
        'moneda' => ['type' => 'currency', 'required' => true],
        'fecha_vencimiento' => ['type' => 'date', 'required' => true],
        'fecha_pago' => ['type' => 'date'],
        'estado' => ['type' => 'enum', 'values' => COBRO_ESTADOS, 'notnull' => true],
        'metodo_pago' => ['type' => 'string', 'max' => 100],
        'comprobante_url' => ['type' => 'url', 'max' => 500],
    ];
}

/** El primer placeholder es la fecha de hoy (para calcular "vencido"). */
function cobros_select(): string
{
    return "SELECT c.*, cl.nombre AS cliente_nombre, p.titulo AS proceso_titulo,
            CASE WHEN c.estado = 'pagado' THEN 'pagado'
                 WHEN c.fecha_vencimiento < ? THEN 'vencido'
                 ELSE 'pendiente' END AS estado_efectivo,
            cp.id AS cp_id, cp.destino AS cp_destino, cp.enviado_en AS cp_enviado_en, cp.error AS cp_error
        FROM cobros c
        JOIN clientes cl ON cl.id = c.cliente_id
        LEFT JOIN procesos p ON p.id = c.proceso_id
        LEFT JOIN comprobantes_pago cp ON cp.cobro_id = c.id AND cp.anulado_en IS NULL";
}

function cobro_publico(array $c): array
{
    $c['tiene_archivo'] = $c['comprobante_archivo'] !== null;
    // El comprobante de pago vigente (no anulado) del cobro, si tiene.
    $c['comprobante_pago'] = $c['cp_id'] === null ? null : [
        'numero' => comprobante_pago_numero((int)$c['cp_id']),
        'destino' => $c['cp_destino'],
        'enviado_en' => $c['cp_enviado_en'],
        'error' => $c['cp_error'],
    ];
    unset($c['comprobante_archivo'], $c['cp_id'], $c['cp_destino'], $c['cp_enviado_en'], $c['cp_error']);
    return $c;
}

function cobros_list(array $get): array
{
    $f = filtros($get, [
        'cliente_id' => ['type' => 'int'],
        'proceso_id' => ['type' => 'int'],
        'estado' => ['type' => 'enum', 'values' => ['pendiente', 'pagado', 'vencido']],
        'desde' => ['type' => 'date'],
        'hasta' => ['type' => 'date'],
    ]);
    [$partes, $params] = where_eq($f, ['cliente_id' => 'c.cliente_id', 'proceso_id' => 'c.proceso_id']);
    $estado = $f['estado'] ?? null;
    if ($estado === 'pagado') {
        $partes[] = "c.estado = 'pagado'";
    } elseif ($estado === 'pendiente') {
        $partes[] = "c.estado = 'pendiente' AND c.fecha_vencimiento >= ?";
        $params[] = hoy();
    } elseif ($estado === 'vencido') {
        $partes[] = "c.estado = 'pendiente' AND c.fecha_vencimiento < ?";
        $params[] = hoy();
    }
    if (isset($f['desde'])) {
        $partes[] = 'COALESCE(c.fecha_pago, c.fecha_vencimiento) >= ?';
        $params[] = $f['desde'];
    }
    if (isset($f['hasta'])) {
        $partes[] = 'COALESCE(c.fecha_pago, c.fecha_vencimiento) <= ?';
        $params[] = $f['hasta'];
    }
    $sql = cobros_select() . sql_where($partes)
        . " ORDER BY c.estado = 'pagado',
                   CASE WHEN c.estado = 'pagado' THEN NULL ELSE c.fecha_vencimiento END,
                   c.fecha_pago DESC, c.id DESC";
    return array_map('cobro_publico', q_all($sql, [hoy(), ...$params]));
}

function cobros_get(int $id): array
{
    $c = q_one(cobros_select() . ' WHERE c.id = ?', [hoy(), $id]);
    if ($c === null) {
        throw new HttpError(404, 'No encontrado');
    }
    return cobro_publico($c);
}

function cobros_normalizar(array $datos, ?array $actual): array
{
    $clienteId = $datos['cliente_id'] ?? ($actual['cliente_id'] ?? null);
    $procesoId = array_key_exists('proceso_id', $datos) ? $datos['proceso_id'] : ($actual['proceso_id'] ?? null);
    validar_proceso_de_cliente($procesoId === null ? null : (int)$procesoId, $clienteId === null ? null : (int)$clienteId);

    $estado = $datos['estado'] ?? ($actual['estado'] ?? 'pendiente');
    if ($estado === 'pagado') {
        $fechaPago = array_key_exists('fecha_pago', $datos) ? $datos['fecha_pago'] : ($actual['fecha_pago'] ?? null);
        if ($fechaPago === null) {
            $datos['fecha_pago'] = hoy();
        }
    } elseif (($datos['estado'] ?? null) === 'pendiente') {
        $datos['fecha_pago'] = null;
    }
    return $datos;
}

function cobros_create(array $input): array
{
    $datos = cobros_normalizar(validate($input, cobros_schema()), null);
    return cobros_get(crud_insert('cobros', $datos));
}

function cobros_update(int $id, array $input): array
{
    $actual = crud_find('cobros', $id);
    $datos = cobros_normalizar(validate($input, cobros_schema(), true), $actual);
    // Si el cobro tenía comprobante y cambia lo que dice (o vuelve a pendiente), el comprobante se anula (y se reemplaza).
    $nuevo = tx(function () use ($id, $datos, $actual): ?int {
        crud_update('cobros', $id, $datos);
        return comprobante_pago_tras_editar_cobro($actual, crud_find('cobros', $id));
    });
    if ($nuevo !== null) {
        comprobante_pago_enviar($nuevo);
    }
    return cobros_get($id);
}

function cobros_delete(int $id): void
{
    $actual = crud_find('cobros', $id);
    tx(function () use ($id): void {
        $vigente = comprobante_pago_del_cobro($id);
        if ($vigente !== null) {
            comprobante_pago_anular((int)$vigente['id'], 'Se borró el pago');
        }
        crud_delete('cobros', $id);
    });
    comprobante_borrar_archivo($actual['comprobante_archivo']);
}
