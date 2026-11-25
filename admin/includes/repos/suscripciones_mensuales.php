<?php
declare(strict_types=1);

/*
 * Lectura de la pestaña "Suscripciones mensuales" (Cobros) y de la sección Suscripción de la ficha del cliente: quién está al día,
 * por vencer o vencido, calculado desde "pago hasta". Solo lee; nunca cambia nada.
 */

const MESES_CORTOS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

/** '2026-11-01' → '1 nov'. */
function fecha_corta_es(string $fecha): string
{
    [, $mes, $dia] = array_map('intval', explode('-', $fecha));
    return $dia . ' ' . MESES_CORTOS[$mes - 1];
}

/**
 * Deja un teléfono argentino listo para wa.me: solo dígitos, con 549 adelante (móvil). Acepta el número nacional de 10 dígitos
 * ('11 5555-1234'), con 0 de larga distancia ('011 5555-1234') o con el prefijo del país ('+54 9 11 5555 1234'). Todo lo que no
 * encaje (letras, muy corto, con el 15) da null: mejor no mandar a un número equivocado.
 */
function whatsapp_telefono_normalizar(string $telefono): ?string
{
    $t = trim($telefono);
    if ($t === '' || preg_match('/[A-Za-z]/', $t)) {
        return null;
    }
    $d = (string)preg_replace('/\D/', '', $t);
    if (str_starts_with($d, '00')) {
        $d = substr($d, 2);
    }
    if (str_starts_with($d, '54')) {
        $d = substr($d, 2);
        if (strlen($d) === 11 && $d[0] === '9') {
            $d = substr($d, 1);
        }
    } elseif (strlen($d) === 11 && $d[0] === '0') {
        $d = substr($d, 1);
    }
    return strlen($d) === 10 ? '549' . $d : null;
}

/**
 * El link que abre un chat de WhatsApp con el recordatorio de pago ya escrito (voseo, primera persona singular), o null si el
 * cliente no tiene un teléfono válido. $suscripcion trae plan_nombre, monto y fecha_proximo_cobro; $cliente, nombre y telefono.
 */
function suscripcion_whatsapp_url(array $suscripcion, array $cliente): ?string
{
    $telefono = whatsapp_telefono_normalizar((string)($cliente['telefono'] ?? ''));
    if ($telefono === null || $suscripcion['fecha_proximo_cobro'] === null) {
        return null;
    }
    $mensaje = sprintf(
        'Hola %s, te escribo por tu pago mensual de %s (%s), que venció el %s. ¿Lo pudiste hacer? Gracias.',
        $cliente['nombre'], $suscripcion['plan_nombre'], monto_formato_ar($suscripcion['monto']), fecha_corta_es($suscripcion['fecha_proximo_cobro'])
    );
    return 'https://wa.me/' . $telefono . '?text=' . rawurlencode($mensaje);
}

/**
 * Todo lo que necesita la pestaña: el resumen y una fila por suscripción. Filtro opcional `cliente_id`. El resumen cuenta solo las
 * suscripciones activas y no pausadas; el ingreso mensual suma solo las de ARS (y avisa si hay de otra moneda). Las filas traen
 * todo lo de suscripciones_cliente_list más ultimo_pago, debito_pendiente y whatsapp_url (solo en las vencidas activas con teléfono
 * válido). Orden: primero las que hay que mirar (vencidas, por vencer, al día), después las pausadas y las canceladas.
 *
 * @return array{resumen: array{ingreso_mensual: string, otras_monedas: bool, al_dia: int, por_vencer: int, vencidas: int}, debito_disponible: bool, filas: list<array>}
 */
function suscripciones_mensuales(array $get): array
{
    $filas = suscripciones_cliente_list(array_intersect_key($get, ['cliente_id' => 1]));

    $ultimos = [];
    $clientes = [];
    if ($filas) {
        $ids = array_column($filas, 'id');
        $pagos = q_all(
            "SELECT suscripcion_cliente_id, fecha_pago, monto, moneda, metodo_pago FROM cobros
             WHERE estado = 'pagado' AND suscripcion_cliente_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')
             ORDER BY fecha_pago, id',
            $ids
        );
        foreach ($pagos as $p) {
            $ultimos[(int)$p['suscripcion_cliente_id']] = [
                'fecha_pago' => $p['fecha_pago'], 'monto' => $p['monto'], 'moneda' => $p['moneda'], 'metodo_pago' => $p['metodo_pago'],
            ];
        }
        $clienteIds = array_values(array_unique(array_column($filas, 'cliente_id')));
        foreach (q_all(
            'SELECT id, nombre, telefono FROM clientes WHERE id IN (' . implode(',', array_fill(0, count($clienteIds), '?')) . ')',
            $clienteIds
        ) as $c) {
            $clientes[(int)$c['id']] = $c;
        }
    }

    $resumen = ['ingreso_mensual' => '', 'otras_monedas' => false, 'al_dia' => 0, 'por_vencer' => 0, 'vencidas' => 0];
    $centavosArs = 0;
    foreach ($filas as &$f) {
        $cuenta = $f['estado'] === 'activa' && !(int)$f['pausada'];
        $f['ultimo_pago'] = $ultimos[(int)$f['id']] ?? null;
        $f['debito_pendiente'] = $f['mp_preapproval_estado'] === 'pendiente';
        $f['whatsapp_url'] = $cuenta && $f['cobro_estado'] === 'vencido'
            ? suscripcion_whatsapp_url($f, $clientes[(int)$f['cliente_id']] ?? [])
            : null;
        if (!$cuenta) {
            continue;
        }
        match ($f['cobro_estado']) {
            'al_dia' => $resumen['al_dia']++,
            'por_vencer' => $resumen['por_vencer']++,
            'vencido' => $resumen['vencidas']++,
            default => null,
        };
        if ($f['moneda'] === MONEDA_MP) {
            $centavosArs += (int)round((float)$f['monto'] * 100);
        } else {
            $resumen['otras_monedas'] = true;
        }
    }
    unset($f);
    $resumen['ingreso_mensual'] = monto_formato_ar($centavosArs / 100);

    $rango = ['vencido' => 0, 'por_vencer' => 1, 'al_dia' => 2, 'sin_fecha' => 3];
    usort($filas, function (array $a, array $b) use ($rango): int {
        $inactiva = fn(array $f): int => ($f['estado'] !== 'activa' ? 2 : 0) + ((int)$f['pausada'] ? 1 : 0);
        return [$inactiva($a), $rango[$a['cobro_estado']], $a['fecha_proximo_cobro'] ?? '9999-12-31', $a['id']]
            <=> [$inactiva($b), $rango[$b['cobro_estado']], $b['fecha_proximo_cobro'] ?? '9999-12-31', $b['id']];
    });

    return ['resumen' => $resumen, 'debito_disponible' => mp_configurado(), 'filas' => $filas];
}
