<?php
declare(strict_types=1);

/*
 * Comprobantes de pago (no válidos como factura). Cada pago registrado de una suscripción emite uno; un cobro suelto pagado lo
 * emite cuando el admin lo pide. Lo que dice el comprobante queda fijo en `datos` al emitirlo: nunca cambia ni se borra. Si el
 * pago se deshace (se borra o vuelve a pendiente) el comprobante queda anulado, y si se corrige (monto, fecha, concepto o medio)
 * se anula y se emite uno nuevo. El mail sale aparte, después de registrar: si falla, el pago y el comprobante quedan igual.
 * No confundir con cobros.comprobante_archivo (comprobantes.php), que es el archivo de la transferencia que sube el cliente.
 */

const COMPROBANTE_PUNTO_VENTA = '0001';
const COMPROBANTE_LEYENDA = 'Comprobante no válido como factura';
/** Lo que, si cambia en un cobro pagado, deja viejo a su comprobante. */
const COMPROBANTE_CAMPOS_DEL_COBRO = ['monto', 'moneda', 'fecha_pago', 'concepto', 'metodo_pago'];

/** 42 → '0001-00000042'. */
function comprobante_pago_numero(int $id): string
{
    return COMPROBANTE_PUNTO_VENTA . '-' . sprintf('%08d', $id);
}

/** '0001-00000042' → 42, o null si no tiene ese formato. */
function comprobante_pago_id_de_numero(string $numero): ?int
{
    return preg_match('/^' . COMPROBANTE_PUNTO_VENTA . '-(\d{8})$/', trim($numero), $m) && (int)$m[1] > 0 ? (int)$m[1] : null;
}

/** '2026-12-05' → '5/12/2026'. */
function fecha_corta_ar(string $fecha): string
{
    [$anio, $mes, $dia] = array_map('intval', explode('-', substr($fecha, 0, 10)));
    return "$dia/$mes/$anio";
}

/** El monto como se lee en el comprobante: '$45.000' en pesos, 'USD 100' en otra moneda. */
function comprobante_monto_texto(string $monto, string $moneda): string
{
    return $moneda === 'ARS' ? monto_formato_ar($monto) : $moneda . ' ' . ltrim(monto_formato_ar($monto), '$');
}

/** La casilla a la que va el comprobante: la del acceso al portal del cliente o, si no tiene, el mail de su ficha. */
function comprobante_pago_destino(int $clienteId): ?string
{
    $portal = q_val('SELECT email FROM usuarios_cliente WHERE cliente_id = ? AND activo = 1', [$clienteId]);
    $email = trim((string)($portal ?? q_val('SELECT email FROM clientes WHERE id = ?', [$clienteId]) ?? ''));
    return $email === '' ? null : $email;
}

/**
 * Emite el comprobante de un cobro pagado (dentro de la transacción del pago, si la hay) y devuelve su id. $periodo, para los
 * pagos de suscripción: ['desde' => primer día cubierto, 'hasta' => último día cubierto].
 */
