<?php
declare(strict_types=1);

/*
 * La cuenta del cliente tal como la ve en su portal (/clientes): sus suscripciones con el próximo vencimiento, lo pendiente de pago
 * y sus pagos con su comprobante. Todo filtrado por el cliente de la sesión: el $clienteId sale siempre de require_cliente(),
 * nunca del request. Solo lee.
 */

/** Las suscripciones del cliente con lo que el portal necesita para mostrarlas y ofrecer pagar. */
function portal_suscripciones(int $clienteId): array
{
    return array_map(function (array $s): array {
        $estado = $s['estado'] === 'cancelada' ? 'cancelada' : ((int)$s['pausada'] ? 'pausada' : 'activa');
        $e = suscripcion_estado_cobro($s['fecha_proximo_cobro']);
        return [
            'id' => (int)$s['id'],
            'plan' => $s['plan_nombre'],
            'descripcion' => $s['plan_descripcion'],
            'monto' => $s['monto'],
            'moneda' => $s['moneda'],
            'frecuencia' => $s['frecuencia'],
            'modalidad' => $s['modalidad'],
            'debito_activo' => $s['modalidad'] === 'debito' && $s['mp_preapproval_estado'] === 'autorizada',
            // El link de alta del débito pedido y todavía no autorizado: es del propio cliente, para que lo termine.
            'debito_pendiente_link' => $s['mp_preapproval_estado'] === 'pendiente' ? $s['mp_init_point'] : null,
            'estado' => $estado,
            'dia_vencimiento' => (int)$s['dia_vencimiento'],
            'proximo_vencimiento' => $s['fecha_proximo_cobro'],
            'cobro_estado' => $estado === 'activa' ? $e['estado'] : null,
            'cobro_dias' => $estado === 'activa' ? $e['dias'] : null,
        ];
    }, q_all(
        "SELECT sc.*, p.nombre AS plan_nombre, p.descripcion AS plan_descripcion, p.frecuencia
         FROM suscripciones_cliente sc JOIN planes p ON p.id = sc.plan_id
         WHERE sc.cliente_id = ?
         ORDER BY sc.estado = 'cancelada', sc.fecha_proximo_cobro, sc.id",
        [$clienteId]
    ));
}

/** Una suscripción del cliente de la sesión, o 404 (también si es de otro cliente: no se distingue). */
function portal_suscripcion(int $clienteId, int $suscripcionId): array
{
    foreach (portal_suscripciones($clienteId) as $s) {
        if ($s['id'] === $suscripcionId) {
            return $s;
        }
    }
    throw new HttpError(404, 'No encontrado');
}

function portal_cuenta(int $clienteId): array
{
    $cliente = crud_find('clientes', $clienteId);
    $pagos = q_all(
        "SELECT c.fecha_pago AS fecha, c.concepto, c.monto, c.moneda, c.metodo_pago AS medio, cp.id AS comprobante_id
         FROM cobros c LEFT JOIN comprobantes_pago cp ON cp.cobro_id = c.id AND cp.anulado_en IS NULL
         WHERE c.cliente_id = ? AND c.estado = 'pagado'
         ORDER BY c.fecha_pago DESC, c.id DESC LIMIT 24",
        [$clienteId]
    );
    return [
        'nombre' => $cliente['nombre'],
        'suscripciones' => portal_suscripciones($clienteId),
        'pendientes' => q_all(
            "SELECT concepto, monto, moneda, fecha_vencimiento AS vencimiento FROM cobros
             WHERE cliente_id = ? AND estado = 'pendiente' AND suscripcion_cliente_id IS NULL ORDER BY fecha_vencimiento, id",
            [$clienteId]
        ),
        'pagos' => array_map(function (array $p): array {
            $p['comprobante'] = $p['comprobante_id'] === null ? null : comprobante_pago_numero((int)$p['comprobante_id']);
            unset($p['comprobante_id']);
            return $p;
        }, $pagos),
    ];
}

// ---- vista ----

const PORTAL_ESTADO_COBRO = [
    'al_dia' => ['Al día', 'ok'],
    'por_vencer' => ['Por vencer', 'warn'],
    'vencido' => ['Vencido', 'danger'],
    'sin_fecha' => ['Sin fecha de vencimiento', ''],
];

function portal_badge(string $texto, string $variante = ''): string
{
    return '<span class="badge' . ($variante !== '' ? ' badge-' . e($variante) : '') . '">' . e($texto) . '</span>';
}

function portal_dias_texto(?int $dias): string
{
    if ($dias === null) {
        return '';
    }
    if ($dias < 0) {
        return 'venció hace ' . -$dias . ($dias === -1 ? ' día' : ' días');
    }
    return $dias === 0 ? 'vence hoy' : 'faltan ' . $dias . ($dias === 1 ? ' día' : ' días');
}

function portal_dato(string $etiqueta, string $valor): string
{
    return '<div><div class="stat-label">' . e($etiqueta) . '</div><div>' . e($valor) . '</div></div>';
}

/**
 * Una suscripción como tarjeta del portal. $acciones es el HTML de los botones de pago (lo arma la página: las etapas de pago
 * suman los suyos) y ya viene escapado.
 */
