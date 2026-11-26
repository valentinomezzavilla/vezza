<?php
declare(strict_types=1);

const MODALIDADES = ['debito', 'transferencia'];
/** Mercado Pago solo cobra el débito automático en esta moneda. */
const MONEDA_MP = 'ARS';
/** Faltando esta cantidad de días o menos para "pago hasta", la suscripción figura como por vencer. */
const COBRO_DIAS_POR_VENCER = 7;
/** El día de vencimiento va de 1 a 28: el 28 existe en todos los meses (y el débito de Mercado Pago no acepta más). */
const DIA_VENCIMIENTO_MAX = 28;

/** La misma fecha 'YYYY-MM-DD' con otro día del mes. $dia va de 1 a 28, así que siempre existe. */
function fecha_con_dia(string $fecha, int $dia): string
{
    return substr($fecha, 0, 8) . sprintf('%02d', $dia);
}

/** El primer día $dia igual o posterior a $fecha: '2026-11-20' con día 5 → '2026-12-05'. */
function primer_vencimiento_desde(string $fecha, int $dia): string
{
    $mismoMes = fecha_con_dia($fecha, $dia);
    return $mismoMes >= $fecha ? $mismoMes : fecha_con_dia(sumar_meses(substr($fecha, 0, 8) . '01', 1), $dia);
}

/** El próximo vencimiento tiene que caer en el día de vencimiento de la suscripción. */
function suscripcion_validar_vencimiento(string $fecha, int $dia): void
{
    $diaFecha = (int)substr($fecha, 8, 2);
    if ($diaFecha > DIA_VENCIMIENTO_MAX) {
        throw new HttpError(422, 'Revisá los datos marcados', ['fecha_proximo_cobro' => 'El vencimiento tiene que ser un día del 1 al 28']);
    }
    if ($diaFecha !== $dia) {
        throw new HttpError(422, 'Revisá los datos marcados', ['fecha_proximo_cobro' => "Tiene que caer el día $dia, el día de vencimiento"]);
    }
}

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
        'dia_vencimiento' => ['type' => 'int', 'min' => 1, 'max' => DIA_VENCIMIENTO_MAX, 'notnull' => true],
        'fecha_proximo_cobro' => ['type' => 'date', 'notnull' => true],
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
    // El débito cobra el monto de la suscripción todos los meses: con un plan anual cobraría el precio del año cada mes.
    $frecuencia = q_val('SELECT frecuencia FROM planes WHERE id = ?', [(int)($suscripcion['plan_id'] ?? 0)]);
    if ($frecuencia !== null && $frecuencia !== 'mensual') {
        throw suscripcion_modalidad_error('El débito automático cobra todos los meses y este plan es anual. Dejá esta suscripción en transferencia.');
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
    // Por defecto vence el día del alta (o el del primer vencimiento, si se eligió uno); del 29 al 31, el 28.
    $datos['dia_vencimiento'] ??= min(DIA_VENCIMIENTO_MAX, (int)substr($datos['fecha_proximo_cobro'] ?? $datos['fecha_inicio'], 8, 2));
    $datos['fecha_proximo_cobro'] ??= primer_vencimiento_desde($datos['fecha_inicio'], $datos['dia_vencimiento']);
    suscripcion_validar_vencimiento($datos['fecha_proximo_cobro'], $datos['dia_vencimiento']);
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
    $dia = $datos['dia_vencimiento'] ?? (int)$actual['dia_vencimiento'];
    if ($dia !== (int)$actual['dia_vencimiento']) {
        // Cambiar el día mueve el próximo vencimiento al día nuevo del mismo mes (salvo que también se haya elegido otra fecha).
        $fecha = $datos['fecha_proximo_cobro'] ?? $actual['fecha_proximo_cobro'];
        if ($fecha !== null && $fecha === $actual['fecha_proximo_cobro']) {
            $datos['fecha_proximo_cobro'] = fecha_con_dia($fecha, $dia);
        }
        suscripcion_cambiar_dia_debito($actual, $dia);
    }
    if (isset($datos['fecha_proximo_cobro'])) {
        suscripcion_validar_vencimiento($datos['fecha_proximo_cobro'], $dia);
    }
    crud_update('suscripciones_cliente', $id, $datos);
    return suscripciones_cliente_get($id);
}

/**
 * Con un débito vivo, el día nuevo se cambia también en Mercado Pago (billing_day, *a confirmar*). Si Mercado Pago no lo acepta,
 * no se cambia nada: el admin tiene que pedir el débito de nuevo.
 */
function suscripcion_cambiar_dia_debito(array $s, int $dia): void
{
    if ($s['mp_preapproval_id'] === null || !in_array($s['mp_preapproval_estado'], DEBITO_EN_CURSO, true)) {
        return;
    }
    try {
        mp_actualizar_preapproval((string)$s['mp_preapproval_id'], ['auto_recurring' => ['billing_day' => $dia, 'billing_day_proportional' => false]]);
    } catch (RuntimeException $e) {
        throw suscripcion_mp_error($e);
    }
}

function suscripciones_cliente_delete(int $id): void
{
    $s = crud_find('suscripciones_cliente', $id);
    if ($s['mp_preapproval_id'] !== null && $s['mp_preapproval_estado'] !== 'cancelada') {
        throw new HttpError(409, 'Esta suscripción tiene un débito en Mercado Pago. Cancelala antes de borrarla.');
    }
    crud_delete('suscripciones_cliente', $id);
}
