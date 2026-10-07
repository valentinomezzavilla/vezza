<?php
declare(strict_types=1);

/** Cuánto antes del intervalo se considera vencido un chequeo (el cron pasa cada minuto y no es exacto). */
const MONITOR_HOLGURA_SEG = 30;
const MONITOR_RETENCION_DIAS = 30;
const MONITOR_RETENCION_EVENTOS_DIAS = 365;
/** Tope de la cuenta de fallos seguidos: la columna es SMALLINT y un servicio puede quedar caído semanas. */
const MONITOR_TOPE_FALLOS = 1000;
/** Un "Chequear ahora" desde el navegador corta acá para no pasarse del límite de tiempo del hosting. */
const MONITOR_LIMITE_WEB_SEG = 20;

final class Monitor
{
    /** @var (callable(array): array)|null Sonda de prueba: recibe el servicio y devuelve el resultado del chequeo. */
    public static $sonda = null;
    /** @var (callable(string): array)|null Resolución de nombres de prueba: recibe el host y devuelve las IP. */
    public static $resolver = null;
    /** Solo para tests: deja chequear 127.0.0.1 y redes privadas. En producción nunca se activa. */
    public static bool $permitirPrivados = false;
}

// ---------- Validación ----------

function monitor_schema(): array
{
    return [
        'grupo' => ['type' => 'string', 'max' => 100],
        'nombre' => ['type' => 'string', 'required' => true],
        'tipo' => ['type' => 'enum', 'values' => ['http', 'tcp', 'ssl'], 'required' => true],
        'destino' => ['type' => 'string', 'max' => 500, 'required' => true],
        'puerto' => ['type' => 'int', 'min' => 1, 'max' => 65535],
        'codigo_esperado' => ['type' => 'int', 'min' => 100, 'max' => 599],
        'timeout_seg' => ['type' => 'int', 'min' => 1, 'max' => 30, 'notnull' => true],
        'intervalo_min' => ['type' => 'int', 'min' => 1, 'max' => 1440, 'notnull' => true],
        'fallos_para_caer' => ['type' => 'int', 'min' => 1, 'max' => 10, 'notnull' => true],
        'ssl_dias_aviso' => ['type' => 'int', 'min' => 1, 'max' => 90, 'notnull' => true],
        'activo' => ['type' => 'bool', 'notnull' => true],
    ];
}

function monitor_host_valido(string $host): bool
{
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return true;
    }
    return (bool)preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $host);
}

/** Que el destino tenga sentido para el tipo elegido, y completa lo que se puede deducir (SSL → 443). */
function monitor_coherencia(array $f): array
{
    $errores = [];
    $destino = (string)$f['destino'];
    if ($f['tipo'] === 'http') {
        $partes = parse_url($destino);
        $esquema = strtolower((string)($partes['scheme'] ?? ''));
        if (filter_var($destino, FILTER_VALIDATE_URL) === false || !in_array($esquema, ['http', 'https'], true) || empty($partes['host'])) {
            $errores['destino'] = 'Poné la URL completa, con https://';
        } elseif (isset($partes['user']) || isset($partes['pass'])) {
            $errores['destino'] = 'La URL no puede llevar usuario ni contraseña';
        }
        $f['puerto'] = null; // va dentro de la URL
    } else {
        if (!monitor_host_valido($destino)) {
            $errores['destino'] = 'Poné solo el dominio o la IP, sin https:// ni barras';
        }
        if (empty($f['puerto'])) {
            if ($f['tipo'] === 'ssl') {
                $f['puerto'] = 443;
            } else {
                $errores['puerto'] = 'Es obligatorio para chequear un puerto';
            }
        }
        $f['codigo_esperado'] = null;
    }
    if ($errores) {
        throw new HttpError(422, 'Revisá los datos marcados', $errores);
    }
    return $f;
}

// ---------- Red: qué destinos se pueden chequear ----------

