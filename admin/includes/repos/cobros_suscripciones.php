<?php
declare(strict_types=1);

/** Con cuántos días de anticipación al vencimiento se genera el cobro de cada período. */
const COBRO_ANTICIPACION_DIAS = 5;
/** Tope de períodos que una suscripción genera en una corrida (si estuvo mucho tiempo sin correr). */
const COBRO_MAX_PERIODOS_POR_CORRIDA = 12;
const COBRO_MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** "Mantenimiento · octubre 2026" para un vencimiento YYYY-MM-DD (sin depender de intl ni del locale). */
function cobro_concepto_periodo(string $plan, string $fecha): string
{
    [$anio, $mes] = array_map('intval', explode('-', $fecha));
    return $plan . ' · ' . COBRO_MESES[$mes - 1] . ' ' . $anio;
}

/**
 * Crea el link de pago (preferencia de Mercado Pago) de un cobro y lo guarda. Lanza si la pasarela falla.
 * $cobro trae al menos id, concepto, monto y cliente_id.
 */
function cobro_crear_link(array $cobro): void
{
    $email = trim((string)q_val('SELECT email FROM clientes WHERE id = ?', [(int)$cobro['cliente_id']]));
    if ($email === '') {
        throw new RuntimeException('el cliente no tiene mail');
    }
    $pref = mp_crear_preferencia($cobro, $email);
    crud_update('cobros', (int)$cobro['id'], ['link_pago' => $pref['init_point'], 'mp_preference_id' => $pref['id']]);
}

/**
 * Genera los cobros que ya toca emitir (vencimiento a COBRO_ANTICIPACION_DIAS días o menos) de las suscripciones
 * activas y no pausadas, y avanza su fecha_proximo_cobro un mes. Cada suscripción va en su propia transacción; un
 * error en una se loguea y no frena a las demás. Los links de pago se piden después, fuera de la transacción: si la
 * pasarela falla el cobro queda sin link y cobros_completar_links() lo reintenta.
 *
 * @return array{creados: int, errores: int}
 */
function cobros_generar_pendientes(?string $hoy = null): array
{
    $hoy ??= hoy();
    $limite = (new DateTimeImmutable($hoy))->modify('+' . COBRO_ANTICIPACION_DIAS . ' days')->format('Y-m-d');
    $ids = q_all(
        "SELECT id FROM suscripciones_cliente
         WHERE estado = 'activa' AND pausada = 0 AND fecha_proximo_cobro IS NOT NULL AND fecha_proximo_cobro <= ?
         ORDER BY id",
        [$limite]
    );
    $creados = 0;
    $errores = 0;
    foreach ($ids as $fila) {
        $id = (int)$fila['id'];
        try {
            $nuevos = tx(fn(): array => cobros_generar_de_suscripcion($id, $limite));
        } catch (Throwable $e) {
            $errores++;
            error_log("[cobros] No se pudo generar el cobro de la suscripción $id: " . $e->getMessage());
            continue;
        }
        $creados += count($nuevos);
        foreach ($nuevos as $cobro) {
            if ($cobro['modalidad'] !== 'link') {
                continue;
            }
            try {
                cobro_crear_link($cobro);
            } catch (Throwable $e) {
                $errores++;
                error_log('[cobros] No se pudo crear el link del cobro ' . $cobro['id'] . ': ' . $e->getMessage());
            }
        }
    }
    return ['creados' => $creados, 'errores' => $errores];
}

/**
 * Emite los cobros de una suscripción hasta $limite (máx. COBRO_MAX_PERIODOS_POR_CORRIDA) y deja su fecha_proximo_cobro
 * en el siguiente vencimiento. Se llama dentro de una transacción. Si el período ya tenía cobro, solo avanza la fecha.
 * El avance usa sumar_periodo: tras un mes corto el día no vuelve al original (31 ene → 28 feb → 28 mar), limitación
 * aceptada.
 *
 * @return list<array> Los cobros creados, con la modalidad de la suscripción.
 */
function cobros_generar_de_suscripcion(int $id, string $limite): array
{
    $s = q_one(
        'SELECT sc.*, p.nombre AS plan_nombre FROM suscripciones_cliente sc JOIN planes p ON p.id = sc.plan_id WHERE sc.id = ? FOR UPDATE',
        [$id]
    );
    $nuevos = [];
    if ($s === null || $s['estado'] !== 'activa' || (int)$s['pausada'] === 1) {
        return $nuevos;
    }
    $fecha = $s['fecha_proximo_cobro'];
    for ($i = 0; $i < COBRO_MAX_PERIODOS_POR_CORRIDA && $fecha !== null && $fecha <= $limite; $i++) {
        $periodo = substr($fecha, 0, 7);
        if (q_val('SELECT 1 FROM cobros WHERE suscripcion_cliente_id = ? AND periodo = ?', [$id, $periodo]) === null) {
            $cobro = [
                'cliente_id' => (int)$s['cliente_id'],
                'suscripcion_cliente_id' => $id,
                'periodo' => $periodo,
                'concepto' => cobro_concepto_periodo((string)$s['plan_nombre'], $fecha),
                'monto' => $s['monto'],
                'moneda' => $s['moneda'],
                'fecha_vencimiento' => $fecha,
                'estado' => 'pendiente',
            ];
            $cobro['id'] = crud_insert('cobros', $cobro);
            $nuevos[] = $cobro + ['modalidad' => $s['modalidad']];
        }
        $fecha = sumar_periodo($fecha, 'mensual');
    }
    crud_update('suscripciones_cliente', $id, ['fecha_proximo_cobro' => $fecha]);
    return $nuevos;
}

/**
 * Crea el link de pago de los cobros pendientes de modalidad link que todavía no lo tienen (porque la pasarela
 * falló al generarlos). Un error en uno se loguea y no frena a los demás.
 *
 * @return array{completados: int, errores: int}
 */
function cobros_completar_links(): array
{
    $cobros = q_all(
        "SELECT c.* FROM cobros c
         JOIN suscripciones_cliente sc ON sc.id = c.suscripcion_cliente_id
         WHERE c.estado = 'pendiente' AND c.link_pago IS NULL AND sc.modalidad = 'link'
         ORDER BY c.id"
    );
    $completados = 0;
    $errores = 0;
    foreach ($cobros as $cobro) {
        try {
            cobro_crear_link($cobro);
            $completados++;
        } catch (Throwable $e) {
            $errores++;
            error_log('[cobros] No se pudo crear el link del cobro ' . $cobro['id'] . ': ' . $e->getMessage());
        }
    }
    return ['completados' => $completados, 'errores' => $errores];
}
