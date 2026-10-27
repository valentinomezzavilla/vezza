<?php
declare(strict_types=1);

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
 * Manda un aviso por mail al cliente del cobro y deja registro en avisos_pago. La fila se inserta primero con ok = 0
 * (la clave única (cobro, tipo, canal, numero) es el reclamo: si ya existe con ok = 1 no se manda de nuevo; con ok = 0
 * se reintenta) y pasa a ok = 1 recién cuando el mail salió. Devuelve si el aviso quedó enviado.
 * Un cliente sin mail no deja fila: cuando lo cargue, el próximo intento lo manda.
 */
function aviso_registrar_y_enviar_mail(int $cobroId, string $tipo, int $numero, string $plantilla, array $cobro): bool
{
    $email = trim((string)q_val('SELECT email FROM clientes WHERE id = ?', [(int)$cobro['cliente_id']]));
    if ($email === '') {
        error_log("[avisos] El cliente del cobro $cobroId no tiene mail: no se mandó el aviso $tipo");
        return false;
    }
    $clave = [$cobroId, $tipo, 'mail', $numero];
    db()->prepare('INSERT IGNORE INTO avisos_pago (cobro_id, tipo, canal, numero, ok) VALUES (?, ?, ?, ?, 0)')->execute($clave);
    $ok = q_val('SELECT ok FROM avisos_pago WHERE cobro_id = ? AND tipo = ? AND canal = ? AND numero = ?', $clave);
    if ($ok === null) {
        return false;
    }
    if ((int)$ok === 1) {
        return true;
    }
    if (!mail_enviar($plantilla, $email, aviso_datos_cobro($cobro))) {
        return false;
    }
    db()->prepare('UPDATE avisos_pago SET ok = 1, enviado_en = NOW() WHERE cobro_id = ? AND tipo = ? AND canal = ? AND numero = ?')->execute($clave);
    return true;
}