function monitor_ip_publica(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function monitor_resolver(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return [$host];
    }
    $ips = [];
    foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $registro) {
        $ips[] = $registro['ip'] ?? $registro['ipv6'] ?? null;
    }
    if (!$ips) {
        $ips = @gethostbynamel($host) ?: [];
    }
    return array_values(array_unique(array_filter($ips, 'is_string')));
}

/**
 * Resuelve el host y se asegura de que TODAS sus IP sean públicas: el panel no tiene que poder
 * usarse para golpear servicios internos del hosting (SSRF).
 * @return array{0: ?string, 1: ?string} [IP a usar, error]
 */
function monitor_destino_seguro(string $host): array
{
    $ips = Monitor::$resolver !== null ? (Monitor::$resolver)($host) : monitor_resolver($host);
    if (!$ips) {
        return [null, 'No se pudo resolver el dominio'];
    }
    if (!Monitor::$permitirPrivados) {
        foreach ($ips as $ip) {
            if (!monitor_ip_publica($ip)) {
                return [null, 'Destino no permitido: apunta a una red privada o reservada'];
            }
        }
    }
    return [$ips[0], null];
}

function monitor_host_de_url(string $url): string
{
    return trim((string)parse_url($url, PHP_URL_HOST), '[]');
}

function monitor_addr(string $ip, int $puerto): string
{
    return (str_contains($ip, ':') ? "[$ip]" : $ip) . ':' . $puerto;
}

// ---------- Sondas ----------

function monitor_resultado(bool $ok, ?int $latenciaMs, string $detalle, ?string $sslVence = null): array
{
    return [
        'ok' => $ok,
        'latencia_ms' => $latenciaMs,
        'detalle' => mb_substr($detalle, 0, 255),
        'ssl_vence' => $sslVence,
    ];
}

/**
 * Corre $fn y devuelve [su resultado, los avisos de PHP que dejó]. Cuando un socket falla, el motivo real
 * (certificado inválido, conexión rechazada…) viene en esos avisos y no en $errstr, que suele quedar vacío.
 */
function monitor_con_avisos(callable $fn): array
{
    $mensajes = [];
    set_error_handler(function (int $nivel, string $mensaje) use (&$mensajes): bool {
        $mensajes[] = $mensaje;
        return true;
    });
    try {
        $resultado = $fn();
    } finally {
        restore_error_handler();
    }
    return [$resultado, implode(' | ', $mensajes)];
}

function monitor_traducir_error(string $mensaje): string
{
    $m = strtolower($mensaje);
    return match (true) {
        // Los mensajes en español son los de Windows (entorno de desarrollo); en el hosting vienen en inglés.
        str_contains($m, 'timed out'), str_contains($m, 'timeout'), str_contains($m, 'no respondi') => 'tiempo de espera agotado',
        str_contains($m, 'refused'), str_contains($m, 'denegó') => 'conexión rechazada',
        str_contains($m, 'certificate'), str_contains($m, 'verify') => 'certificado inválido',
        str_contains($m, 'getaddrinfo'), str_contains($m, 'resolve') => 'no se pudo resolver el dominio',
        default => mb_substr(trim($mensaje), 0, 120) ?: 'sin detalle',
    };
}

function monitor_status_ok(int $codigo, ?int $esperado): bool
{
    return $esperado !== null ? $codigo === $esperado : ($codigo >= 200 && $codigo <= 399);
}

function monitor_duracion_texto(int $segundos): string
{
    if ($segundos < 60) {
        return 'menos de 1 min';
    }
    if ($segundos < 3600) {
        return intdiv($segundos, 60) . ' min';
    }
    if ($segundos < 86400) {
        $h = intdiv($segundos, 3600);
        $m = intdiv($segundos % 3600, 60);
        return $m > 0 ? "$h h $m min" : "$h h";
    }
    $d = intdiv($segundos, 86400);
    $h = intdiv($segundos % 86400, 3600);
    return $h > 0 ? "$d d $h h" : "$d d";
}

