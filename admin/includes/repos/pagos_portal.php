<?php
declare(strict_types=1);

/*
 * Pagar desde el portal con Mercado Pago (Checkout Pro). El cliente toca "Pagar con Mercado Pago": se guarda un intento con el
 * monto de su suscripción (lo pone el servidor, nunca el navegador), se crea la preferencia y el cliente paga en Mercado Pago con
 * tarjeta, dinero en cuenta o efectivo. El pago se registra solo cuando llega el aviso `payment`, se lo consulta a la API y está
 * aprobado con ese monto: por el mismo suscripcion_registrar_pago de siempre, que corre el vencimiento un mes y emite el comprobante.
 */

/** Cuántos "Pagar con Mercado Pago" puede iniciar un cliente por hora (cada uno crea una preferencia en Mercado Pago). */
const PORTAL_PAGOS_POR_HORA = 10;
/** Cuánto vale el link de pago: alcanza para pagar en efectivo en Rapipago o Pago Fácil. */
const PORTAL_PAGO_VALIDO_HORAS = 72;

/** ¿Se puede pagar esta suscripción con Mercado Pago desde el portal? Devuelve el motivo si no, o null si sí. */
function portal_pago_mp_motivo(array $s): ?string
{
    if (!mp_configurado()) {
        return 'El pago con Mercado Pago todavía no está disponible.';
    }
    if ($s['estado'] !== 'activa') {
        return 'Esta suscripción no está activa.';
    }
    if ($s['moneda'] !== MONEDA_MP) {
        return 'Mercado Pago solo cobra en pesos: esta suscripción se paga por transferencia.';
    }
    if ($s['debito_activo']) {
        return 'Esta suscripción se cobra sola con débito automático.';
    }
    return null;
}

/** El medio de un pago de Mercado Pago como se lee en el cobro y el comprobante. */
function mp_medio_texto(string $tipo): string
{
    return 'Mercado Pago · ' . match ($tipo) {
        'credit_card' => 'tarjeta de crédito',
        'debit_card', 'prepaid_card' => 'tarjeta de débito',
        'account_money' => 'dinero en cuenta',
        'ticket', 'atm' => 'efectivo',
        'bank_transfer' => 'transferencia',
        default => 'pago',
    };
}

/**
 * Inicia el pago con Mercado Pago de una suscripción del cliente de la sesión y devuelve el link al que hay que mandarlo.
 * Una suscripción ajena da 404.
 */
function portal_pagar_mp(int $clienteId, int $suscripcionId): string
{
    $s = portal_suscripcion($clienteId, $suscripcionId);
    $motivo = portal_pago_mp_motivo($s);
    if ($motivo !== null) {
        throw new HttpError(409, $motivo);
    }
    $recientes = (int)q_val('SELECT COUNT(*) FROM pagos_portal WHERE cliente_id = ? AND creado_en > NOW() - INTERVAL 1 HOUR', [$clienteId]);
    if ($recientes >= PORTAL_PAGOS_POR_HORA) {
        throw new HttpError(429, 'Iniciaste muchos pagos seguidos. Esperá un rato y probá de nuevo.');
    }
    $vence = $s['proximo_vencimiento'] ?? primer_vencimiento_desde(hoy(), $s['dia_vencimiento']);
    $id = crud_insert('pagos_portal', [
        'suscripcion_cliente_id' => $suscripcionId,
        'cliente_id' => $clienteId,
        'monto' => $s['monto'],
        'moneda' => $s['moneda'],
        'vencimiento' => $vence,
    ]);
    $email = comprobante_pago_destino($clienteId);
    $cuerpo = [
        'items' => [[
            'id' => 'susc-' . $suscripcionId,
            'title' => cobro_concepto_pago((string)$s['plan'], $vence, 1),
            'quantity' => 1,
            'unit_price' => (float)$s['monto'],
            'currency_id' => $s['moneda'],
        ]],
        'external_reference' => 'portal-' . $id,
        'notification_url' => portal_url('/api/mp-webhook'),
        'back_urls' => [
            'success' => portal_url('/clientes?aviso=aprobado'),
            'pending' => portal_url('/clientes?aviso=pendiente'),
            'failure' => portal_url('/clientes?aviso=rechazado'),
        ],
        'auto_return' => 'approved',
        'statement_descriptor' => 'VEZZA',
        'expires' => true,
        'expiration_date_to' => (new DateTimeImmutable('+' . PORTAL_PAGO_VALIDO_HORAS . ' hours'))->format('Y-m-d\TH:i:s.vP'),
    ];
    if ($email !== null) {
        $cuerpo['payer'] = ['email' => $email];
    }
    try {
        $r = mp_crear_preferencia($cuerpo);
    } catch (RuntimeException $e) {
        throw suscripcion_mp_error($e);
    }
    crud_update('pagos_portal', $id, ['mp_preference_id' => $r['id']]);
    return $r['init_point'];
}

