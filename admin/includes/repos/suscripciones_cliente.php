<?php
declare(strict_types=1);

const MODALIDADES = ['debito', 'transferencia'];
/** Mercado Pago solo cobra el débito automático en esta moneda. */
const MONEDA_MP = 'ARS';
/** Faltando esta cantidad de días o menos para "pago hasta", la suscripción figura como por vencer. */
const COBRO_DIAS_POR_VENCER = 7;

/**
 * Cómo está una suscripción de cobro, calculado desde su "pago hasta" (fecha_proximo_cobro): vencido si ya pasó,
 * por vencer si faltan COBRO_DIAS_POR_VENCER días o menos, al día si falta más, sin_fecha si no tiene. Nunca pausa nada:
 * solo se muestra. $hoy solo sirve para los tests; por defecto es la fecha del reloj.
 *
 * @return array{estado: string, dias: ?int}
 */
function suscripcion_estado_cobro(?string $fechaProximoCobro, ?string $hoy = null): array
{
    if ($fechaProximoCobro === null) {
        return ['estado' => 'sin_fecha', 'dias' => null];
    }
    $dias = dias_entre($hoy ?? hoy(), $fechaProximoCobro);
    $estado = $dias < 0 ? 'vencido' : ($dias <= COBRO_DIAS_POR_VENCER ? 'por_vencer' : 'al_dia');
    return ['estado' => $estado, 'dias' => $dias];
}

/** La fila de la suscripción con su estado de cobro calculado (cobro_estado y cobro_dias). */
function suscripcion_con_estado(array $s): array
{
    $e = suscripcion_estado_cobro($s['fecha_proximo_cobro'] ?? null);
    return $s + ['cobro_estado' => $e['estado'], 'cobro_dias' => $e['dias']];
}

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

/**
 * Lo que se puede editar de una suscripción existente: ni el cliente, ni la modalidad, la pausa o el estado, ni el monto
 * (el monto solo cambia con suscripcion_cliente_ajustar_precio, que avisa al cliente y actualiza Mercado Pago).
 */
function suscripciones_cliente_schema_update(): array
{
    return array_diff_key(suscripciones_cliente_schema(), ['cliente_id' => 1, 'modalidad' => 1, 'monto' => 1]);
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
    return array_map('suscripcion_con_estado', q_all($sql, $params));
}

function suscripciones_cliente_get(int $id): array
{
    $s = q_one(suscripciones_cliente_select() . ' WHERE sc.id = ?', [$id]);
    if ($s === null) {
        throw new HttpError(404, 'No encontrado');
    }
    return suscripcion_con_estado($s);
}

function suscripcion_modalidad_error(string $mensaje): HttpError
{
    return new HttpError(422, 'Revisá los datos marcados', ['modalidad' => $mensaje]);
}

/**
 * El débito automático pasa por Mercado Pago: pide moneda ARS y un mail del cliente (donde Mercado Pago le
 * manda el alta). $suscripcion trae al menos `cliente_id` y `moneda`.
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
        throw suscripcion_modalidad_error('El débito automático solo funciona en ' . MONEDA_MP . '. Dejá esta suscripción en transferencia.');
    }
    $email = q_val('SELECT email FROM clientes WHERE id = ?', [(int)$suscripcion['cliente_id']]);
    if ($email === null || trim((string)$email) === '') {
        throw suscripcion_modalidad_error('El cliente no tiene un mail cargado. Cargalo para poder usar el débito automático.');
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
        // Cambiar de plan conserva el monto y la moneda de la suscripción (el precio es de cada cliente y se ajusta aparte).
        // Un plan en otra moneda dejaría el monto sin sentido, así que se rechaza.
        $plan = crud_find('planes', $datos['plan_id']);
        if ($plan['moneda'] !== $actual['moneda']) {
            throw new HttpError(422, 'Revisá los datos marcados', [
                'plan_id' => 'Ese plan está en ' . $plan['moneda'] . ' y esta suscripción cobra en ' . $actual['moneda'] . '. Creá una suscripción nueva.',
            ]);
        }
    }
    crud_update('suscripciones_cliente', $id, $datos);
    return suscripciones_cliente_get($id);
}

function suscripciones_cliente_delete(int $id): void
{
    $s = crud_find('suscripciones_cliente', $id);
    if ($s['mp_preapproval_id'] !== null && $s['mp_preapproval_estado'] !== 'cancelada') {
        throw new HttpError(409, 'Esta suscripción tiene un débito en Mercado Pago. Cancelala antes de borrarla.');
    }
    crud_delete('suscripciones_cliente', $id);
}
