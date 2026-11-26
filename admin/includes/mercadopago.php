<?php
declare(strict_types=1);

/*
 * Cliente mínimo de la API de Mercado Pago (sin SDK). Credenciales por env: MP_ACCESS_TOKEN
 * (API) y MP_WEBHOOK_SECRET (firma de las notificaciones; lo lee quien llama a mp_firma_valida).
 *
 * Estado de la verificación contra la documentación (consultada el 2026-10-27, mercadopago.com.ar/developers).
 * Lo que dice "CONFIRMADO" salió de una página de los docs; "UNCONFIRMED" es lo que no se pudo leer
 * (los endpoints de referencia de la API devolvían 404 al bajarlos) y hay que probar contra el sandbox.
 */

final class MercadoPago
{
    /** @var (callable(string, string, ?array): array)|null Transporte de prueba: recibe (método, ruta, cuerpo) y devuelve la respuesta decodificada. */
    public static $transporte = null;
}

/**
 * Una llamada a la API. Usa el transporte de prueba si existe (sin token); si no, HTTP real a api.mercadopago.com.
 * Lanza RuntimeException ante token ausente, red caída o respuesta no 2xx (el mensaje nunca incluye el token).
 *
 * @param string $ruta Ruta con barra inicial y, si hace falta, query string (ej. '/v1/payments/search?external_reference=1').
 */
function mp_request(string $metodo, string $ruta, ?array $cuerpo = null): array
{
    if (MercadoPago::$transporte !== null) {
        return (MercadoPago::$transporte)($metodo, $ruta, $cuerpo);
    }
    $token = env('MP_ACCESS_TOKEN');
    if ($token === null) {
        throw new RuntimeException('MP_ACCESS_TOKEN no está configurado');
    }
    return mp_http($metodo, $ruta, $cuerpo, $token);
}