/** Decide si un certificado está bien. $vence y $ahora son timestamps. */
function monitor_evaluar_cert(int $vence, int $ahora, int $diasAviso, bool $confiable): array
{
    $diff = $vence - $ahora;
    $fecha = date('d/m/Y', $vence);
    $vencidoHace = intdiv(-$diff, 86400);
    $restan = intdiv($diff, 86400);
    $plural = static fn(int $n): string => $n === 1 ? '1 día' : "$n días";

    if ($diff < 0) {
        $texto = $vencidoHace === 0 ? 'Venció hoy' : 'Venció hace ' . $plural($vencidoHace);
        $ok = false;
    } elseif ($restan < $diasAviso) {
        $texto = $restan === 0 ? "Vence hoy ($fecha)" : 'Vence en ' . $plural($restan) . " ($fecha)";
        $ok = false;
    } else {
        $texto = 'Válido, vence en ' . $plural($restan) . " ($fecha)";
        $ok = true;
    }
    if (!$confiable) {
        // PHP no dice por qué falló la verificación: si no está vencido, es de otro dominio o lo firmó alguien desconocido.
        $texto = $diff < 0
            ? 'El certificado no es confiable: ' . lcfirst($texto)
            : "El certificado no es confiable: no corresponde al dominio o lo emitió una entidad desconocida (vence el $fecha)";
        $ok = false;
    }
    return ['ok' => $ok, 'detalle' => $texto, 'ssl_vence' => date('Y-m-d', $vence)];
}

function monitor_sondear(array $s): array
{
    try {
        if (Monitor::$sonda !== null) {
            return (Monitor::$sonda)($s);
        }
        return match ($s['tipo']) {
            'http' => monitor_sonda_http($s),
            'tcp' => monitor_sonda_tcp($s),
            'ssl' => monitor_sonda_ssl($s),
            default => monitor_resultado(false, null, 'Tipo de chequeo desconocido'),
        };
    } catch (Throwable $e) {
        error_log('[monitor] La sonda falló: ' . $e);
        return monitor_resultado(false, null, 'Error al chequear: ' . $e->getMessage());
    }
}

function monitor_sonda_http(array $s): array
{
    [, $error] = monitor_destino_seguro(monitor_host_de_url((string)$s['destino']));
    if ($error !== null) {
        return monitor_resultado(false, null, $error);
    }
    $contexto = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => (float)$s['timeout_seg'],
            // Sin redirecciones: un 301 es una respuesta válida y no se puede usar para saltar a otro destino.
            'follow_location' => 0,
            'ignore_errors' => true,
            'header' => "User-Agent: VEZZA-Monitor/1.0\r\nAccept: */*\r\nConnection: close\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $inicio = hrtime(true);
    [$fp, $avisos] = monitor_con_avisos(fn() => fopen((string)$s['destino'], 'rb', false, $contexto));
    $ms = (int)round((hrtime(true) - $inicio) / 1e6);
    if ($fp === false) {
        return monitor_resultado(false, null, 'No se pudo conectar (' . monitor_traducir_error($avisos) . ')');
    }
    $cabeceras = stream_get_meta_data($fp)['wrapper_data'] ?? [];
    fclose($fp);
    $codigo = null;
    foreach ($cabeceras as $linea) {
        if (is_string($linea) && preg_match('#^HTTP/\S+\s+(\d{3})#', $linea, $m)) {
            $codigo = (int)$m[1];
            break;
        }
    }
    if ($codigo === null) {
        return monitor_resultado(false, $ms, 'Respuesta HTTP inválida');
    }
    $esperado = $s['codigo_esperado'] === null ? null : (int)$s['codigo_esperado'];
    $ok = monitor_status_ok($codigo, $esperado);
    $detalle = "HTTP $codigo" . (!$ok && $esperado !== null ? " (se esperaba $esperado)" : '');
    return monitor_resultado($ok, $ms, $detalle);
}

