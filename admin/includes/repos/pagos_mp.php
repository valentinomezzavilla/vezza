<?php
declare(strict_types=1);

/*
 * Pagos de Mercado Pago: el webhook y el registro de cada pago en su cobro.
 *
 * Regla de oro: un cobro solo pasa a `pagado` después de consultar el pago en la API de Mercado Pago (nunca por lo que
 * trae el cuerpo del aviso), una sola vez, y con el mismo monto y moneda que el cobro. La idempotencia sale de
 * cobros.mp_payment_id (único) y de tomar el cobro con SELECT ... FOR UPDATE dentro de una transacción.
 */

/** Cuánto puede distanciarse el ts de la firma de la hora actual (segundos). */
const MP_FIRMA_TOLERANCIA_SEG = 300;

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
    if (!mp_firma_valida($headers, $dataId, (string)env('MP_WEBHOOK_SECRET', ''), MP_FIRMA_TOLERANCIA_SEG)) {
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
 * Busca el cobro al que corresponde un pago ya normalizado (mp_pago_normalizar). Un pago con preapproval_id es de
 * débito: va al cobro pendiente más antiguo de esa suscripción cuyo monto coincida (su referencia externa es la
 * suscripción, no un cobro). Los demás son pagos por link y llevan el id del cobro como referencia externa.
 */
function cobro_de_pago_mp(array $pago): ?array
{
    if ($pago['preapproval_id'] !== '') {
        if ($pago['monto'] === null) {
            return null;
        }
        return q_one(
            "SELECT c.* FROM cobros c
             JOIN suscripciones_cliente sc ON sc.id = c.suscripcion_cliente_id
             WHERE sc.mp_preapproval_id = ? AND c.estado = 'pendiente' AND c.monto = ?
             ORDER BY c.fecha_vencimiento, c.id LIMIT 1",
            [$pago['preapproval_id'], number_format($pago['monto'], 2, '.', '')]
        );
    }
    $ref = $pago['referencia_externa'];
    if ($ref === '' || !ctype_digit($ref) || strlen($ref) > 10) {
        return null;
    }
    return q_one('SELECT * FROM cobros WHERE id = ?', [(int)$ref]);
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
 * del pago en Mercado Pago), sin_cobro y error.
 *
 * @return array{resultado: string, cobro_id: ?int, estado_pago: string, sin_cobro: bool, error: ?string}
 */
function cobro_registrar_pago_mp_detalle(array $pago): array
{
    [$resultado, $cobro, $error] = tx(function () use ($pago): array {
        // Un pago ya registrado no se vuelve a asignar: si no, el aviso repetido de un débito pagaría el cobro del mes siguiente.
        $registrado = q_one('SELECT * FROM cobros WHERE mp_payment_id = ?', [$pago['id']]);
        if ($registrado !== null) {
            return ['ya_pagado', $registrado, null];
        }
        $encontrado = cobro_de_pago_mp($pago);
        if ($encontrado === null) {
            return ['sin_cobro', null, 'No se encontró el cobro del pago ' . $pago['id']];
        }
        if ($pago['status'] !== 'approved') {
            return ['no_aprobado', $encontrado, null];
        }
        $cobro = q_one('SELECT * FROM cobros WHERE id = ? FOR UPDATE', [(int)$encontrado['id']]);
        if ($cobro === null) {
            return ['sin_cobro', null, 'No se encontró el cobro del pago ' . $pago['id']];
        }
        if ($cobro['estado'] === 'pagado') {
            return ['ya_pagado', $cobro, 'El cobro ya estaba pagado con otro medio o pago: revisá si el pago ' . $pago['id'] . ' hay que devolverlo'];
        }
        if (mp_centavos($cobro['monto']) !== mp_centavos($pago['monto']) || strtoupper((string)$cobro['moneda']) !== $pago['moneda']) {
            return ['monto_distinto', $cobro, sprintf(
                'El monto o la moneda del pago %s (%s %s) no coincide con el cobro (%s %s): no se marcó como pagado',
                $pago['id'], $pago['monto'] === null ? '?' : number_format($pago['monto'], 2, '.', ''), $pago['moneda'] ?: '?',
                $cobro['monto'], $cobro['moneda']
            )];
        }
        crud_update('cobros', (int)$cobro['id'], [
            'estado' => 'pagado',
            'fecha_pago' => hoy(),
            'metodo_pago' => $pago['preapproval_id'] !== '' ? 'Mercado Pago · débito' : 'Mercado Pago · link',
            'mp_payment_id' => $pago['id'],
        ]);
        return ['pagado', crud_find('cobros', (int)$cobro['id']), null];
    });

    if ($cobro !== null) {
        if ($resultado === 'pagado' || ($resultado === 'ya_pagado' && $cobro['mp_payment_id'] === $pago['id'])) {
            // También en 'ya_pagado' de este mismo pago: si el mail falló la primera vez se reintenta; si ya salió, no hace nada.
            aviso_registrar_y_enviar_mail((int)$cobro['id'], 'confirmacion', 0, 'pago_confirmado', $cobro);
        } elseif ($resultado === 'no_aprobado' && $pago['status'] === 'rejected' && $pago['preapproval_id'] !== '') {
            aviso_registrar_y_enviar_mail((int)$cobro['id'], 'rechazo', 0, 'debito_rechazado', $cobro);
        }
    }

    return [
        'resultado' => $resultado,
        'cobro_id' => $cobro === null ? null : (int)$cobro['id'],
        'estado_pago' => $pago['status'],
        'sin_cobro' => in_array($resultado, ['sin_cobro', 'monto_distinto'], true),
        'error' => $error,
    ];
}