function portal_suscripcion_html(array $s, string $acciones = ''): string
{
    $estado = match ($s['estado']) {
        'cancelada' => portal_badge('Dada de baja'),
        'pausada' => portal_badge('Pausada'),
        default => portal_badge(...PORTAL_ESTADO_COBRO[$s['cobro_estado']] ?? PORTAL_ESTADO_COBRO['sin_fecha']),
    };
    $forma = $s['debito_activo'] ? 'Débito automático' : ($s['debito_pendiente_link'] !== null ? 'Débito automático (falta autorizarlo)' : 'Transferencia o Mercado Pago');
    $datos = portal_dato('Monto', comprobante_monto_texto((string)$s['monto'], (string)$s['moneda']) . ($s['frecuencia'] === 'anual' ? ' por año' : ' por mes'));
    if ($s['estado'] === 'activa') {
        $datos .= portal_dato('Próximo vencimiento', $s['proximo_vencimiento'] === null ? 'Sin fecha'
            : fecha_corta_ar($s['proximo_vencimiento']) . ' · ' . portal_dias_texto($s['cobro_dias']));
        $datos .= portal_dato('Vence', 'El ' . $s['dia_vencimiento'] . ' de cada mes');
    }
    $datos .= portal_dato('Forma de pago', $forma);
    return '<article class="item"><div class="item-top"><span class="item-titulo">' . e((string)$s['plan']) . '</span>' . $estado . '</div>'
        . ($s['descripcion'] ? '<div class="item-sub">' . e((string)$s['descripcion']) . '</div>' : '')
        . '<div class="datos seccion">' . $datos . '</div>'
        . ($acciones !== '' ? '<div class="item-acciones seccion">' . $acciones . '</div>' : '')
        . '</article>';
}

/**
 * Los botones de pago de una suscripción en el portal (HTML ya escapado). Solo una suscripción activa y no pausada se puede
 * pagar. Cada medio suma su botón si está configurado.
 */
function portal_acciones_pago(array $s): string
{
    if ($s['estado'] !== 'activa') {
        return '';
    }
    if ($s['debito_activo']) {
        return '<span class="item-sub">Se cobra solo, con débito automático.</span>';
    }
    $html = '';
    if ($s['debito_pendiente_link'] !== null) {
        $html .= '<a class="btn btn-primario" href="' . e($s['debito_pendiente_link']) . '">Terminar de autorizar el débito</a>';
    }
    $csrf = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<input type="hidden" name="suscripcion_id" value="' . (int)$s['id'] . '">';
    if (portal_pago_mp_motivo($s) === null) {
        $html .= '<form method="post" action="/clientes/pagar">' . $csrf
            . '<button class="btn btn-primario" type="submit">Pagar ' . e(comprobante_monto_texto((string)$s['monto'], (string)$s['moneda']))
            . ' con Mercado Pago</button></form>';
    }
    return $html;
}

/** Lo pendiente (cobros sueltos) y los pagos con su comprobante, como listas del portal. */
function portal_pagos_html(array $cuenta): string
{
    $html = '';
    if ($cuenta['pendientes']) {
        $items = '';
        foreach ($cuenta['pendientes'] as $p) {
            $items .= '<article class="item"><div class="item-top"><span class="item-titulo">' . e((string)($p['concepto'] ?: 'Pendiente')) . '</span>'
                . '<span class="item-titulo">' . e(comprobante_monto_texto((string)$p['monto'], (string)$p['moneda'])) . '</span></div>'
                . '<div class="item-sub">Vence el ' . e(fecha_corta_ar((string)$p['vencimiento'])) . '</div></article>';
        }
        $html .= '<section class="card seccion"><h2>Pendiente de pago</h2><div class="lista seccion">' . $items . '</div></section>';
    }
    $items = '';
    foreach ($cuenta['pagos'] as $p) {
        $comprobante = $p['comprobante'] !== null
            ? '<a class="btn btn-chico" href="/clientes/comprobante?n=' . e($p['comprobante']) . '">Comprobante N° ' . e($p['comprobante']) . '</a>'
            : '';
        $items .= '<article class="item"><div class="item-top"><span class="item-titulo">' . e((string)($p['concepto'] ?: 'Pago')) . '</span>'
            . '<span class="item-titulo">' . e(comprobante_monto_texto((string)$p['monto'], (string)$p['moneda'])) . '</span></div>'
            . '<div class="item-sub">' . e(fecha_corta_ar((string)$p['fecha']) . ($p['medio'] ? ' · ' . $p['medio'] : '')) . '</div>'
            . ($comprobante !== '' ? '<div class="item-acciones">' . $comprobante . '</div>' : '') . '</article>';
    }
    $html .= '<section class="card seccion"><h2>Mis pagos</h2>'
        . '<p class="item-sub">Cada pago tiene su comprobante (no válido como factura): lo podés ver, imprimir o guardar como PDF.</p>'
        . ($items !== '' ? '<div class="lista seccion">' . $items . '</div>' : '<p class="item-sub seccion">Todavía no hay pagos registrados.</p>')
        . '</section>';
    return $html;
}
