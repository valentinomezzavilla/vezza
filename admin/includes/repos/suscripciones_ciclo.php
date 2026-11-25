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
    $r = suscripcion_crear_debito($s, $inicio);
    crud_update('suscripciones_cliente', $id, [
        'mp_preapproval_id' => $r['id'],
        'mp_init_point' => $r['init_point'],
        'mp_preapproval_estado' => 'pendiente',
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
    $cambios = ['estado' => 'cancelada', 'fecha_baja' => hoy(), 'pausada' => 0, 'modalidad' => 'transferencia'];
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
    $cambios = ['estado' => 'activa', 'fecha_baja' => null, 'pausada' => 0, 'aviso_baja_enviado_en' => null];
    if ($s['mp_preapproval_id'] !== null) {
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
