<?php
declare(strict_types=1);

/*
 * Ciclo de vida de una suscripción de cliente: débito automático de Mercado Pago, pausa, baja y reactivación.
 *
 * Regla de todas las acciones: primero la llamada a Mercado Pago, después el cambio local. Si Mercado Pago falla, la
 * acción termina con un 502 y el estado local queda como estaba (nunca una suscripción local distinta de la de Mercado Pago).
 */

/** Los estados del débito en los que Mercado Pago todavía tiene una suscripción viva que hay que cancelar o que impide crear otra. */
const DEBITO_EN_CURSO = ['pendiente', 'autorizada', 'pausada'];

/** '2026-11-25' → '25 de noviembre de 2026' (para los mails). */
function fecha_larga_es(string $fecha): string
{
    [$anio, $mes, $dia] = array_map('intval', explode('-', $fecha));
    return sprintf('%d de %s de %d', $dia, COBRO_MESES[$mes - 1], $anio);
}

/** Una falla de Mercado Pago (red, token, respuesta rara) como error del panel; el detalle va al log, nunca al navegador. */
function suscripcion_mp_error(RuntimeException $e): HttpError
{
    error_log('[suscripciones] Mercado Pago: ' . $e->getMessage());
    return new HttpError(502, 'No pudimos hablar con Mercado Pago. Probá de nuevo en un rato.');
}

/**
 * Crea el preapproval de una suscripción en Mercado Pago (sin tocar la base). Exige débito encendido, moneda ARS y mail.
 * Mercado Pago empieza a cobrar en $inicio si es futuro; si no hay inicio (o ya pasó) cobra apenas el cliente autoriza.
 * Sin $inicio se usa fecha_proximo_cobro cuando todavía no llegó.
 *
 * @return array{id: string, init_point: string}
 */
function suscripcion_crear_debito(array $s, ?string $inicio): array
{
    if (!mp_configurado()) {
        throw new HttpError(409, 'El débito automático todavía no está configurado (falta la clave de Mercado Pago).');
    }
    if ($inicio !== null) {
        $inicio = validate(['inicio' => $inicio], ['inicio' => ['type' => 'date']])['inicio'];
    }
    suscripcion_validar_modalidad($s, 'debito');
    $email = (string)q_val('SELECT email FROM clientes WHERE id = ?', [(int)$s['cliente_id']]);

    $hoy = hoy();
    $inicio ??= $s['fecha_proximo_cobro'];
    $inicio = ($inicio !== null && $inicio > $hoy) ? $inicio : null;
    try {
        return mp_crear_preapproval($s, $email, $inicio === null ? null : mp_inicio_iso($inicio));
    } catch (RuntimeException $e) {
        throw suscripcion_mp_error($e);
    }
}

/**
 * Pide el débito automático: crea el preapproval en Mercado Pago y deja el link de alta para mandárselo al cliente. La
 * modalidad NO cambia hasta que Mercado Pago confirma la autorización (lo hace el webhook o Sincronizar).
 */
function suscripcion_pasar_a_debito(int $id, ?string $inicio = null): array
{
    if (!mp_configurado()) {
        throw new HttpError(409, 'El débito automático todavía no está configurado (falta la clave de Mercado Pago).');
    }
    $s = suscripciones_cliente_get($id);
    if ($s['estado'] === 'cancelada') {
        throw new HttpError(409, 'La suscripción está cancelada. Reactivala primero.');
    }
    if (in_array($s['mp_preapproval_estado'], DEBITO_EN_CURSO, true)) {
        throw new HttpError(409, 'Esta suscripción ya tiene un débito en curso.');
    }
    if ((int)$s['pausada']) {
        // Con el cobro pausado, apenas el cliente autorice Mercado Pago empezaría a cobrar igual.
        throw new HttpError(409, 'El cobro está pausado. Reanudalo antes de pasar a débito.');
    }
    $r = suscripcion_crear_debito($s, $inicio);
    crud_update('suscripciones_cliente', $id, [
        'mp_preapproval_id' => $r['id'],
        'mp_init_point' => $r['init_point'],
        'mp_preapproval_estado' => 'pendiente',
        // Un débito nuevo es una historia nueva: si se vuelve a caer, se le avisa de nuevo al cliente.
        'aviso_baja_enviado_en' => null,
    ]);
    return suscripciones_cliente_get($id);
}

/** Pausa o reanuda el cobro. Con débito autorizado o pausado también pausa o reanuda la suscripción en Mercado Pago. */
function suscripcion_cliente_pausar(int $id, bool $pausada): array
{
    $s = suscripciones_cliente_get($id);
    if ($s['estado'] === 'cancelada') {
        throw new HttpError(409, 'La suscripción está cancelada.');
    }
    if ($pausada && $s['mp_preapproval_estado'] === 'pendiente') {
        // Pausar solo cambiaría la marca local: apenas el cliente autorice, Mercado Pago cobraría igual.
        throw new HttpError(409, 'El débito todavía está pendiente de autorización. Pausá el cobro cuando el cliente autorice, o cancelá el débito.');
    }
    $cambios = ['pausada' => $pausada ? 1 : 0];
    if (in_array($s['mp_preapproval_estado'], ['autorizada', 'pausada'], true)) {
        try {
            $pausada ? mp_pausar_preapproval((string)$s['mp_preapproval_id']) : mp_reanudar_preapproval((string)$s['mp_preapproval_id']);
        } catch (RuntimeException $e) {
            throw suscripcion_mp_error($e);
        }
        $cambios['mp_preapproval_estado'] = $pausada ? 'pausada' : 'autorizada';
    }
    crud_update('suscripciones_cliente', $id, $cambios);
    return suscripciones_cliente_get($id);
}

