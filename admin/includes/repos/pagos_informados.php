<?php
declare(strict_types=1);

/*
 * Transferencias informadas desde el portal. El cliente ve los datos de la cuenta, transfiere y avisa (fecha, monto, comprobante).
 * Un aviso no es un pago: queda pendiente hasta que el admin lo confirma —ahí se registra el pago por el camino de siempre (corre
 * el vencimiento y emite el comprobante) con la fecha y el monto informados— o lo rechaza con un motivo, que se le manda por mail.
 */

/** Cuántas transferencias puede informar un cliente por día. */
const PORTAL_INFORMES_POR_DIA = 5;

/** Los datos de la cuenta para transferir (del .env). Sin CBU ni alias, el portal no ofrece transferencia. */
function transferencia_datos(): ?array
{
    $d = [
        'titular' => env('TRANSFERENCIA_TITULAR'),
        'cbu' => env('TRANSFERENCIA_CBU'),
        'alias' => env('TRANSFERENCIA_ALIAS'),
        'banco' => env('TRANSFERENCIA_BANCO'),
    ];
    return $d['cbu'] === null && $d['alias'] === null ? null : $d;
}

/** ¿Puede informar una transferencia de esta suscripción? Devuelve el motivo si no, o null si sí. */
function portal_transferencia_motivo(array $s): ?string
{
    if (transferencia_datos() === null) {
        return 'El pago por transferencia todavía no está disponible.';
    }
    if ($s['estado'] !== 'activa') {
        return 'Esta suscripción no está activa.';
    }
    if ($s['debito_activo']) {
        return 'Esta suscripción se cobra sola con débito automático.';
    }
    return null;
}

/**
 * Guarda el aviso de una transferencia del cliente de la sesión. $archivo: ['tmp' => ruta temporal, 'bytes' => int] del comprobante
 * (obligatorio). Una suscripción ajena da 404. Devuelve el id del aviso.
 */
function portal_informar_transferencia(int $clienteId, int $suscripcionId, array $input, ?array $archivo): int
{
    $s = portal_suscripcion($clienteId, $suscripcionId);
    $motivo = portal_transferencia_motivo($s);
    if ($motivo !== null) {
        throw new HttpError(409, $motivo);
    }
    $datos = validate($input, [
        'fecha' => ['type' => 'date', 'required' => true],
        'monto' => ['type' => 'decimal'],
        'nota' => ['type' => 'string', 'max' => 300],
    ], true);
    if (!isset($datos['fecha'])) {
        throw new HttpError(422, 'Revisá los datos marcados', ['fecha' => 'Es obligatorio']);
    }
    if ($datos['fecha'] > hoy()) {
        throw new HttpError(422, 'Revisá los datos marcados', ['fecha' => 'No puede ser una fecha futura']);
    }
    $monto = $datos['monto'] ?? $s['monto'];
    if ((float)$monto <= 0) {
        throw new HttpError(422, 'Revisá los datos marcados', ['monto' => 'Tiene que ser mayor que cero']);
    }
    if ($archivo === null) {
        throw new HttpError(422, 'Revisá los datos marcados', ['archivo' => 'Subí el comprobante de la transferencia']);
    }
    $hoyCuenta = (int)q_val('SELECT COUNT(*) FROM pagos_informados WHERE cliente_id = ? AND creado_en > NOW() - INTERVAL 1 DAY', [$clienteId]);
    if ($hoyCuenta >= PORTAL_INFORMES_POR_DIA) {
        throw new HttpError(429, 'Ya informaste varias transferencias hoy. Si hace falta, escribinos.');
    }
    $nombre = archivo_comprobante_guardar($archivo['tmp'], (int)$archivo['bytes']);
    return crud_insert('pagos_informados', [
        'suscripcion_cliente_id' => $suscripcionId,
        'cliente_id' => $clienteId,
        'fecha' => $datos['fecha'],
        'monto' => $monto,
        'moneda' => $s['moneda'],
        'archivo' => $nombre,
        'nota' => $datos['nota'] ?? null,
    ]);
}

