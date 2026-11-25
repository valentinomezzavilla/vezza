<?php
declare(strict_types=1);

/*
 * Pagos de las suscripciones de cliente. Cada pago (registrado a mano o cobrado por Mercado Pago) deja un cobro `pagado` en
 * `cobros` y adelanta suscripciones_cliente.fecha_proximo_cobro (el "pago hasta"). No hay cobros generados de antemano.
 */

const COBRO_MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/**
 * Suma meses calendario a una fecha 'YYYY-MM-DD'. Si el día no existe en el mes destino se recorta al último día de ese mes,
 * una sola vez: 31 ene + 1 = 28 feb, pero 31 ene + 2 = 31 mar.
 */
function sumar_meses(string $fecha, int $meses): string
{
    [$anio, $mes, $dia] = array_map('intval', explode('-', $fecha));
    $total = $anio * 12 + ($mes - 1) + $meses;
    $anio = intdiv($total, 12);
    $mes = $total % 12 + 1;
    $ultimo = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes)))->format('t');
    return sprintf('%04d-%02d-%02d', $anio, $mes, min($dia, $ultimo));
}

/** "Plan · noviembre 2026", o "Plan · 3 meses desde noviembre 2026" si el pago cubre más de un mes. */
function cobro_concepto_pago(string $plan, string $fecha, int $meses): string
{
    $mes = COBRO_MESES[(int)substr($fecha, 5, 2) - 1];
    $anio = substr($fecha, 0, 4);
    return $meses === 1 ? "$plan · $mes $anio" : "$plan · $meses meses desde $mes $anio";
}

/**
 * Registra un pago de una suscripción: crea el cobro `pagado` y adelanta fecha_proximo_cobro. Es el único camino por el que
 * un pago entra, el manual y el de Mercado Pago, así que la idempotencia vive acá.
 *
 * $pago: meses (int), monto (string decimal), moneda, metodo_pago, mp_payment_id (?string), nota (?string, opcional),
 *   avance: 'max_hoy' avanza desde el mayor entre fecha_proximo_cobro y hoy (pago manual); 'fecha' avanza siempre desde
 *   fecha_proximo_cobro (débito: Mercado Pago cobra por calendario, un aviso tardío no tiene que correr la fecha).
 *
 * La fila de la suscripción se toma con FOR UPDATE como PRIMERA sentencia de la transacción: dos avisos del mismo pago se hacen cola
 * y el segundo ve lo que registró el primero. La búsqueda del mp_payment_id es una lectura común a propósito (sin FOR UPDATE ni
 * LOCK IN SHARE MODE): con candado tomaría un candado de intervalo sobre la clave única y dos pagos distintos se bloquearían entre sí.
 *
 * @return array{resultado: 'registrado'|'repetido', cobro_id: int, fecha_proximo_cobro: ?string}
 */
function suscripcion_registrar_pago(int $suscripcionId, array $pago): array
{
    return tx(function () use ($suscripcionId, $pago): array {
        $s = q_one('SELECT * FROM suscripciones_cliente WHERE id = ? FOR UPDATE', [$suscripcionId]);
        if ($s === null) {
            throw new HttpError(404, 'No encontrado');
        }
        $mpPaymentId = $pago['mp_payment_id'] ?? null;
        if ($mpPaymentId !== null) {
            $ya = q_one('SELECT id FROM cobros WHERE mp_payment_id = ?', [$mpPaymentId]);
            if ($ya !== null) {
                return ['resultado' => 'repetido', 'cobro_id' => (int)$ya['id'], 'fecha_proximo_cobro' => $s['fecha_proximo_cobro']];
            }
        }

        $hoy = hoy();
        $meses = (int)$pago['meses'];
        $actual = $s['fecha_proximo_cobro'];
        $vencimiento = $actual ?? $hoy;
        $desde = $pago['avance'] === 'fecha' ? $vencimiento : max($vencimiento, $hoy);
        $nueva = sumar_meses($desde, $meses);

        $plan = (string)q_val('SELECT nombre FROM planes WHERE id = ?', [(int)$s['plan_id']]);
        $concepto = cobro_concepto_pago($plan, $vencimiento, $meses);
        $nota = trim((string)($pago['nota'] ?? ''));
        if ($nota !== '') {
            $concepto .= ' — ' . $nota;
        }
        $cobroId = crud_insert('cobros', [
            'cliente_id' => (int)$s['cliente_id'],
            'suscripcion_cliente_id' => $suscripcionId,
            'concepto' => mb_substr($concepto, 0, 255),
            'monto' => $pago['monto'],
            'moneda' => $pago['moneda'],
            'fecha_vencimiento' => $vencimiento,
            'fecha_pago' => $hoy,
            'estado' => 'pagado',
            'metodo_pago' => $pago['metodo_pago'],
            'mp_payment_id' => $mpPaymentId,
        ]);
        crud_update('suscripciones_cliente', $suscripcionId, ['fecha_proximo_cobro' => $nueva]);
        return ['resultado' => 'registrado', 'cobro_id' => $cobroId, 'fecha_proximo_cobro' => $nueva];
    });
}

/**
 * "Registrar pago" del admin (transferencia, efectivo...): meses (1 a 24, por defecto 1), monto (por defecto monto × meses),
 * metodo_pago (por defecto Transferencia) y una nota opcional que se agrega al concepto del cobro.
 *
 * @return array{cobro: array, suscripcion: array}
 */
function suscripcion_pago_manual(int $suscripcionId, array $input): array
{
    $s = suscripciones_cliente_get($suscripcionId);
    if ($s['estado'] === 'cancelada') {
        throw new HttpError(409, 'La suscripción está cancelada. Reactivala para registrar pagos.');
    }
    $datos = validate($input, [
        'meses' => ['type' => 'int', 'min' => 1, 'max' => 24],
        'monto' => ['type' => 'decimal'],
        'metodo_pago' => ['type' => 'string', 'max' => 100],
        'nota' => ['type' => 'string', 'max' => 200],
    ], true);
    $meses = $datos['meses'] ?? 1;
    $monto = $datos['monto'] ?? number_format((float)$s['monto'] * $meses, 2, '.', '');
    if ((float)$monto <= 0) {
        throw new HttpError(422, 'Revisá los datos marcados', ['monto' => 'Tiene que ser mayor que cero']);
    }
    $r = suscripcion_registrar_pago($suscripcionId, [
        'meses' => $meses,
        'monto' => $monto,
        'moneda' => $s['moneda'],
        'metodo_pago' => $datos['metodo_pago'] ?? 'Transferencia',
        'mp_payment_id' => null,
        'nota' => $datos['nota'] ?? null,
        'avance' => 'max_hoy',
    ]);
    return ['cobro' => cobros_get($r['cobro_id']), 'suscripcion' => suscripciones_cliente_get($suscripcionId)];
}