function monitor_sonda_tcp(array $s): array
{
    [$ip, $error] = monitor_destino_seguro((string)$s['destino']);
    if ($error !== null) {
        return monitor_resultado(false, null, $error);
    }
    $inicio = hrtime(true);
    [$fp, $avisos] = monitor_con_avisos(function () use ($ip, $s, &$errstr) {
        return stream_socket_client('tcp://' . monitor_addr((string)$ip, (int)$s['puerto']), $errno, $errstr, (float)$s['timeout_seg']);
    });
    $ms = (int)round((hrtime(true) - $inicio) / 1e6);
    if ($fp === false) {
        return monitor_resultado(false, null, "El puerto {$s['puerto']} no responde (" . monitor_traducir_error(trim($errstr . ' ' . $avisos)) . ')');
    }
    fclose($fp);
    return monitor_resultado(true, $ms, "Puerto {$s['puerto']} abierto");
}

/** @return array{cert: mixed, ms: int, error: string} */
function monitor_leer_cert(string $ip, int $puerto, string $host, float $timeout, bool $verificar): array
{
    $contexto = stream_context_create(['ssl' => [
        'capture_peer_cert' => true,
        'verify_peer' => $verificar,
        'verify_peer_name' => $verificar,
        'peer_name' => $host,
        'SNI_enabled' => true,
        'SNI_server_name' => $host,
    ]]);
    $inicio = hrtime(true);
    [$fp, $avisos] = monitor_con_avisos(function () use ($ip, $puerto, $timeout, $contexto, &$errstr) {
        return stream_socket_client('ssl://' . monitor_addr($ip, $puerto), $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $contexto);
    });
    $ms = (int)round((hrtime(true) - $inicio) / 1e6);
    if ($fp === false) {
        return ['cert' => null, 'ms' => $ms, 'error' => trim($errstr . ' ' . $avisos)];
    }
    $cert = stream_context_get_params($fp)['options']['ssl']['peer_certificate'] ?? null;
    fclose($fp);
    return ['cert' => $cert, 'ms' => $ms, 'error' => ''];
}

function monitor_sonda_ssl(array $s): array
{
    $host = (string)$s['destino'];
    [$ip, $error] = monitor_destino_seguro($host);
    if ($error !== null) {
        return monitor_resultado(false, null, $error);
    }
    $puerto = (int)($s['puerto'] ?? 443);
    $timeout = (float)$s['timeout_seg'];
    $confiable = true;
    $lectura = monitor_leer_cert((string)$ip, $puerto, $host, $timeout, true);
    // Si el certificado no pasa la verificación, se lee igual para poder decir qué le pasa (vencido, otro dominio…).
    if ($lectura['cert'] === null && preg_match('/certificate|verify/i', $lectura['error'])) {
        $confiable = false;
        $lectura = monitor_leer_cert((string)$ip, $puerto, $host, $timeout, false);
    }
    if ($lectura['cert'] === null) {
        return monitor_resultado(false, null, 'No se pudo leer el certificado (' . monitor_traducir_error($lectura['error']) . ')');
    }
    $info = openssl_x509_parse($lectura['cert']);
    if (!is_array($info) || !isset($info['validTo_time_t'])) {
        return monitor_resultado(false, $lectura['ms'], 'No se pudo interpretar el certificado');
    }
    $r = monitor_evaluar_cert((int)$info['validTo_time_t'], time(), (int)$s['ssl_dias_aviso'], $confiable);
    return monitor_resultado($r['ok'], $lectura['ms'], $r['detalle'], $r['ssl_vence']);
}

// ---------- Motor de estados ----------