function comprobante_pago_emitir(int $cobroId, ?array $periodo = null): int
{
    $c = crud_find('cobros', $cobroId);
    if ($c['estado'] !== 'pagado') {
        throw new HttpError(409, 'Solo se emite comprobante de un cobro pagado.');
    }
    if (q_val('SELECT id FROM comprobantes_pago WHERE cobro_id = ?', [$cobroId]) !== null) {
        throw new HttpError(409, 'Este cobro ya tiene su comprobante.');
    }
    $cliente = crud_find('clientes', (int)$c['cliente_id']);
    $ahora = date('Y-m-d H:i:s');
    $id = crud_insert('comprobantes_pago', [
        'cobro_id' => $cobroId,
        'cliente_id' => (int)$c['cliente_id'],
        'datos' => '{}',
        'emitido_en' => $ahora,
        'destino' => comprobante_pago_destino((int)$c['cliente_id']),
        'actualizado_en' => $ahora,
    ]);
    // El número sale del id, así que la foto se completa recién con el id en la mano.
    $datos = [
        'numero' => comprobante_pago_numero($id),
        'fecha' => $c['fecha_pago'] ?? hoy(),
        'emisor' => [
            'nombre' => (string)env('COMPROBANTE_EMISOR_NOMBRE', 'VEZZA'),
            'cuit' => env('COMPROBANTE_EMISOR_CUIT'),
            'contacto' => env('COMPROBANTE_EMISOR_CONTACTO'),
        ],
        'cliente' => ['nombre' => $cliente['nombre']],
        'concepto' => (string)($c['concepto'] ?? 'Pago'),
        'periodo' => $periodo,
        'medio' => $c['metodo_pago'],
        'monto' => $c['monto'],
        'moneda' => $c['moneda'],
        'cobro_id' => $cobroId,
        'leyenda' => COMPROBANTE_LEYENDA,
    ];
    crud_update('comprobantes_pago', $id, ['datos' => json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
    return $id;
}

/** El comprobante vigente (no anulado) de un cobro, o null. */
function comprobante_pago_del_cobro(int $cobroId): ?array
{
    return q_one('SELECT * FROM comprobantes_pago WHERE cobro_id = ? AND anulado_en IS NULL', [$cobroId]);
}

/**
 * Anula un comprobante: queda con la fecha y el motivo, y se suelta del cobro (así el cobro puede tener uno nuevo). Lo que
 * decía no cambia.
 */
function comprobante_pago_anular(int $id, string $motivo): void
{
    db()->prepare(
        'UPDATE comprobantes_pago SET anulado_en = NOW(), motivo_anulacion = ?, cobro_id = NULL, actualizado_en = NOW()
         WHERE id = ? AND anulado_en IS NULL'
    )->execute([mb_substr($motivo, 0, 255), $id]);
}

/** Un comprobante como lo usan las pantallas, la API y los sistemas: la foto más su estado. */
function comprobante_pago_publico(array $f): array
{
    $datos = json_decode((string)$f['datos'], true) ?: [];
    return [
        'id' => (int)$f['id'],
        'numero' => comprobante_pago_numero((int)$f['id']),
        'cliente_id' => (int)$f['cliente_id'],
        'cobro_id' => $f['cobro_id'] === null ? null : (int)$f['cobro_id'],
        'emitido_en' => $f['emitido_en'],
        'destino' => $f['destino'],
        'enviado_en' => $f['enviado_en'],
        'error' => $f['error'],
        'anulado' => $f['anulado_en'] !== null,
        'anulado_en' => $f['anulado_en'],
        'motivo_anulacion' => $f['motivo_anulacion'],
        'actualizado_en' => $f['actualizado_en'],
        'datos' => $datos,
    ];
}

function comprobante_pago_get(int $id): array
{
    $f = q_one('SELECT * FROM comprobantes_pago WHERE id = ?', [$id]);
    if ($f === null) {
        throw new HttpError(404, 'No encontrado');
    }
    return comprobante_pago_publico($f);
}

/** Por número. Con $clienteId (portal), uno de otro cliente da 404 igual que uno que no existe. */
function comprobante_pago_por_numero(string $numero, ?int $clienteId = null): array
{
    $id = comprobante_pago_id_de_numero($numero) ?? throw new HttpError(404, 'No encontrado');
    $c = comprobante_pago_get($id);
    if ($clienteId !== null && $c['cliente_id'] !== $clienteId) {
        throw new HttpError(404, 'No encontrado');
    }
    return $c;
}

/** Los datos del mail `comprobante_pago` (ya con formato), a partir de la foto. */
function comprobante_pago_mail_datos(array $c): array
{
    $d = $c['datos'];
    $p = $d['periodo'] ?? null;
    return [
        'nombre' => (string)($d['cliente']['nombre'] ?? ''),
        'numero' => $c['numero'],
        'fecha' => fecha_corta_ar((string)$d['fecha']),
        'concepto' => (string)$d['concepto'],
        'periodo' => is_array($p) ? fecha_corta_ar($p['desde']) . ' al ' . fecha_corta_ar($p['hasta']) : '',
        'medio' => (string)($d['medio'] ?? ''),
        'monto' => comprobante_monto_texto((string)$d['monto'], (string)$d['moneda']),
        'emisor' => (string)($d['emisor']['nombre'] ?? 'VEZZA'),
        'link' => portal_url('/clientes/comprobante?n=' . $c['numero']),
        'leyenda' => COMPROBANTE_LEYENDA,
    ];
}

/**
 * Manda (o vuelve a mandar) el comprobante por mail. Nunca lanza por el mail: devuelve 'enviado', 'no_enviado' (n8n falló, queda
 * el error para reintentar) o 'sin_mail' (el cliente no tiene casilla). Un comprobante anulado no se manda.
 */
function comprobante_pago_enviar(int $id): string
{
    $c = comprobante_pago_get($id);
    if ($c['anulado']) {
        throw new HttpError(409, 'El comprobante está anulado.');
    }
    // La casilla se vuelve a buscar: si el cliente cargó o cambió su mail después, el reenvío va a la nueva.
    $destino = comprobante_pago_destino($c['cliente_id']);
    if ($destino === null) {
        db()->prepare('UPDATE comprobantes_pago SET destino = NULL, error = ? WHERE id = ?')->execute(['El cliente no tiene mail cargado', $id]);
        return 'sin_mail';
    }
    $ok = mail_enviar('comprobante_pago', $destino, comprobante_pago_mail_datos($c));
    db()->prepare('UPDATE comprobantes_pago SET destino = ?, enviado_en = ' . ($ok ? 'NOW()' : 'enviado_en') . ', error = ? WHERE id = ?')
        ->execute([$destino, $ok ? null : 'No salió el mail (n8n no respondió bien)', $id]);
    return $ok ? 'enviado' : 'no_enviado';
}

/** "Emitir comprobante" del admin para un cobro pagado que no lo tiene (un cobro suelto, o uno anterior a los comprobantes). */
function comprobante_pago_emitir_y_enviar(int $cobroId): array
{
    $id = tx(fn(): int => comprobante_pago_emitir($cobroId));
    return ['comprobante' => comprobante_pago_get($id), 'aviso' => comprobante_pago_enviar($id)];
}

/**
 * Lo que le pasa al comprobante de un cobro cuando el admin lo edita: si vuelve a pendiente, se anula; si sigue pagado pero cambió
 * algo de lo que dice el comprobante, se anula y se emite uno nuevo (que hay que mandar después: devuelve su id).
 */
function comprobante_pago_tras_editar_cobro(array $antes, array $despues): ?int
{
    $vigente = comprobante_pago_del_cobro((int)$antes['id']);
    if ($vigente === null) {
        return null;
    }
    if ($despues['estado'] !== 'pagado') {
        comprobante_pago_anular((int)$vigente['id'], 'El pago volvió a pendiente');
        return null;
    }
    foreach (COMPROBANTE_CAMPOS_DEL_COBRO as $campo) {
        if ((string)$antes[$campo] !== (string)$despues[$campo]) {
            $periodo = (json_decode((string)$vigente['datos'], true) ?: [])['periodo'] ?? null;
            comprobante_pago_anular((int)$vigente['id'], 'Se corrigió el pago: lo reemplaza un comprobante nuevo');
            return comprobante_pago_emitir((int)$antes['id'], $periodo);
        }
    }
    return null;
}

/** Los comprobantes de un cliente, del más nuevo al más viejo (ficha del admin y portal). */
function comprobantes_pago_de_cliente(int $clienteId): array
{
    return array_map('comprobante_pago_publico', q_all('SELECT * FROM comprobantes_pago WHERE cliente_id = ? ORDER BY id DESC', [$clienteId]));
}

/** El comprobante listo para imprimir (lo usan la página del admin y la del portal). Todo sale de la foto. */
function comprobante_pago_html(array $c): string
{
    $d = $c['datos'];
    $m = comprobante_pago_mail_datos($c);
    $fila = fn(string $k, string $v): string => $v === '' ? '' : '<div class="cp-fila"><dt>' . e($k) . '</dt><dd>' . e($v) . '</dd></div>';
    $emisor = $d['emisor'] ?? [];
    $html = '<article class="cp' . ($c['anulado'] ? ' cp-anulado' : '') . '">';
    $html .= '<header class="cp-head"><div><div class="cp-emisor">' . e((string)($emisor['nombre'] ?? 'VEZZA')) . '</div>';
    foreach (['cuit' => 'CUIT ', 'contacto' => ''] as $k => $pre) {
        if (!empty($emisor[$k])) {
            $html .= '<div class="cp-sub">' . e($pre . $emisor[$k]) . '</div>';
        }
    }
    $html .= '</div><div class="cp-tipo"><div>Comprobante de pago</div><div class="cp-numero">N° ' . e($c['numero']) . '</div>'
        . '<div class="cp-sub">' . e($m['fecha']) . '</div></div></header>';
    if ($c['anulado']) {
        $html .= '<p class="cp-sello" role="note">ANULADO el ' . e(fecha_corta_ar((string)$c['anulado_en']))
            . ($c['motivo_anulacion'] ? ' · ' . e($c['motivo_anulacion']) : '') . '</p>';
    }
    $html .= '<dl class="cp-datos">' . $fila('Cliente', $m['nombre']) . $fila('Concepto', $m['concepto'])
        . $fila('Período', $m['periodo']) . $fila('Medio de pago', $m['medio']) . '</dl>';
    $html .= '<div class="cp-total"><span>Total pagado</span><strong>' . e($m['monto']) . '</strong></div>';
    $html .= '<footer class="cp-leyenda">' . e(COMPROBANTE_LEYENDA) . '</footer></article>';
    return $html;
}

/** La página entera del comprobante: solo el comprobante, un botón Imprimir y un link para volver (que no se imprimen). */
function comprobante_pago_pagina(array $c, string $volverHref, string $volverTexto): void
{
    send_panel_headers();
    header('Content-Type: text/html; charset=utf-8');
    $v = PANEL_ASSET_V;
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
        . '<meta name="color-scheme" content="light"><title>Comprobante ' . e($c['numero']) . ' · VEZZA</title>'
        . '<link rel="icon" href="/assets/icons/favicon.svg" type="image/svg+xml">'
        . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Sora:wght@600;700&display=swap">'
        . '<link rel="stylesheet" href="/admin/assets/admin.css?v=' . $v . '"></head><body class="cp-pagina">'
        . '<nav class="cp-acciones"><a class="btn" href="' . e($volverHref) . '">' . e($volverTexto) . '</a>'
        . '<button class="btn btn-primario" type="button" onclick="window.print()">Imprimir o guardar PDF</button></nav>'
        . '<main>' . comprobante_pago_html($c) . '</main></body></html>';
}