/**
 * Da de baja la suscripción: cancela el débito en Mercado Pago si lo hay, deja la baja de hoy y avisa al cliente (una sola vez)
 * hasta cuándo sigue activo lo ya pagado.
 *
 * @return array{suscripcion: array, aviso: string} aviso: lo que devuelve suscripcion_avisar_baja
 */
function suscripcion_cliente_cancelar(int $id): array
{
    $s = suscripciones_cliente_get($id);
    if ($s['estado'] === 'cancelada') {
        throw new HttpError(409, 'La suscripción ya está cancelada.');
    }
    $conDebito = $s['mp_preapproval_id'] !== null && in_array($s['mp_preapproval_estado'], DEBITO_EN_CURSO, true);
    if ($conDebito) {
        try {
            mp_cancelar_preapproval((string)$s['mp_preapproval_id']);
        } catch (RuntimeException $e) {
            throw suscripcion_mp_error($e);
        }
    }
    // Una baja del admin es un hecho nuevo: se le avisa al cliente aunque ya se le haya avisado antes de que se cayó el débito.
    // Una segunda baja no puede mandar un segundo mail: ya está cancelada y arriba da 409.
    // debito_al_cancelar recuerda si había un débito vivo, que es lo único que se vuelve a pedir al reactivar.
    $cambios = [
        'estado' => 'cancelada', 'fecha_baja' => hoy(), 'pausada' => 0, 'modalidad' => 'transferencia',
        'aviso_baja_enviado_en' => null, 'debito_al_cancelar' => $conDebito ? 1 : 0,
    ];
    if ($conDebito) {
        $cambios['mp_preapproval_estado'] = 'cancelada';
    }
    crud_update('suscripciones_cliente', $id, $cambios);
    return ['suscripcion' => suscripciones_cliente_get($id), 'aviso' => suscripcion_avisar_baja($id, 'baja')];
}

/**
 * Vuelve a activar una suscripción cancelada. Si tenía débito, crea uno nuevo en Mercado Pago (primero la llamada: si falla
 * no se reactiva) y queda pendiente de que el cliente autorice con el link nuevo.
 */
function suscripcion_cliente_reactivar(int $id): array
{
    $s = suscripciones_cliente_get($id);
    if ($s['estado'] !== 'cancelada') {
        throw new HttpError(409, 'La suscripción no está cancelada.');
    }
    $cambios = ['estado' => 'activa', 'fecha_baja' => null, 'pausada' => 0, 'aviso_baja_enviado_en' => null, 'debito_al_cancelar' => 0];
    // Solo se vuelve a pedir el débito si estaba vivo cuando se dio de baja. Un débito que se cayó antes (y la suscripción siguió
    // por transferencia) no se pide de nuevo: nadie lo pidió y puede fallar por un mail o una moneda que ya no sirven.
    if ((int)$s['debito_al_cancelar'] === 1) {
        $r = suscripcion_crear_debito($s, null);
        $cambios += ['mp_preapproval_id' => $r['id'], 'mp_init_point' => $r['init_point'], 'mp_preapproval_estado' => 'pendiente'];
    }
    crud_update('suscripciones_cliente', $id, $cambios);
    return suscripciones_cliente_get($id);
}

/**
 * Le avisa al cliente por mail (plantilla `suscripcion_cancelada`) que su suscripción se dio de baja y hasta cuándo sigue
 * activo lo ya pagado. $motivo: 'baja' (la cancela el admin) o 'debito' (se cayó el débito por afuera y la suscripción sigue activa).
 *
 * Sale una sola vez: se reclama aviso_baja_enviado_en con un UPDATE condicional antes de mandar, así dos eventos a la vez no
 * duplican el mail; si el mail no sale se libera el reclamo para poder reintentar.
 *
 * @return string 'enviado' | 'no_enviado' | 'ya_enviado' | 'sin_mail'
 */
