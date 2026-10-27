<?php
declare(strict_types=1);

/*
 * Pagos de Mercado Pago: el webhook y el registro de cada pago en su cobro.
 *
 * Regla de oro: un cobro solo pasa a `pagado` después de consultar el pago en la API de Mercado Pago (nunca por lo que
 * trae el cuerpo del aviso), una sola vez, y con el mismo monto y moneda que el cobro. La idempotencia sale de
 * cobros.mp_payment_id (único) y de tomar el cobro con SELECT ... FOR UPDATE dentro de una transacción.
 */

/** La consulta a Mercado Pago falló (red, token, respuesta rara): el webhook responde 500 para que reintente. */
final class MpConsultaFallida extends RuntimeException
{
}

// ---- Mapeo de las respuestas de Mercado Pago ---------------------------------------------------------------------
// Todos los nombres de campo que se leen de un payload de Mercado Pago están en estas funciones. Si el sandbox muestra
// que alguno es distinto, se corrige acá y en ningún otro lado. UNCONFIRMED (docs no legibles al 2026-10-27):
// transaction_amount / currency_id / external_reference / status / id de GET /v1/payments/{id} son los de la API de
// pagos; de qué suscripción viene un pago (preapproval_id, metadata.preapproval_id o
// point_of_interaction.transaction_data.subscription_id) y la forma de /authorized_payments/{id}
// (preapproval_id, status, payment.id) y /preapproval/{id} (status) son la mejor lectura de los docs.

/** @return array{id: string, status: string, monto: ?float, moneda: string, referencia_externa: string, preapproval_id: string} */
function mp_pago_normalizar(array $p): array
{
    $preapproval = $p['preapproval_id']
        ?? $p['metadata']['preapproval_id']
        ?? $p['point_of_interaction']['transaction_data']['subscription_id']
        ?? '';
    return [
        'id' => (string)($p['id'] ?? ''),
        'status' => (string)($p['status'] ?? ''),
        'monto' => isset($p['transaction_amount']) && is_numeric($p['transaction_amount']) ? (float)$p['transaction_amount'] : null,
        'moneda' => strtoupper((string)($p['currency_id'] ?? '')),
        'referencia_externa' => (string)($p['external_reference'] ?? ''),
        'preapproval_id' => (string)$preapproval,
    ];
}

/** @return array{payment_id: string, preapproval_id: string, status: string} */
function mp_recurrente_normalizar(array $r): array
{
    return [
        'payment_id' => (string)($r['payment']['id'] ?? ''),
        'preapproval_id' => (string)($r['preapproval_id'] ?? ''),
        'status' => (string)($r['status'] ?? ''),
    ];
}

/** @return array{status: string} */
function mp_preapproval_normalizar(array $p): array
{
    return ['status' => (string)($p['status'] ?? '')];
}

/** Corre una consulta a Mercado Pago; cualquier falla sale como MpConsultaFallida (el mensaje no lleva credenciales). */
function mp_consultar(callable $consulta): array
{
    try {
        return $consulta();
    } catch (RuntimeException $e) {
        throw new MpConsultaFallida($e->getMessage(), 0, $e);
    }
}

/** @return array{id: string, status: string, monto: ?float, moneda: string, referencia_externa: string, preapproval_id: string} */
function mp_consultar_pago(string $id): array
{
    $pago = mp_pago_normalizar(mp_consultar(fn() => mp_obtener_pago($id)));
    if ($pago['id'] === '') {
        throw new MpConsultaFallida("Mercado Pago devolvió un pago sin id ($id)");
    }
    return $pago;
}

// ---- Webhook -----------------------------------------------------------------------------------------------------

/**
 * Procesa una notificación de Mercado Pago. $headers: x-signature y x-request-id (con o sin el prefijo HTTP_ de
 * $_SERVER); $query: la query string ya decodificada (data.id llega como data_id en $_GET); $cuerpoCrudo: el cuerpo tal
 * cual llegó. 401 si la firma no valida (no toca nada); 200 si se procesó o no había nada que hacer; 500 si falló la
 * consulta a Mercado Pago o algo interno (Mercado Pago reintenta).
 *
 * @return array{http: int, resultado: string}
 */
