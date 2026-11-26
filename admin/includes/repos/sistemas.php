<?php
declare(strict_types=1);

/*
 * Sistemas de los clientes conectados al admin. Cada sistema (el que le hicimos a un cliente, con su repo y su URL) tiene un token
 * propio: con él, el servidor de ese sistema lee el estado de la cuenta de su cliente en /api/sistema-cuenta y se lo muestra a su
 * usuario en el módulo "Acceder a VEZZA". El token se muestra una sola vez; se guarda solo su hash.
 */

/** Cada cuánto se vuelve a escribir el último contacto de un sistema (no hace falta una escritura por consulta). */
const SISTEMA_CONTACTO_CADA_MIN = 5;
/** Pagos que se le muestran al sistema: los últimos. */
const SISTEMA_PAGOS_MAX = 12;

function sistemas_schema(): array
{
    return [
        'cliente_id' => ['type' => 'fk', 'table' => 'clientes', 'required' => true],
        'nombre' => ['type' => 'string', 'required' => true],
        'repo' => ['type' => 'string', 'max' => 255],
        'url' => ['type' => 'url'],
    ];
}

/** 'https://github.com/dueño/repo(.git)' o 'dueño/repo' → 'dueño/repo'. Cualquier otra cosa no es un repo de GitHub. */
function sistema_repo_normalizar(string $repo): string
{
    $r = (string)preg_replace('#^(https?://)?(www\.)?github\.com/#i', '', trim($repo));
    $r = (string)preg_replace('#(\.git)?/?$#i', '', $r);
    if (!preg_match('#^[A-Za-z0-9-]{1,39}/[A-Za-z0-9._-]{1,100}$#', $r)) {
        throw new HttpError(422, 'Revisá los datos marcados', ['repo' => 'Poné el repo como dueño/nombre (ej. valentinomezzavilla/pesca_valle_viejo)']);
    }
    return $r;
}

function sistema_validar(array $input, bool $parcial = false): array
{
    $schema = sistemas_schema();
    if ($parcial) {
        unset($schema['cliente_id']);
    }
    $datos = validate($input, $schema, $parcial);
    if (isset($datos['repo'])) {
        $datos['repo'] = sistema_repo_normalizar($datos['repo']);
    }
    return $datos;
}

/** Lo que ve el admin de un sistema: nunca el hash del token, solo si tiene uno. */
function sistema_publico(array $s): array
{
    $s['conectado'] = $s['token_hash'] !== null;
    unset($s['token_hash']);
    return $s;
}

function sistemas_list(array $get): array
{
    $f = filtros($get, ['cliente_id' => ['type' => 'int']]);
    [$partes, $params] = where_eq($f, ['cliente_id' => 'cliente_id']);
    return array_map('sistema_publico', q_all('SELECT * FROM sistemas' . sql_where($partes) . ' ORDER BY nombre, id', $params));
}

function sistemas_get(int $id): array
{
    return sistema_publico(crud_find('sistemas', $id));
}

function sistema_token_hash(string $token): string
{
    return hash('sha256', $token);
}

/** Genera un token nuevo para el sistema (el anterior deja de funcionar) y lo devuelve en claro, por única vez. */
function sistema_token_generar(int $id): array
{
    crud_find('sistemas', $id);
    $token = 'vz_' . bin2hex(random_bytes(24));
    crud_update('sistemas', $id, ['token_hash' => sistema_token_hash($token), 'token_generado_en' => date('Y-m-d H:i:s')]);
    return sistemas_get($id) + ['token' => $token];
}

/** Crea el sistema y ya le genera su token: la respuesta trae `token` en claro, que no se vuelve a mostrar. */
function sistemas_create(array $input): array
{
    $id = crud_insert('sistemas', sistema_validar($input));
    return sistema_token_generar($id);
}

function sistemas_update(int $id, array $input): array
{
    crud_update('sistemas', $id, sistema_validar($input, true));
    return sistemas_get($id);
}

function sistemas_delete(int $id): void
{
    crud_delete('sistemas', $id);
}