/** Guarda el chequeo y mueve el estado. Devuelve el evento (caída o recuperación) si hubo un cambio que avisar. */
function monitor_aplicar(array $s, array $r, string $ahora): ?array
{
    return tx(function () use ($s, $r, $ahora): ?array {
        crud_insert('monitor_chequeos', [
            'servicio_id' => $s['id'],
            'hecho_en' => $ahora,
            'ok' => $r['ok'] ? 1 : 0,
            'latencia_ms' => $r['latencia_ms'],
            'detalle' => $r['detalle'],
        ]);

        $estado = $s['estado'];
        $cambio = $s['ultimo_cambio'];
        $fallos = (int)$s['fallos_seguidos'];
        $evento = null;

        if ($r['ok']) {
            $fallos = 0;
            if ($estado === 'caido') {
                $evento = [
                    'tipo' => 'recuperacion',
                    'duracion_seg' => max(0, strtotime($ahora) - strtotime((string)$s['ultimo_cambio'])),
                ];
            }
            if ($estado !== 'ok') {
                $estado = 'ok';
                $cambio = $ahora;
            }
        } else {
            $fallos = min($fallos + 1, MONITOR_TOPE_FALLOS);
            if ($estado !== 'caido' && $fallos >= (int)$s['fallos_para_caer']) {
                $estado = 'caido';
                $cambio = $ahora;
                $evento = ['tipo' => 'caida', 'duracion_seg' => null];
            }
        }

        $cambios = [
            'estado' => $estado,
            'fallos_seguidos' => $fallos,
            'ultimo_chequeo' => $ahora,
            'ultimo_cambio' => $cambio,
            'ultima_latencia_ms' => $r['latencia_ms'],
            'ultimo_detalle' => $r['detalle'],
        ];
        if (!empty($r['ssl_vence'])) {
            $cambios['ssl_vence'] = $r['ssl_vence'];
        }
        crud_update('monitor_servicios', (int)$s['id'], $cambios);

        if ($evento === null) {
            return null;
        }
        $evento += ['servicio_id' => (int)$s['id'], 'creado_en' => $ahora, 'detalle' => $r['detalle']];
        $evento['id'] = crud_insert('monitor_eventos', $evento);
        return $evento;
    });
}

function monitor_destino_texto(array $s): string
{
    return $s['tipo'] === 'http' ? (string)$s['destino'] : $s['destino'] . ':' . $s['puerto'];
}

/** Manda el aviso por el webhook de mails. Nunca lanza: un mail que falla no puede frenar el monitoreo. */
function monitor_notificar(array $s, array $evento): bool
{
    $destinatario = env('MONITOR_ALERTA_EMAIL');
    if ($destinatario === null) {
        return false;
    }
    $caida = $evento['tipo'] === 'caida';
    $enviado = mail_enviar('monitor_alerta', $destinatario, [
        'evento' => $evento['tipo'],
        'asunto' => ($caida ? '[CAÍDO] ' : '[RECUPERADO] ') . $s['nombre'],
        'servicio' => $s['nombre'],
        'grupo' => $s['grupo'],
        'tipo' => $s['tipo'],
        'destino' => monitor_destino_texto($s),
        'detalle' => $evento['detalle'],
        'cuando' => $evento['creado_en'],
        'duracion' => $evento['duracion_seg'] === null ? null : monitor_duracion_texto((int)$evento['duracion_seg']),
        'panel' => portal_url('/admin/monitor'),
    ]);
    if ($enviado) {
        crud_update('monitor_eventos', (int)$evento['id'], ['notificado' => 1]);
    }
    return $enviado;
}

/** Chequea un servicio, guarda el resultado y avisa si cambió de estado. */
function monitor_chequear(array $s, string $ahora): ?array
{
    $evento = monitor_aplicar($s, monitor_sondear($s), $ahora);
    if ($evento !== null) {
        monitor_notificar($s, $evento);
    }
    return $evento;
}

function monitor_vencido(array $s, int $ahoraTs): bool
{
    if ($s['ultimo_chequeo'] === null) {
        return true;
    }
    return strtotime((string)$s['ultimo_chequeo']) <= $ahoraTs - (int)$s['intervalo_min'] * 60 + MONITOR_HOLGURA_SEG;
}

