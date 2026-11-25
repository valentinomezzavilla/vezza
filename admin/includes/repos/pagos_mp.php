<?php
declare(strict_types=1);

/*
 * Pagos de Mercado Pago: el webhook de suscripciones, el registro de cada cobro aprobado y Sincronizar.
 *
 * Regla de oro: un pago solo se registra después de consultarlo a la API de Mercado Pago (nunca por lo que trae el cuerpo del
 * aviso), una sola vez (cobros.mp_payment_id es único y suscripcion_registrar_pago toma la fila de la suscripción con FOR UPDATE),
 * y el estado de la suscripción en Mercado Pago se lee siempre de la API, no del aviso. Un aviso solo dice "andá a mirar".
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

/** El estado de una suscripción de Mercado Pago en los nuestros. La baja aparece escrita de las dos maneras en su documentación. */
function mp_estado_preapproval(string $status): ?string
{
    return match ($status) {
        'pending' => 'pendiente',
        'authorized' => 'autorizada',
        'paused' => 'pausada',
        'cancelled', 'canceled' => 'cancelada',
        default => null,
    };
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
 * consulta a Mercado Pago o algo interno (Mercado Pago reintenta). Solo se atienden los temas de suscripciones.
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

    db()->prepare('UPDATE mp_notificaciones SET sin_cobro = ?, error = ?, procesada_en = NOW() WHERE id = ?')->execute([
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

/**
 * Un cobro del débito automático: se consulta el cobro recurrente, se busca su pago y se registra como cualquier otro.
 *
 * @return array{resultado: string, sin_cobro?: bool, error?: ?string}
 */
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
    return mp_pago_registrar($pago);
}

/**
 * Cambió una suscripción de Mercado Pago: se lee su estado real de la API y se aplica. Una que no es nuestra queda marcada
 * para revisar (sin_cobro) y no se consulta.
 *
 * @return array{resultado: string, sin_cobro?: bool, error?: ?string}
 */
function mp_procesar_preapproval(string $id): array
{
    $s = q_one('SELECT id FROM suscripciones_cliente WHERE mp_preapproval_id = ?', [$id]);
    if ($s === null) {
        return ['resultado' => 'sin_suscripcion', 'sin_cobro' => true, 'error' => "No hay una suscripción con el preapproval $id"];
    }
    $p = mp_preapproval_normalizar(mp_consultar(fn() => mp_obtener_preapproval($id)));
    return ['resultado' => suscripcion_aplicar_preapproval((int)$s['id'], $p['status'])];
}

/**
 * Aplica a la suscripción local el estado que Mercado Pago informa para su débito:
 * - autorizada: el débito está vivo, la modalidad pasa a débito y se saca la pausa;
 * - pausada: queda pausada;
 * - cancelada: si estaba autorizada o pausada, el débito se terminó pero la suscripción sigue activa y vuelve a transferencia, y se
 *   avisa al cliente; si nunca se autorizó, solo cambia el estado del débito. Una cancelada no vuelve a vivir: reactivar crea otra;
 * - pendiente: nunca retrocede un estado posterior.
 *
 * @return string 'autorizada' | 'pausada' | 'cancelada' | 'pendiente' | 'sin_cambios' | 'sin_suscripcion'
 */
function suscripcion_aplicar_preapproval(int $id, string $statusMp): string
{
    $nuevo = mp_estado_preapproval($statusMp);
    if ($nuevo === null) {
        return 'sin_cambios';
    }
    $avisar = false;
    $resultado = tx(function () use ($id, $nuevo, &$avisar): string {
        $s = q_one('SELECT * FROM suscripciones_cliente WHERE id = ? FOR UPDATE', [$id]);
        if ($s === null) {
            return 'sin_suscripcion';
        }
        $actual = $s['mp_preapproval_estado'];
        if ($actual === $nuevo || $actual === 'cancelada' || ($nuevo === 'pendiente' && $actual !== null)) {
            return 'sin_cambios';
        }
        $cambios = match ($nuevo) {
            'autorizada' => ['mp_preapproval_estado' => 'autorizada', 'modalidad' => 'debito', 'pausada' => 0],
            'pausada' => ['mp_preapproval_estado' => 'pausada', 'pausada' => 1],
            'pendiente' => ['mp_preapproval_estado' => 'pendiente'],
            'cancelada' => in_array($actual, ['autorizada', 'pausada'], true)
                ? ['mp_preapproval_estado' => 'cancelada', 'modalidad' => 'transferencia', 'pausada' => 0]
                : ['mp_preapproval_estado' => 'cancelada'],
        };
        $avisar = $nuevo === 'cancelada' && in_array($actual, ['autorizada', 'pausada'], true);
        crud_update('suscripciones_cliente', $id, $cambios);
        return $nuevo;
    });
    if ($avisar) {
        suscripcion_avisar_baja($id, 'debito');
    }
    return $resultado;
}

// ---- Registro del cobro --------------------------------------------------------------------------------------------

/**
 * La suscripción a la que corresponde un pago ya normalizado, sin adivinar: por el preapproval del pago y, si ese no es
 * nuestro (por ejemplo un cobro del preapproval anterior a una reactivación), por la referencia 'susc-{id}' que arma
 * mp_crear_preapproval. Cualquier otra cosa no se asigna a nada.
 *
 * @return array{id: int, moneda: string}|null
 */
function mp_suscripcion_de_pago(array $pago): ?array
{
    $s = null;
    if ($pago['preapproval_id'] !== '') {
        $s = q_one('SELECT id, moneda FROM suscripciones_cliente WHERE mp_preapproval_id = ?', [$pago['preapproval_id']]);
    }
    if ($s === null && preg_match('/^susc-(\d{1,10})$/', $pago['referencia_externa'], $m)) {
        $s = q_one('SELECT id, moneda FROM suscripciones_cliente WHERE id = ? AND mp_preapproval_id IS NOT NULL', [(int)$m[1]]);
    }
    return $s === null ? null : ['id' => (int)$s['id'], 'moneda' => (string)$s['moneda']];
}

/**
 * Registra un pago de Mercado Pago ya normalizado (mp_pago_normalizar). Solo uno aprobado avanza algo. El dinero entró, así
 * que una suscripción cancelada o pausada igual se registra; lo que no se puede asignar (suscripción desconocida, sin monto o en otra
 * moneda) no se descarta ni se inventa: queda marcado sin_cobro para revisarlo a mano.
 *
 * @return array{resultado: 'registrado'|'repetido'|'no_aprobado'|'sin_cobro', cobro_id: ?int, sin_cobro: bool, error: ?string}
 */
function mp_pago_registrar(array $pago): array
{
    $sinCobro = fn(string $error): array => ['resultado' => 'sin_cobro', 'cobro_id' => null, 'sin_cobro' => true, 'error' => $error];
    if ($pago['status'] !== 'approved') {
        return ['resultado' => 'no_aprobado', 'cobro_id' => null, 'sin_cobro' => false, 'error' => null];
    }
    $s = mp_suscripcion_de_pago($pago);
    if ($s === null) {
        return $sinCobro(sprintf(
            'No se encontró la suscripción del pago %s (preapproval "%s", referencia "%s")',
            $pago['id'], mb_substr($pago['preapproval_id'], 0, 40), mb_substr($pago['referencia_externa'], 0, 60)
        ));
    }
    if ($pago['monto'] === null || $pago['monto'] <= 0) {
        return $sinCobro("El pago {$pago['id']} no trae un monto válido");
    }
    if ($pago['moneda'] !== strtoupper($s['moneda'])) {
        return $sinCobro(sprintf(
            'El pago %s está en %s pero la suscripción cobra en %s: no se registró',
            $pago['id'], $pago['moneda'] ?: '?', $s['moneda']
        ));
    }
    $r = suscripcion_registrar_pago($s['id'], [
        'meses' => 1,
        'monto' => number_format($pago['monto'], 2, '.', ''),
        'moneda' => $s['moneda'],
        'metodo_pago' => 'Mercado Pago · débito',
        'mp_payment_id' => $pago['id'],
        'avance' => 'fecha',
    ]);
    return ['resultado' => $r['resultado'], 'cobro_id' => $r['cobro_id'], 'sin_cobro' => false, 'error' => null];
}

// ---- Sincronizar ---------------------------------------------------------------------------------------------------

/**
 * Pone al día una suscripción con su débito: lee su estado en Mercado Pago y registra los pagos aprobados que falten (los
 * busca por la referencia 'susc-{id}', del más viejo al más nuevo). Hace lo mismo que el webhook, por el mismo camino, así que
 * se puede correr las veces que haga falta. Reemplaza a una reconciliación por cron.
 *
 * @return array{estado: ?string, pagos: int} pagos = los que esta corrida registró (no los que ya estaban)
 */
function suscripcion_sincronizar(int $id): array
{
    $s = suscripciones_cliente_get($id);
    if ($s['mp_preapproval_id'] === null) {
        throw new HttpError(409, 'Esta suscripción no tiene un débito en Mercado Pago.');
    }
    try {
        $remoto = mp_preapproval_normalizar(mp_obtener_preapproval((string)$s['mp_preapproval_id']));
        $encontrados = mp_buscar_pagos('susc-' . $id);
    } catch (RuntimeException $e) {
        throw suscripcion_mp_error($e);
    }
    suscripcion_aplicar_preapproval($id, $remoto['status']);

    $registrados = 0;
    foreach (array_reverse($encontrados) as $crudo) {
        $pago = mp_pago_normalizar(is_array($crudo) ? $crudo : []);
        if ($pago['id'] !== '' && mp_pago_registrar($pago)['resultado'] === 'registrado') {
            $registrados++;
        }
    }
    return ['estado' => suscripciones_cliente_get($id)['mp_preapproval_estado'], 'pagos' => $registrados];
}