function mp_http(string $metodo, string $ruta, ?array $cuerpo, string $token): array
{
    $cabeceras = "Authorization: Bearer $token\r\nAccept: application/json\r\n";
    $http = ['method' => $metodo, 'timeout' => 25, 'ignore_errors' => true];
    if ($cuerpo !== null) {
        $cabeceras .= "Content-Type: application/json\r\n";
        $http['content'] = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    $http['header'] = $cabeceras;

    $respuesta = @file_get_contents('https://api.mercadopago.com' . $ruta, false, stream_context_create(['http' => $http]));
    if ($respuesta === false) {
        throw new RuntimeException("Mercado Pago no respondió ($metodo $ruta)");
    }
    $lineas = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    if (!preg_match('#^HTTP/\S+\s+(\d{3})#', (string)($lineas[0] ?? ''), $m) || $m[1][0] !== '2') {
        throw new RuntimeException("Mercado Pago respondió " . ($m[1] ?? '?') . " a $metodo $ruta: " . mb_substr($respuesta, 0, 300));
    }
    if (trim($respuesta) === '') {
        return [];
    }
    try {
        $datos = json_decode($respuesta, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException("Mercado Pago devolvió algo que no es JSON ($metodo $ruta)", 0, $e);
    }
    return is_array($datos) ? $datos : [];
}

/** @return array{id: string, init_point: string} */
function mp_id_y_link(array $r, string $que): array
{
    if (!isset($r['id'], $r['init_point'])) {
        throw new RuntimeException("Mercado Pago no devolvió id e init_point al crear $que");
    }
    return ['id' => (string)$r['id'], 'init_point' => (string)$r['init_point']];
}

/**
 * Un pago por id: GET /v1/payments/{id}. Interesan id, status, external_reference, transaction_amount (UNCONFIRMED:
 * no se pudo leer la referencia; los nombres son los que usa la API de pagos).
 */
function mp_obtener_pago(string $id): array
{
    return mp_request('GET', '/v1/payments/' . rawurlencode($id));
}

/**
 * Los pagos de una referencia externa, del más nuevo al más viejo (para la reconciliación).
 * GET /v1/payments/search?external_reference=... (CONFIRMADO: endpoint, external_reference, sort, criteria, limit
 * máx. 50 con 30 por defecto, offset; respuesta con paging.{total,limit,offset} y results[]). Sin paginar: una
 * referencia de VEZZA tiene pocos pagos, muy por debajo de 50.
 *
 * @return list<array>
 */
function mp_buscar_pagos(string $referenciaExterna): array
{
    $query = http_build_query(['external_reference' => $referenciaExterna, 'sort' => 'date_created', 'criteria' => 'desc', 'limit' => 50]);
    $r = mp_request('GET', '/v1/payments/search?' . $query);
    return array_values($r['results'] ?? []);
}

/**
 * Crea una suscripción sin plan asociado y con pago pendiente: el cliente la autoriza entrando a init_point.
 * POST /preapproval. $suscripcion: id, monto, moneda (por defecto ARS), plan_nombre (opcional, va de reason).
 * $inicio: fecha y hora ISO 8601 con zona (ver mp_inicio_iso), se manda tal cual; sin $inicio no se manda start_date y
 * Mercado Pago cobra apenas el cliente autoriza.
 *
 * Campos enviados (CONFIRMADOS en el ejemplo de "suscripción sin plan, pago pendiente" y en la referencia de
 * creación): reason, external_reference, payer_email, back_url, status='pending', auto_recurring.{frequency,
 * frequency_type='months', start_date, transaction_amount, currency_id}. UNCONFIRMED: la respuesta (id, init_point;
 * no hay ejemplo de respuesta en lo leído) y que start_date acepte una fecha futura con ese formato exacto. No se
 * manda notification_url: la URL de avisos de las suscripciones se configura en el panel de la aplicación.
 * back_url es la home: la landing no se toca y VEZZA no tiene una pantalla de "volver" para el cliente.
 *
 * @return array{id: string, init_point: string}
 */
function mp_crear_preapproval(array $suscripcion, string $emailCliente, ?string $inicio = null): array
{
    $recurrente = ['frequency' => 1, 'frequency_type' => 'months'];
    if ($inicio !== null) {
        $recurrente['start_date'] = $inicio;
    }
    // Vencimiento fijo: Mercado Pago cobra siempre el día de vencimiento de la suscripción, sin prorratear el primer mes.
    // *A confirmar* los dos nombres (auto_recurring.billing_day, 1 a 28, y billing_day_proportional).
    if (isset($suscripcion['dia_vencimiento'])) {
        $recurrente['billing_day'] = (int)$suscripcion['dia_vencimiento'];
        $recurrente['billing_day_proportional'] = false;
    }
    $recurrente += [
        'transaction_amount' => (float)$suscripcion['monto'],
        'currency_id' => (string)($suscripcion['moneda'] ?? 'ARS') ?: 'ARS',
    ];
    $r = mp_request('POST', '/preapproval', [
        'reason' => (string)($suscripcion['plan_nombre'] ?? '') !== '' ? (string)$suscripcion['plan_nombre'] : 'Suscripción VEZZA',
        'external_reference' => 'susc-' . $suscripcion['id'],
        'payer_email' => $emailCliente,
        'back_url' => portal_url('/'),
        'status' => 'pending',
        'auto_recurring' => $recurrente,
    ]);
    return mp_id_y_link($r, 'la suscripción');
}

/**
 * Crea una preferencia de Checkout Pro (pago único): POST /checkout/preferences. *A confirmar* contra la documentación vigente
 * (no se pudo leer al escribirlo): items[].title/quantity/unit_price/currency_id, external_reference, notification_url,
 * back_urls, auto_return, expires/expiration_date_to y que la respuesta traiga init_point.
 *
 * @return array{id: string, init_point: string}
 */
function mp_crear_preferencia(array $cuerpo): array
{
    return mp_id_y_link(mp_request('POST', '/checkout/preferences', $cuerpo), 'el pago');
}

/** Una fecha 'YYYY-MM-DD' como la pide start_date: al mediodía, hora de Argentina. */
function mp_inicio_iso(string $fecha): string
{
    return $fecha . 'T12:00:00.000-03:00';
}

/** ¿Está encendido el débito automático? Hay token de Mercado Pago (o, en los tests, un transporte falso). */
function mp_configurado(): bool
{
    return MercadoPago::$transporte !== null || env('MP_ACCESS_TOKEN') !== null;
}

/** Una suscripción por id: GET /preapproval/{id}. Interesan id, status, auto_recurring, external_reference (UNCONFIRMED: no se pudo leer la referencia). */
function mp_obtener_preapproval(string $id): array
{
    return mp_request('GET', '/preapproval/' . rawurlencode($id));
}

/**
 * Cambia datos de una suscripción: PUT /preapproval/{id} con solo lo que cambia. Para el monto:
 * ['auto_recurring' => ['transaction_amount' => float, 'currency_id' => 'ARS']] (CONFIRMADO en la guía de
 * administración de suscripciones).
 */
function mp_actualizar_preapproval(string $id, array $cambios): array
{
    return mp_request('PUT', '/preapproval/' . rawurlencode($id), $cambios);
}

/** Pausa: PUT status='paused' (CONFIRMADO). */
function mp_pausar_preapproval(string $id): void
{
    mp_actualizar_preapproval($id, ['status' => 'paused']);
}

/**
 * Reactiva una suscripción pausada: PUT status='authorized'. UNCONFIRMED: la guía solo dice que se reactiva con un
 * PUT "con los parámetros necesarios" sin nombrar el valor; 'authorized' es el estado de una suscripción activa.
 */
function mp_reanudar_preapproval(string $id): void
{
    mp_actualizar_preapproval($id, ['status' => 'authorized']);
}

/**
 * Cancela: PUT status='cancelled'. UNCONFIRMED la ortografía: la guía de administración escribe `canceled` (una sola
 * l) en la prosa, pero la API de Mercado Pago devuelve y documenta 'cancelled' en los estados de pagos y
 * suscripciones. Verificar en el sandbox antes de salir a producción.
 */
function mp_cancelar_preapproval(string $id): void
{
    mp_actualizar_preapproval($id, ['status' => 'cancelled']);
}

/**
 * Verifica la firma de una notificación (webhook). CONFIRMADO en la guía de webhooks de Mercado Pago:
 * el header x-signature trae "ts=<timestamp>,v1=<hash>"; el hash es HMAC-SHA256 en hexadecimal, con la clave secreta
 * de la aplicación, del manifiesto "id:<data.id>;request-id:<x-request-id>;ts:<ts>;". Si un valor no vino en la
 * notificación se omite esa parte del manifiesto. Un data.id alfanumérico se pasa a minúsculas antes de armar el manifiesto
 * (regla de la guía, UNCONFIRMED en la sección "Sin SDKs" que no se pudo leer); los pagos tienen id numérico, así que no
 * afecta a los cobros.
 *
 * Con $toleranciaSeg la firma además tiene que ser reciente: el ts (segundos o milisegundos) no puede distanciarse de
 * $ahora (por defecto, la hora actual) más que esa cantidad de segundos. Sin ella no se mira la hora.
 *
 * @param array<string, string> $headers Headers de la request; los nombres no distinguen mayúsculas ni usan el prefijo HTTP_ de $_SERVER.
 * @param string $dataId El data.id de la query string de la notificación.
 */
function mp_firma_valida(array $headers, string $dataId, string $secreto, ?int $toleranciaSeg = null, ?int $ahora = null): bool
{
    if ($secreto === '') {
        return false;
    }
    if (preg_match('/^[A-Za-z0-9]+$/', $dataId)) {
        $dataId = strtolower($dataId);
    }
    $h = [];
    foreach ($headers as $nombre => $valor) {
        $h[preg_replace('/^http-/', '', str_replace('_', '-', strtolower((string)$nombre)))] = (string)$valor;
    }
    $partes = [];
    foreach (explode(',', $h['x-signature'] ?? '') as $par) {
        [$clave, $valor] = array_pad(explode('=', trim($par), 2), 2, null);
        if ($valor !== null) {
            $partes[$clave] = $valor;
        }
    }
    $ts = $partes['ts'] ?? '';
    $v1 = $partes['v1'] ?? '';
    if ($ts === '' || $v1 === '') {
        return false;
    }
    if ($toleranciaSeg !== null) {
        if (!ctype_digit($ts)) {
            return false;
        }
        $segundos = strlen($ts) > 11 ? intdiv((int)$ts, 1000) : (int)$ts;
        if (abs(($ahora ?? time()) - $segundos) > $toleranciaSeg) {
            return false;
        }
    }
    $manifiesto = '';
    if ($dataId !== '') {
        $manifiesto .= "id:$dataId;";
    }
    if (($h['x-request-id'] ?? '') !== '') {
        $manifiesto .= 'request-id:' . $h['x-request-id'] . ';';
    }
    $manifiesto .= "ts:$ts;";
    return hash_equals(hash_hmac('sha256', $manifiesto, $secreto), strtolower($v1));
}

/**
 * Un cobro de una suscripción (la "authorized payment" de un preapproval): GET /authorized_payments/{id}. Es lo que
 * anuncia la notificación subscription_authorized_payment. Interesan preapproval_id, status y payment.{id,status}
 * (UNCONFIRMED: no se pudo leer la referencia; los nombres son los de la API de suscripciones). Probar en el sandbox.
 */
function mp_obtener_cobro_recurrente(string $id): array
{
    return mp_request('GET', '/authorized_payments/' . rawurlencode($id));
}