/**
 * El aviso `payment` de Mercado Pago. Solo se atienden los pagos iniciados desde el portal (referencia portal-{id}); el resto
 * (por ejemplo los cobros del débito, que llegan por subscription_authorized_payment) se ignora. Se consulta el pago a la API y
 * solo uno aprobado, con el monto y la moneda del intento, se registra. Lo que no coincide queda sin_cobro para revisarlo a mano.
 *
 * @return array{resultado: string, cobro_id?: ?int, sin_cobro?: bool, error?: ?string}
 */
function mp_procesar_pago_portal(string $id): array
{
    $pago = mp_consultar_pago($id);
    if (!preg_match('/^portal-(\d{1,10})$/', $pago['referencia_externa'], $m)) {
        return ['resultado' => 'ignorado'];
    }
    $intento = q_one('SELECT * FROM pagos_portal WHERE id = ?', [(int)$m[1]]);
    $sinCobro = fn(string $error): array => ['resultado' => 'sin_cobro', 'cobro_id' => null, 'sin_cobro' => true, 'error' => $error];
    if ($intento === null) {
        return $sinCobro("El pago {$pago['id']} trae la referencia {$pago['referencia_externa']}, que no existe");
    }
    $estado = match ($pago['status']) {
        'approved' => 'aprobado',
        'pending', 'in_process', 'authorized' => 'pendiente',
        'rejected', 'cancelled', 'refunded', 'charged_back' => 'rechazado',
        default => null,
    };
    if ($estado !== 'aprobado') {
        if ($estado !== null && $intento['estado'] !== 'aprobado') {
            crud_update('pagos_portal', (int)$intento['id'], ['estado' => $estado, 'mp_payment_id' => $pago['id'], 'actualizado_en' => date('Y-m-d H:i:s')]);
        }
        return ['resultado' => 'no_aprobado', 'cobro_id' => null, 'sin_cobro' => false, 'error' => null];
    }
    $centavos = fn($x): int => (int)round((float)$x * 100);
    if ($pago['monto'] === null || $centavos($pago['monto']) !== $centavos($intento['monto']) || $pago['moneda'] !== $intento['moneda']) {
        return $sinCobro(sprintf('El pago %s es de %s %s y el intento portal-%d era de %s %s: no se registró',
            $pago['id'], $pago['moneda'] ?: '?', $pago['monto'] ?? '?', $intento['id'], $intento['moneda'], $intento['monto']));
    }
    // El dinero entró: se registra aunque la suscripción se haya pausado o dado de baja mientras tanto.
    $r = suscripcion_registrar_pago((int)$intento['suscripcion_cliente_id'], [
        'meses' => 1,
        'monto' => $intento['monto'],
        'moneda' => $intento['moneda'],
        'metodo_pago' => mp_medio_texto($pago['tipo']),
        'mp_payment_id' => $pago['id'],
    ]);
    crud_update('pagos_portal', (int)$intento['id'], ['estado' => 'aprobado', 'mp_payment_id' => $pago['id'], 'actualizado_en' => date('Y-m-d H:i:s')]);
    return ['resultado' => $r['resultado'], 'cobro_id' => $r['cobro_id'], 'sin_cobro' => false, 'error' => null];
}