function monitor_tomar_lock(): bool
{
    return (int)q_val("SELECT GET_LOCK('vezza_monitor', 0)") === 1;
}

function monitor_soltar_lock(): void
{
    q_val("SELECT RELEASE_LOCK('vezza_monitor')");
}

/** @return array{chequeados: int, caidos: int, eventos: int, pendientes: int} */
function monitor_correr(array $servicios, string $ahora, ?int $limiteSeg): array
{
    $inicio = hrtime(true);
    $r = ['chequeados' => 0, 'caidos' => 0, 'eventos' => 0, 'pendientes' => 0];
    foreach ($servicios as $i => $s) {
        if ($limiteSeg !== null && (hrtime(true) - $inicio) / 1e9 > $limiteSeg) {
            $r['pendientes'] = count($servicios) - $i;
            break;
        }
        try {
            if (monitor_chequear($s, $ahora) !== null) {
                $r['eventos']++;
            }
            $r['chequeados']++;
            if (crud_find('monitor_servicios', (int)$s['id'])['estado'] === 'caido') {
                $r['caidos']++;
            }
        } catch (Throwable $e) {
            error_log('[monitor] No se pudo chequear el servicio ' . $s['id'] . ': ' . $e);
        }
    }
    return $r;
}

/** Lo que corre el cron: chequea solo los servicios cuyo intervalo ya pasó. */
function monitor_correr_vencidos(?string $ahora = null): array
{
    $ahora ??= date('Y-m-d H:i:s');
    if (!monitor_tomar_lock()) {
        return ['chequeados' => 0, 'caidos' => 0, 'eventos' => 0, 'pendientes' => 0, 'omitido' => true];
    }
    try {
        $ahoraTs = strtotime($ahora);
        $vencidos = array_values(array_filter(
            q_all('SELECT * FROM monitor_servicios WHERE activo = 1 ORDER BY ultimo_chequeo, id'),
            fn(array $s): bool => monitor_vencido($s, $ahoraTs)
        ));
        $resumen = monitor_correr($vencidos, $ahora, null);
        monitor_limpiar($ahora);
        return $resumen + ['omitido' => false];
    } finally {
        monitor_soltar_lock();
    }
}

/** El botón "Chequear ahora": todos los activos, o uno solo (aunque esté pausado), sin esperar al intervalo. */
function monitor_chequear_ahora(?int $id, ?string $ahora = null): array
{
    $ahora ??= date('Y-m-d H:i:s');
    $servicios = $id !== null
        ? [crud_find('monitor_servicios', $id)]
        : q_all('SELECT * FROM monitor_servicios WHERE activo = 1 ORDER BY id');
    if (!monitor_tomar_lock()) {
        throw new HttpError(409, 'Ya hay un chequeo en curso. Probá de nuevo en unos segundos.');
    }
    try {
        return monitor_correr($servicios, $ahora, PHP_SAPI === 'cli' ? null : MONITOR_LIMITE_WEB_SEG);
    } finally {
        monitor_soltar_lock();
    }
}

function monitor_limpiar(string $ahora): void
{
    $ts = strtotime($ahora);
    db()->prepare('DELETE FROM monitor_chequeos WHERE hecho_en < ?')
        ->execute([date('Y-m-d H:i:s', $ts - MONITOR_RETENCION_DIAS * 86400)]);
    db()->prepare('DELETE FROM monitor_eventos WHERE creado_en < ?')
        ->execute([date('Y-m-d H:i:s', $ts - MONITOR_RETENCION_EVENTOS_DIAS * 86400)]);
}

// ---------- API ----------