/** El sistema dueño del token, o null si no hay ninguno (token inventado, viejo o vacío). */
function sistema_por_token(string $token): ?array
{
    if (!preg_match('/^vz_[0-9a-f]{48}$/', $token)) {
        return null;
    }
    return q_one('SELECT * FROM sistemas WHERE token_hash = ?', [sistema_token_hash($token)]);
}

/** El token que manda el sistema en `Authorization: Bearer ...` (algunos Apache lo dejan en REDIRECT_HTTP_AUTHORIZATION). */
function sistema_token_del_request(): string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    return is_string($h) && preg_match('/^Bearer\s+(\S+)$/i', trim($h), $m) ? $m[1] : '';
}

function sistema_registrar_contacto(int $id): void
{
    db()->prepare(
        'UPDATE sistemas SET ultimo_contacto = NOW()
         WHERE id = ? AND (ultimo_contacto IS NULL OR ultimo_contacto < NOW() - INTERVAL ' . SISTEMA_CONTACTO_CADA_MIN . ' MINUTE)'
    )->execute([$id]);
}

/**
 * El estado de la cuenta del cliente dueño del sistema, tal como se le muestra en su módulo "Acceder a VEZZA": sus suscripciones,
 * sus últimos pagos y lo que tiene pendiente. Solo datos del propio cliente, y nada interno: ni ids, ni datos de Mercado Pago,
 * ni notas. Solo lee (salvo el último contacto del sistema).
 */
function sistema_cuenta(array $sistema): array
{
    $clienteId = (int)$sistema['cliente_id'];
    $cliente = crud_find('clientes', $clienteId);

    $suscripciones = array_map(function (array $s): array {
        $e = suscripcion_estado_cobro($s['fecha_proximo_cobro']);
        $estado = $s['estado'] === 'cancelada' ? 'cancelada' : ((int)$s['pausada'] ? 'pausada' : 'activa');
        return [
            'plan' => $s['plan_nombre'],
            'descripcion' => $s['plan_descripcion'],
            'monto' => $s['monto'],
            'moneda' => $s['moneda'],
            'frecuencia' => $s['frecuencia'],
            'modalidad' => $s['modalidad'],
            'debito_activo' => $s['modalidad'] === 'debito' && $s['mp_preapproval_estado'] === 'autorizada',
            'estado' => $estado,
            'fecha_inicio' => $s['fecha_inicio'],
            'pago_hasta' => $s['fecha_proximo_cobro'],
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

    $pagos = q_all(
        "SELECT fecha_pago AS fecha, concepto, monto, moneda, metodo_pago AS metodo FROM cobros
         WHERE cliente_id = ? AND estado = 'pagado' ORDER BY fecha_pago DESC, id DESC LIMIT " . SISTEMA_PAGOS_MAX,
        [$clienteId]
    );
    $pendientes = q_all(
        "SELECT concepto, monto, moneda, fecha_vencimiento AS vencimiento FROM cobros
         WHERE cliente_id = ? AND estado = 'pendiente' ORDER BY fecha_vencimiento, id",
        [$clienteId]
    );

    return [
        'cliente' => ['nombre' => $cliente['nombre']],
        'sistema' => ['nombre' => $sistema['nombre']],
        'suscripciones' => $suscripciones,
        'pagos' => $pagos,
        'pendientes' => $pendientes,
        'generado_en' => date(DATE_ATOM),
    ];
}

/**
 * El endpoint que consultan los sistemas: GET /api/sistema-cuenta con `Authorization: Bearer <token>`. No usa la sesión del admin
 * ni CSRF (lo llama el servidor del sistema, no un navegador). Un token que no existe da 401, sin decir por qué.
 */
function sistema_api_run(): void
{
    send_panel_headers();
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            throw new HttpError(405, 'Método no permitido');
        }
        $sistema = sistema_por_token(sistema_token_del_request());
        if ($sistema === null) {
            throw new HttpError(401, 'Token inválido');
        }
        sistema_registrar_contacto((int)$sistema['id']);
        json_out(200, ['data' => sistema_cuenta($sistema)]);
    } catch (HttpError $e) {
        json_out($e->status, ['error' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('[sistemas] ' . $e);
        json_out(500, ['error' => 'Error interno']);
    }
}