function mp_webhook_procesar(array $headers, array $query, string $cuerpoCrudo): array
{
    $dato = fn(string ...$claves): string => (string)array_reduce(
        $claves,
        fn(string $acc, string $k): string => $acc !== '' ? $acc : (is_string($query[$k] ?? null) ? trim($query[$k]) : ''),
        ''
    );
    $dataId = $dato('data.id', 'data_id');
    if (!mp_firma_valida($headers, $dataId, (string)env('MP_WEBHOOK_SECRET', ''))) {
        return ['http' => 401, 'resultado' => 'firma_invalida'];
    }
    if ($dataId === '') {
        return ['http' => 200, 'resultado' => 'sin_datos'];
    }

    $cuerpo = json_decode($cuerpoCrudo, true);
    $tipo = $dato('type', 'topic');
    if ($tipo === '' && is_array($cuerpo)) {
        $tipo = is_string($cuerpo['type'] ?? null) ? $cuerpo['type'] : (is_string($cuerpo['topic'] ?? null) ? $cuerpo['topic'] : '');
    }
    $tipo = mb_substr($tipo !== '' ? $tipo : 'desconocido', 0, 60);

    $notificacion = crud_insert('mp_notificaciones', [
        'tipo' => $tipo,
        'recurso_id' => mb_substr($dataId, 0, 100),
        'payload' => mb_substr($cuerpoCrudo, 0, 15000),
    ]);

    try {
        $r = match ($tipo) {
            'payment' => cobro_registrar_pago_mp_detalle(mp_consultar_pago($dataId)),
            'subscription_authorized_payment' => mp_procesar_cobro_recurrente($dataId),
            'subscription_preapproval', 'preapproval' => mp_procesar_preapproval($dataId),
            default => ['resultado' => 'ignorado'],
        };
    } catch (Throwable $e) {
        error_log('[mp-webhook] ' . $e);
        $consulta = $e instanceof MpConsultaFallida;
        mp_notificacion_registrar_error($notificacion, $consulta ? 'No se pudo consultar a Mercado Pago: ' . $e->getMessage() : 'Error interno al procesar el aviso');
        return ['http' => 500, 'resultado' => $consulta ? 'error_consulta' : 'error_interno'];
    }

    db()->prepare('UPDATE mp_notificaciones SET cobro_id = ?, estado_pago = ?, sin_cobro = ?, error = ?, procesada_en = NOW() WHERE id = ?')->execute([
        $r['cobro_id'] ?? null,
        isset($r['estado_pago']) ? mb_substr((string)$r['estado_pago'], 0, 30) : null,
        !empty($r['sin_cobro']) ? 1 : 0,
        isset($r['error']) ? mb_substr((string)$r['error'], 0, 255) : null,
        $notificacion,
    ]);
    return ['http' => 200, 'resultado' => $r['resultado']];
}

function mp_notificacion_registrar_error(int $id, string $error): void
{
    try {
        db()->prepare('UPDATE mp_notificaciones SET error = ? WHERE id = ?')->execute([mb_substr($error, 0, 255), $id]);
    } catch (Throwable $e) {
        error_log('[mp-webhook] No se pudo guardar el error de la notificación ' . $id . ': ' . $e->getMessage());
    }
}

/** Un cobro de una suscripción con débito: se busca su pago y se registra como cualquier otro. */
function mp_procesar_cobro_recurrente(string $id): array
{
    $r = mp_recurrente_normalizar(mp_consultar(fn() => mp_obtener_cobro_recurrente($id)));
    if ($r['payment_id'] === '') {
        return ['resultado' => 'sin_pago'];
    }
    $pago = mp_consultar_pago($r['payment_id']);
    if ($pago['preapproval_id'] === '') {
        $pago['preapproval_id'] = $r['preapproval_id'];
    }
    return cobro_registrar_pago_mp_detalle($pago);
}

/** Una suscripción: solo si Mercado Pago confirma que está autorizada se pasa a débito. */
function mp_procesar_preapproval(string $id): array
{
    $p = mp_preapproval_normalizar(mp_consultar(fn() => mp_obtener_preapproval($id)));
    if (q_val('SELECT 1 FROM suscripciones_cliente WHERE mp_preapproval_id = ?', [$id]) === null) {
        return ['resultado' => 'sin_suscripcion', 'sin_cobro' => true, 'error' => "No hay una suscripción con el preapproval $id"];
    }
    if ($p['status'] !== 'authorized') {
        return ['resultado' => 'sin_cambios'];
    }
    suscripcion_confirmar_preapproval($id);
    return ['resultado' => 'autorizada'];
}