/** Los avisos para el admin (por defecto, los pendientes), con el cliente y el plan. */
function pagos_informados_list(array $get): array
{
    $f = filtros($get, [
        'estado' => ['type' => 'enum', 'values' => ['pendiente', 'confirmado', 'rechazado']],
        'cliente_id' => ['type' => 'int'],
    ]);
    $f['estado'] ??= 'pendiente';
    [$partes, $params] = where_eq($f, ['estado' => 'pi.estado', 'cliente_id' => 'pi.cliente_id']);
    return array_map(function (array $p): array {
        $p['tiene_archivo'] = $p['archivo'] !== null;
        unset($p['archivo']);
        return $p;
    }, q_all(
        'SELECT pi.*, cl.nombre AS cliente_nombre, p.nombre AS plan_nombre
         FROM pagos_informados pi
         JOIN clientes cl ON cl.id = pi.cliente_id
         JOIN suscripciones_cliente sc ON sc.id = pi.suscripcion_cliente_id
         JOIN planes p ON p.id = sc.plan_id' . sql_where($partes) . ' ORDER BY pi.creado_en, pi.id',
        $params
    ));
}

/** Toma el aviso para resolverlo: solo uno pendiente, y una sola vez aunque se toque dos veces el botón. */
function pago_informado_tomar(int $id, string $estado, ?string $motivo = null): array
{
    $p = crud_find('pagos_informados', $id);
    $st = db()->prepare("UPDATE pagos_informados SET estado = ?, motivo_rechazo = ?, resuelto_en = NOW() WHERE id = ? AND estado = 'pendiente'");
    $st->execute([$estado, $motivo, $id]);
    if ($st->rowCount() !== 1) {
        throw new HttpError(409, 'Este aviso ya se resolvió.');
    }
    return $p;
}

/**
 * Confirma la transferencia: registra el pago (un mes, con la fecha y el monto informados y el archivo como comprobante del cobro)
 * y sale el comprobante de pago. Si registrar falla, el aviso vuelve a pendiente.
 *
 * @return array{cobro: array, aviso: ?string}
 */
function pago_informado_confirmar(int $id): array
{
    $p = pago_informado_tomar($id, 'confirmado');
    try {
        $r = suscripcion_registrar_pago((int)$p['suscripcion_cliente_id'], [
            'meses' => 1,
            'monto' => $p['monto'],
            'moneda' => $p['moneda'],
            'metodo_pago' => 'Transferencia',
            'mp_payment_id' => null,
            'fecha_pago' => $p['fecha'],
            'nota' => $p['nota'],
        ]);
    } catch (Throwable $e) {
        db()->prepare("UPDATE pagos_informados SET estado = 'pendiente', resuelto_en = NULL WHERE id = ?")->execute([$id]);
        throw $e;
    }
    db()->prepare('UPDATE pagos_informados SET cobro_id = ? WHERE id = ?')->execute([$r['cobro_id'], $id]);
    if ($p['archivo'] !== null) {
        db()->prepare('UPDATE cobros SET comprobante_archivo = ? WHERE id = ? AND comprobante_archivo IS NULL')->execute([$p['archivo'], $r['cobro_id']]);
    }
    return ['cobro' => cobros_get($r['cobro_id']), 'aviso' => $r['comprobante_aviso']];
}

/**
 * Rechaza el aviso con un motivo y se lo cuenta al cliente por mail (plantilla pago_rechazado). No toca la suscripción.
 *
 * @return array{aviso: string} 'enviado' | 'no_enviado' | 'sin_mail'
 */
function pago_informado_rechazar(int $id, array $input): array
{
    $motivo = validate($input, ['motivo' => ['type' => 'string', 'max' => 200, 'required' => true]])['motivo'];
    $p = pago_informado_tomar($id, 'rechazado', $motivo);
    $destino = comprobante_pago_destino((int)$p['cliente_id']);
    if ($destino === null) {
        return ['aviso' => 'sin_mail'];
    }
    $ok = mail_enviar('pago_rechazado', $destino, [
        'nombre' => (string)q_val('SELECT nombre FROM clientes WHERE id = ?', [(int)$p['cliente_id']]),
        'monto' => comprobante_monto_texto((string)$p['monto'], (string)$p['moneda']),
        'fecha' => fecha_corta_ar((string)$p['fecha']),
        'motivo' => $motivo,
    ]);
    return ['aviso' => $ok ? 'enviado' : 'no_enviado'];
}

/** El archivo que subió el cliente, para que el admin lo vea. */
function pago_informado_archivo(int $id): array
{
    return archivo_comprobante_ruta(crud_find('pagos_informados', $id)['archivo'], 'Este aviso no tiene archivo');
}
