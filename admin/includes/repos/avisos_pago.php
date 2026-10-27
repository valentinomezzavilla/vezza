<?php
declare(strict_types=1);

/** Qué plantilla de mail lleva cada aviso que sale apenas se registra (los que no dependen de un calendario). */
const AVISOS_PLANTILLA_INMEDIATA = ['confirmacion' => 'pago_confirmado', 'rechazo' => 'debito_rechazado'];

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
 * Los datos que llevan las plantillas de aviso de un cobro. $cobro trae al menos cliente_id, concepto, monto y
 * fecha_vencimiento (link_pago es opcional). `dias` son los días que faltan para el vencimiento, negativos si ya venció.
 */
function aviso_datos_cobro(array $cobro): array
{
    [, $mes, $dia] = array_map('intval', explode('-', (string)$cobro['fecha_vencimiento']));
    return [
        'nombre' => (string)q_val('SELECT nombre FROM clientes WHERE id = ?', [(int)$cobro['cliente_id']]),
        'concepto' => (string)($cobro['concepto'] ?? ''),
        'monto' => monto_formato_ar($cobro['monto']),
        'fecha_vencimiento' => $dia . ' ' . mb_substr(COBRO_MESES[$mes - 1], 0, 3),
        'dias' => dias_entre(hoy(), (string)$cobro['fecha_vencimiento']),
        'link_pago' => (string)($cobro['link_pago'] ?? ''),
        'portal' => portal_url('/clientes/pagos'),
    ];
}

/**
 * Anota un aviso de mail pendiente de enviar (ok = 0). La clave única (cobro, tipo, canal, numero) hace de reclamo: si
 * la fila ya existe, sea cual sea su estado, no se toca. Es seguro llamarlo dentro de la transacción que provoca el aviso.
 */
function aviso_registrar(int $cobroId, string $tipo, int $numero): void
{
    db()->prepare("INSERT IGNORE INTO avisos_pago (cobro_id, tipo, canal, numero, ok) VALUES (?, ?, 'mail', ?, 0)")
        ->execute([$cobroId, $tipo, $numero]);
}

/**
 * Manda por mail los avisos de confirmación y de rechazo que quedaron registrados sin enviar (ok = 0) y los pasa a
 * ok = 1 con su enviado_en. Se corre después de responderle a Mercado Pago, no dentro del request: el SMTP tarda ~10 s.
 * Un candado de la base (GET_LOCK) evita que dos corridas a la vez manden el mismo mail; si otra lo tiene, no hace nada.
 * Lo que falla (mail caído, cliente sin mail) queda en ok = 0 y se cuenta en `fallidos`: la próxima corrida lo reintenta.
 *
 * @return array{enviados: int, fallidos: int}
 */
function avisos_despachar_inmediatos(): array
{
    $resultado = ['enviados' => 0, 'fallidos' => 0];
    if ((int)q_val("SELECT GET_LOCK('avisos_pago_despacho', 0)") !== 1) {
        return $resultado;
    }
    try {
        $pendientes = q_all(
            "SELECT id, cobro_id, tipo FROM avisos_pago WHERE ok = 0 AND canal = 'mail' AND tipo IN ('confirmacion', 'rechazo') ORDER BY id"
        );
        foreach ($pendientes as $aviso) {
            $cobro = q_one('SELECT * FROM cobros WHERE id = ?', [(int)$aviso['cobro_id']]);
            $email = $cobro === null ? '' : trim((string)q_val('SELECT email FROM clientes WHERE id = ?', [(int)$cobro['cliente_id']]));
            if ($email === '') {
                error_log('[avisos] No se pudo mandar el aviso ' . $aviso['id'] . ' (' . $aviso['tipo'] . '): el cliente no tiene mail');
                $resultado['fallidos']++;
                continue;
            }
            if (!mail_enviar(AVISOS_PLANTILLA_INMEDIATA[$aviso['tipo']], $email, aviso_datos_cobro($cobro))) {
                $resultado['fallidos']++;
                continue;
            }
            db()->prepare('UPDATE avisos_pago SET ok = 1, enviado_en = NOW() WHERE id = ?')->execute([(int)$aviso['id']]);
            $resultado['enviados']++;
        }
    } finally {
        q_val("SELECT RELEASE_LOCK('avisos_pago_despacho')");
    }
    return $resultado;
}