/** Mercado Pago informó que el cliente autorizó el débito: la suscripción pasa a débito automático. Solo si estaba pendiente. */
function suscripcion_confirmar_preapproval(string $preapprovalId): void
{
    db()->prepare(
        "UPDATE suscripciones_cliente SET mp_preapproval_estado = 'autorizada', modalidad = 'debito'
         WHERE mp_preapproval_id = ? AND mp_preapproval_estado = 'pendiente'"
    )->execute([$preapprovalId]);
}

// ---- Registro del pago en el cobro -------------------------------------------------------------------------------

/** Pesos a centavos enteros, para comparar montos sin errores de coma flotante. */
function mp_centavos(string|float|null $monto): ?int
{
    return $monto === null ? null : (int)round((float)$monto * 100);
}

/**
 * Las referencias externas que armamos llevan prefijo: 'cobro-{id}' (preferencia de un cobro) y 'susc-{id}' (suscripción
 * con débito). Sin prefijo los ids de las dos tablas se confundirían entre sí; lo que no tenga prefijo no se reconoce.
 *
 * @return array{0: string, 1: int}|null [tipo, id]
 */
function mp_referencia_interpretar(string $ref): ?array
{
    return preg_match('/^(cobro|susc)-(\d{1,10})$/', $ref, $m) ? [$m[1], (int)$m[2]] : null;
}

/** Un pago es de débito si trae el preapproval de su suscripción o su referencia es de suscripción. */
function mp_pago_es_debito(array $pago): bool
{
    return $pago['preapproval_id'] !== '' || (mp_referencia_interpretar($pago['referencia_externa'])[0] ?? '') === 'susc';
}

/**
 * Busca el cobro al que corresponde un pago ya normalizado (mp_pago_normalizar), sin adivinar:
 * - con preapproval_id (o referencia 'susc-N') es de débito: el cobro pendiente más antiguo de esa suscripción cuyo monto
 *   coincida (el preapproval del pago manda sobre la referencia);
 * - con referencia 'cobro-N' es un pago por link: el cobro N, solo si tiene una preferencia nuestra (mp_preference_id);
 * - cualquier otra cosa (incluida una referencia numérica pelada) no se asigna a nada.
 * $bloquear toma la fila con FOR UPDATE (dentro de una transacción): en el débito eso bloquea la suscripción y sus cobros,
 * y así dos avisos del mismo pago se hacen cola en lugar de pisarse.
 */
function cobro_de_pago_mp(array $pago, bool $bloquear = false): ?array
{
    $ref = mp_referencia_interpretar($pago['referencia_externa']);
    if ($pago['preapproval_id'] !== '' || ($ref[0] ?? '') === 'susc') {
        if ($pago['monto'] === null) {
            return null;
        }
        if ($pago['preapproval_id'] !== '') {
            $donde = 'sc.mp_preapproval_id = ?';
            $params = [$pago['preapproval_id']];
        } else {
            $donde = 'sc.id = ?';
            $params = [$ref[1]];
        }
        $params[] = number_format($pago['monto'], 2, '.', '');
        return q_one(
            "SELECT c.* FROM cobros c
             JOIN suscripciones_cliente sc ON sc.id = c.suscripcion_cliente_id
             WHERE $donde AND c.estado = 'pendiente' AND c.monto = ?
             ORDER BY c.fecha_vencimiento, c.id LIMIT 1" . ($bloquear ? ' FOR UPDATE' : ''),
            $params
        );
    }
    if ($ref !== null && $ref[0] === 'cobro') {
        return q_one('SELECT * FROM cobros WHERE id = ? AND mp_preference_id IS NOT NULL', [$ref[1]]);
    }
    return null;
}

/**
 * El cobro que ya tiene registrado este pago de Mercado Pago (mp_payment_id es único), o null. Con $bloquear es una lectura
 * con candado (LOCK IN SHARE MODE): ve lo último que se confirmó aunque otra transacción lo haya hecho recién.
 */
function cobro_con_pago_mp(string $paymentId, bool $bloquear = false): ?array
{
    return q_one('SELECT * FROM cobros WHERE mp_payment_id = ?' . ($bloquear ? ' LOCK IN SHARE MODE' : ''), [$paymentId]);
}

/**
 * Registra un pago de Mercado Pago ya normalizado en su cobro: 'pagado', 'ya_pagado', 'sin_cobro', 'monto_distinto'
 * o 'no_aprobado'. Para el webhook, con los datos de la notificación, ver cobro_registrar_pago_mp_detalle().
 */
function cobro_registrar_pago_mp(array $pago): string
{
    return cobro_registrar_pago_mp_detalle($pago)['resultado'];
}