function monitor_enriquecer(array $fila, ?array $agregado, string $ahora): array
{
    $total = (int)($agregado['total'] ?? 0);
    $fila['chequeos_24h'] = $total;
    $fila['uptime_24h'] = $total > 0 ? round((int)$agregado['buenos'] * 100 / $total, 2) : null;
    $recientes = q_all(
        'SELECT ok FROM monitor_chequeos WHERE servicio_id = ? ORDER BY hecho_en DESC, id DESC LIMIT 30',
        [$fila['id']]
    );
    $fila['recientes'] = array_reverse(array_map(fn(array $c): int => (int)$c['ok'], $recientes));
    $fila['hace_seg'] = $fila['ultimo_chequeo'] === null
        ? null
        : max(0, strtotime($ahora) - strtotime((string)$fila['ultimo_chequeo']));
    return $fila;
}

function monitor_agregados_24h(string $ahora, ?int $servicioId = null): array
{
    $sql = 'SELECT servicio_id, COUNT(*) AS total, SUM(ok) AS buenos FROM monitor_chequeos WHERE hecho_en >= ?';
    $params = [date('Y-m-d H:i:s', strtotime($ahora) - 86400)];
    if ($servicioId !== null) {
        $sql .= ' AND servicio_id = ?';
        $params[] = $servicioId;
    }
    $porServicio = [];
    foreach (q_all($sql . ' GROUP BY servicio_id', $params) as $fila) {
        $porServicio[(int)$fila['servicio_id']] = $fila;
    }
    return $porServicio;
}

function monitor_list(array $get): array
{
    $ahora = date('Y-m-d H:i:s');
    $agregados = monitor_agregados_24h($ahora);
    $filas = q_all('SELECT * FROM monitor_servicios ORDER BY grupo IS NULL, grupo, nombre, id');
    return array_map(fn(array $f): array => monitor_enriquecer($f, $agregados[(int)$f['id']] ?? null, $ahora), $filas);
}

function monitor_get(int $id): array
{
    $ahora = date('Y-m-d H:i:s');
    return monitor_enriquecer(crud_find('monitor_servicios', $id), monitor_agregados_24h($ahora, $id)[$id] ?? null, $ahora);
}

function monitor_create(array $input): array
{
    $datos = monitor_coherencia(validate($input, monitor_schema()));
    return monitor_get(crud_insert('monitor_servicios', $datos));
}

function monitor_update(int $id, array $input): array
{
    $actual = crud_find('monitor_servicios', $id);
    $final = monitor_coherencia(validate($input, monitor_schema(), true) + $actual);
    $cambios = array_intersect_key($final, monitor_schema());
    foreach (['tipo', 'destino', 'puerto'] as $clave) {
        if ((string)$cambios[$clave] !== (string)$actual[$clave]) {
            // Es otro destino: lo que se sabía del anterior ya no vale.
            $cambios += [
                'estado' => 'desconocido', 'fallos_seguidos' => 0, 'ultimo_chequeo' => null, 'ultimo_cambio' => null,
                'ultima_latencia_ms' => null, 'ultimo_detalle' => null, 'ssl_vence' => null,
            ];
            break;
        }
    }
    crud_update('monitor_servicios', $id, $cambios);
    return monitor_get($id);
}

function monitor_delete(int $id): void
{
    crud_delete('monitor_servicios', $id);
}

function monitor_eventos_list(array $get): array
{
    $f = filtros($get, [
        'servicio_id' => ['type' => 'int', 'min' => 1],
        'limite' => ['type' => 'int', 'min' => 1, 'max' => 100],
    ]);
    [$partes, $params] = where_eq($f, ['servicio_id' => 'e.servicio_id']);
    $limite = (int)($f['limite'] ?? 30);
    return q_all(
        'SELECT e.*, s.nombre AS servicio_nombre, s.grupo AS servicio_grupo
         FROM monitor_eventos e JOIN monitor_servicios s ON s.id = e.servicio_id'
        . sql_where($partes) . " ORDER BY e.creado_en DESC, e.id DESC LIMIT $limite",
        $params
    );
}