function suscripcion_avisar_baja(int $id, string $motivo): string
{
    $s = suscripciones_cliente_get($id);
    $cliente = q_one('SELECT nombre, email FROM clientes WHERE id = ?', [(int)$s['cliente_id']]);
    $email = trim((string)($cliente['email'] ?? ''));
    if ($email === '') {
        return 'sin_mail';
    }
    $reclamo = db()->prepare('UPDATE suscripciones_cliente SET aviso_baja_enviado_en = NOW() WHERE id = ? AND aviso_baja_enviado_en IS NULL');
    $reclamo->execute([$id]);
    if ($reclamo->rowCount() === 0) {
        return 'ya_enviado';
    }

    $fecha = $s['fecha_proximo_cobro'];
    $vigente = $fecha !== null && $fecha >= hoy();
    $enviado = mail_enviar('suscripcion_cancelada', $email, [
        'nombre' => (string)$cliente['nombre'],
        'plan' => (string)$s['plan_nombre'],
        'motivo' => $motivo,
        'acceso_hasta' => $vigente ? fecha_larga_es($fecha) : '',
        'vencida' => !$vigente,
    ]);
    if (!$enviado) {
        db()->prepare('UPDATE suscripciones_cliente SET aviso_baja_enviado_en = NULL WHERE id = ?')->execute([$id]);
        return 'no_enviado';
    }
    return 'enviado';
}

// ---- Ajustar precio ------------------------------------------------------------------------------------------------

/** "$85.000" (con centavos, "$85.000,50", solo cuando no son cero). */
function monto_formato_ar(string|float $monto): string
{
    $centavos = (int)round(abs((float)$monto) * 100);
    $texto = '$' . number_format(intdiv($centavos, 100), 0, ',', '.');
    if ($centavos % 100 !== 0) {
        $texto .= ',' . sprintf('%02d', $centavos % 100);
    }
    return ((float)$monto < 0 && $centavos > 0 ? '-' : '') . $texto;
}

/**
 * Cambia el precio de UNA suscripción (cada cliente tiene el suyo) y le avisa por mail desde cuándo rige. Con débito en
 * curso, primero se actualiza el monto en Mercado Pago (PUT /preapproval): si no lo acepta o contesta con otro monto, 502 y no
 * se cambia nada. Un débito todavía pendiente de autorización también se actualiza, así el cliente no autoriza el monto viejo
 * (UNCONFIRMED: que Mercado Pago acepte cambiar el monto de una suscripción pendiente; si no, el error avisa y hay que cancelar y
 * volver a pasar a débito). Los cobros ya registrados no cambian. El mail que no sale no deshace el cambio.
 *
 * @return array{suscripcion: array, aviso: string} aviso: 'enviado' | 'no_enviado' | 'sin_mail'
 */
function suscripcion_cliente_ajustar_precio(int $id, array $input): array
{
    $s = suscripciones_cliente_get($id);
    if ($s['estado'] === 'cancelada') {
        throw new HttpError(409, 'La suscripción está cancelada.');
    }
    $nuevo = validate($input, ['monto' => ['type' => 'decimal', 'required' => true]])['monto'];
    if ((float)$nuevo <= 0) {
        throw new HttpError(422, 'Revisá los datos marcados', ['monto' => 'Tiene que ser mayor que cero']);
    }
    if ($nuevo === $s['monto']) {
        throw new HttpError(422, 'Revisá los datos marcados', ['monto' => 'Es el mismo monto que ya tiene']);
    }

    if ($s['mp_preapproval_id'] !== null && in_array($s['mp_preapproval_estado'], DEBITO_EN_CURSO, true)) {
        try {
            $r = mp_actualizar_preapproval((string)$s['mp_preapproval_id'], [
                'auto_recurring' => ['transaction_amount' => (float)$nuevo, 'currency_id' => $s['moneda']],
            ]);
        } catch (RuntimeException $e) {
            throw suscripcion_mp_error($e);
        }
        $devuelto = $r['auto_recurring']['transaction_amount'] ?? null;
        if ($devuelto !== null && (int)round((float)$devuelto * 100) !== (int)round((float)$nuevo * 100)) {
            throw new HttpError(502, 'Mercado Pago no aplicó el monto nuevo. No se cambió nada.');
        }
    }

    $historialId = tx(function () use ($id, $s, $nuevo): int {
        crud_update('suscripciones_cliente', $id, ['monto' => $nuevo]);
        return crud_insert('suscripciones_precio_historial', [
            'suscripcion_cliente_id' => $id,
            'monto_anterior' => $s['monto'],
            'monto_nuevo' => $nuevo,
            'moneda' => $s['moneda'],
        ]);
    });

    $cliente = q_one('SELECT nombre, email FROM clientes WHERE id = ?', [(int)$s['cliente_id']]);
    $email = trim((string)($cliente['email'] ?? ''));
    $aviso = 'sin_mail';
    if ($email !== '') {
        $enviado = mail_enviar('precio_cambiado', $email, [
            'nombre' => (string)$cliente['nombre'],
            'plan' => (string)$s['plan_nombre'],
            'monto_anterior' => monto_formato_ar($s['monto']),
            'monto_nuevo' => monto_formato_ar($nuevo),
            'desde' => $s['fecha_proximo_cobro'] !== null ? fecha_larga_es($s['fecha_proximo_cobro']) : '',
        ]);
        if ($enviado) {
            db()->prepare('UPDATE suscripciones_precio_historial SET avisado_en = NOW() WHERE id = ?')->execute([$historialId]);
        }
        $aviso = $enviado ? 'enviado' : 'no_enviado';
    }
    return ['suscripcion' => suscripciones_cliente_get($id), 'aviso' => $aviso];
}
