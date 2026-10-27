<?php
declare(strict_types=1);

const MODALIDADES = ['debito', 'link', 'transferencia'];
/** Mercado Pago solo cobra (débito y link) en esta moneda. */
const MONEDA_MP = 'ARS';

function suscripciones_cliente_schema(): array
{
    return [
        'cliente_id' => ['type' => 'fk', 'table' => 'clientes', 'required' => true],
        'plan_id' => ['type' => 'fk', 'table' => 'planes', 'required' => true],
        'monto' => ['type' => 'decimal', 'notnull' => true],
        'modalidad' => ['type' => 'enum', 'values' => MODALIDADES],
        'fecha_inicio' => ['type' => 'date', 'notnull' => true],
        'fecha_proximo_cobro' => ['type' => 'date', 'required' => true],
    ];
}

/** Lo que se puede editar de una suscripción existente: ni el cliente ni la modalidad, la pausa o el estado (eso va por las acciones de ciclo). */
function suscripciones_cliente_schema_update(): array
{
    return array_diff_key(suscripciones_cliente_schema(), ['cliente_id' => 1, 'modalidad' => 1]);
}

function suscripciones_cliente_select(): string
{
    return 'SELECT sc.*, cl.nombre AS cliente_nombre, p.nombre AS plan_nombre
        FROM suscripciones_cliente sc
        JOIN clientes cl ON cl.id = sc.cliente_id
        JOIN planes p ON p.id = sc.plan_id';
}

function suscripciones_cliente_list(array $get): array
{
    $f = filtros($get, [
        'cliente_id' => ['type' => 'int'],
        'estado' => ['type' => 'enum', 'values' => ['activa', 'cancelada']],
    ]);
    [$partes, $params] = where_eq($f, ['cliente_id' => 'sc.cliente_id', 'estado' => 'sc.estado']);
    $sql = suscripciones_cliente_select() . sql_where($partes)
        . " ORDER BY sc.estado = 'cancelada', sc.fecha_proximo_cobro, sc.id";
    return q_all($sql, $params);
}

function suscripciones_cliente_get(int $id): array
{
    $s = q_one(suscripciones_cliente_select() . ' WHERE sc.id = ?', [$id]);
    if ($s === null) {
        throw new HttpError(404, 'No encontrado');
    }
    return $s;
}

function suscripcion_modalidad_error(string $mensaje): HttpError
{
    return new HttpError(422, 'Revisá los datos marcados', ['modalidad' => $mensaje]);
}

/**
 * El débito automático y el link de pago pasan por Mercado Pago: piden moneda ARS y un mail del
 * cliente (donde le llega el aviso). $suscripcion trae al menos `cliente_id` y `moneda`.
 */
function suscripcion_validar_modalidad(array $suscripcion, string $modalidad): void
{
    if (!in_array($modalidad, MODALIDADES, true)) {
        throw suscripcion_modalidad_error('Valor no permitido');
    }
    if ($modalidad === 'transferencia') {
        return;
    }
    if ($suscripcion['moneda'] !== MONEDA_MP) {
        throw suscripcion_modalidad_error('El débito automático y el link de pago solo funcionan en ' . MONEDA_MP . '. Dejá esta suscripción en transferencia.');
    }
    $email = q_val('SELECT email FROM clientes WHERE id = ?', [(int)$suscripcion['cliente_id']]);
    if ($email === null || trim((string)$email) === '') {
        throw suscripcion_modalidad_error('El cliente no tiene un mail cargado. Cargalo para poder usar el débito automático o el link de pago.');
    }
}

function suscripciones_cliente_create(array $input): array
{
    $datos = validate($input, suscripciones_cliente_schema());
    $modalidad = $datos['modalidad'] ?? 'transferencia';
    if ($modalidad === 'debito') {
        throw suscripcion_modalidad_error('Pedí el débito automático desde Cambiar modalidad, una vez creada la suscripción.');
    }
    $plan = crud_find('planes', $datos['plan_id']);
    $datos['modalidad'] = $modalidad;
    $datos['moneda'] = $plan['moneda'];
    $datos['monto'] ??= $plan['monto'];
    $datos['fecha_inicio'] ??= hoy();
    suscripcion_validar_modalidad($datos, $modalidad);
    return suscripciones_cliente_get(crud_insert('suscripciones_cliente', $datos));
}

function suscripciones_cliente_update(int $id, array $input): array
{
    $actual = crud_find('suscripciones_cliente', $id);
    $datos = validate($input, suscripciones_cliente_schema_update(), true);
    if (isset($datos['plan_id']) && $datos['plan_id'] !== (int)$actual['plan_id']) {
        // Cambiar de plan toma su moneda y, si no se indica otro, su precio.
        $plan = crud_find('planes', $datos['plan_id']);
        $datos['moneda'] = $plan['moneda'];
        $datos['monto'] ??= $plan['monto'];
        suscripcion_validar_modalidad($datos + $actual, $actual['modalidad']);
    }
    crud_update('suscripciones_cliente', $id, $datos);
    return suscripciones_cliente_get($id);
}

function suscripciones_cliente_delete(int $id): void
{
    crud_delete('suscripciones_cliente', $id);
}