/**
 * Como cobro_registrar_pago_mp, y además devuelve lo que se anota en la notificación: cobro_id, estado_pago (el status
 * del pago en Mercado Pago), sin_cobro y error. No manda mails: deja el aviso (confirmación o rechazo) registrado en
 * avisos_pago con ok = 0, en la misma transacción, y lo despacha avisos_despachar_inmediatos() fuera del request.
 *
 * @return array{resultado: string, cobro_id: ?int, estado_pago: string, sin_cobro: bool, error: ?string}
 */
function cobro_registrar_pago_mp_detalle(array $pago): array
{
    $aprobado = $pago['status'] === 'approved';
    [$resultado, $cobro, $error, $sinCobro] = tx(function () use ($pago, $aprobado): array {
        $sinCobro = fn(?array $cobro, string $error): array => ['sin_cobro', $cobro, $error, true];
        // Un pago ya registrado no se vuelve a asignar: si no, el aviso repetido de un débito pagaría el cobro del mes siguiente.
        $registrado = cobro_con_pago_mp($pago['id']);
        if ($registrado !== null) {
            if ($aprobado) {
                aviso_registrar((int)$registrado['id'], 'confirmacion', 0);
            }
            return ['ya_pagado', $registrado, null, false];
        }
        $encontrado = cobro_de_pago_mp($pago, $aprobado);
        // El mismo pago de débito puede llegar dos veces a la vez (Mercado Pago manda payment y
        // subscription_authorized_payment). La lectura de arriba no ve lo que la otra transacción confirma mientras esta
        // esperaba el candado de la suscripción en la consulta anterior: se vuelve a mirar ya con el candado tomado, antes
        // de dar el pago por huérfano o de intentar guardarlo en el cobro del mes siguiente.
        if ($aprobado && mp_pago_es_debito($pago)) {
            $registrado = cobro_con_pago_mp($pago['id'], true);
            if ($registrado !== null) {
                return ['ya_pagado', $registrado, null, false];
            }
        }
        if ($encontrado === null) {
            return $sinCobro(null, 'No se encontró el cobro del pago ' . $pago['id'] . ' (referencia "' . mb_substr($pago['referencia_externa'], 0, 60) . '")');
        }
        if (!$aprobado) {
            if ($pago['status'] === 'rejected' && mp_pago_es_debito($pago)) {
                aviso_registrar((int)$encontrado['id'], 'rechazo', 0);
            }
            return ['no_aprobado', $encontrado, null, false];
        }
        $cobro = q_one('SELECT * FROM cobros WHERE id = ? FOR UPDATE', [(int)$encontrado['id']]);
        if ($cobro === null) {
            return $sinCobro(null, 'No se encontró el cobro del pago ' . $pago['id']);
        }
        if ($cobro['estado'] === 'pagado') {
            // En débito no puede pasar (la consulta con candado solo devuelve cobros pendientes): es un pago por link.
            if ($cobro['mp_payment_id'] === $pago['id']) {
                aviso_registrar((int)$cobro['id'], 'confirmacion', 0);
                return ['ya_pagado', $cobro, null, false];
            }
            return ['ya_pagado', $cobro, "Pago {$pago['id']} recibido sobre un cobro ya pagado: revisá si hay que devolverlo", true];
        }
        if (mp_centavos($cobro['monto']) !== mp_centavos($pago['monto']) || strtoupper((string)$cobro['moneda']) !== $pago['moneda']) {
            return ['monto_distinto', $cobro, sprintf(
                'El monto o la moneda del pago %s (%s %s) no coincide con el cobro (%s %s): no se marcó como pagado',
                $pago['id'], $pago['monto'] === null ? '?' : number_format($pago['monto'], 2, '.', ''), $pago['moneda'] ?: '?',
                $cobro['monto'], $cobro['moneda']
            ), true];
        }
        crud_update('cobros', (int)$cobro['id'], [
            'estado' => 'pagado',
            'fecha_pago' => hoy(),
            'metodo_pago' => mp_pago_es_debito($pago) ? 'Mercado Pago · débito' : 'Mercado Pago · link',
            'mp_payment_id' => $pago['id'],
        ]);
        aviso_registrar((int)$cobro['id'], 'confirmacion', 0);
        return ['pagado', crud_find('cobros', (int)$cobro['id']), null, false];
    });

    return [
        'resultado' => $resultado,
        'cobro_id' => $cobro === null ? null : (int)$cobro['id'],
        'estado_pago' => $pago['status'],
        'sin_cobro' => $sinCobro,
        'error' => $error,
    ];
}
