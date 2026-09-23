<?php
declare(strict_types=1);

const LOGIN_MAX_INTENTOS = 5;
const LOGIN_VENTANA_MIN = 15;
const SESION_DURACION = 604800; // 7 días

/** Carpeta propia de sesiones, fuera de public_html: la limpieza respeta los 7 días de este panel. */
function sessions_dir(bool $crear = true): string
{
    $dir = env('SESSIONS_DIR') ?? dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'vezza_sessions';
    if ($crear && !is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function session_boot(): void
{
    if (PHP_SAPI === 'cli') {
        $_SESSION ??= [];
        return;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $dir = sessions_dir();
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    } else {
        error_log("[panel] No se pudo usar la carpeta de sesiones $dir; se usa la del hosting");
    }
    ini_set('session.gc_maxlifetime', (string)SESION_DURACION);
    ini_set('session.use_strict_mode', '1');
    session_name('vezza_admin');
    $params = [
        'lifetime' => SESION_DURACION,
        'path' => '/',
        'secure' => env('APP_ENV') === 'production',
        'httponly' => true,
        'samesite' => 'Strict',
    ];
    session_set_cookie_params($params);
    session_start();
    if (!empty($_SESSION['admin'])) {
        if (time() - (int)($_SESSION['last_activity'] ?? 0) > SESION_DURACION) {
            $_SESSION = [];
            return;
        }
        $_SESSION['last_activity'] = time();
        setcookie(session_name(), session_id(), [
            'expires' => time() + SESION_DURACION,
            'path' => '/',
            'secure' => $params['secure'],
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}

function auth_logged(): bool
{
    return !empty($_SESSION['admin']);
}

function login_bloqueado(string $ip): bool
{
    $n = (int)q_val(
        'SELECT COUNT(*) FROM login_intentos WHERE ip = ? AND creado_en > (NOW() - INTERVAL ' . LOGIN_VENTANA_MIN . ' MINUTE)',
        [$ip]
    );
    return $n >= LOGIN_MAX_INTENTOS;
}

function auth_attempt(string $usuario, string $clave, string $ip): string
{
    db()->exec('DELETE FROM login_intentos WHERE creado_en < (NOW() - INTERVAL 1 DAY)');
    if (login_bloqueado($ip)) {
        return 'bloqueado';
    }
    $usuarioEsperado = env('ADMIN_USERNAME');
    $hash = env('ADMIN_PASSWORD_HASH');
    // Las dos comprobaciones se hacen siempre para no filtrar por timing cuál falló.
    $usuarioOk = hash_equals((string)$usuarioEsperado, $usuario);
    $claveOk = password_verify($clave, $hash ?? '$2y$04$invalidinvalidinvalidinvalidinvalidinvalidinvalidin');
    if ($usuarioEsperado !== null && $hash !== null && $usuarioOk && $claveOk) {
        db()->prepare('DELETE FROM login_intentos WHERE ip = ?')->execute([$ip]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['admin'] = true;
        $_SESSION['last_activity'] = time();
        unset($_SESSION['csrf']);
        return 'ok';
    }
    db()->prepare('INSERT INTO login_intentos (ip) VALUES (?)')->execute([$ip]);
    return 'invalido';
}

function auth_logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $p['path'],
            'secure' => $p['secure'],
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_destroy();
    }
}

function safe_next(?string $next): string
{
    if ($next !== null
        && preg_match('#^/admin(?:/[A-Za-z0-9/_-]*)?(?:\?[A-Za-z0-9=&_%-]*)?$#D', $next)
        && !str_contains($next, '//')) {
        return $next;
    }
    return '/admin';
}

function require_admin(): void
{
    session_boot();
    if (!auth_logged()) {
        $next = safe_next(is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : null);
        header('Location: /admin-login?next=' . rawurlencode($next), true, 302);
        exit;
    }
}

function require_admin_api(): void
{
    if (!auth_logged()) {
        throw new HttpError(401, 'Sesión expirada');
    }
}

function client_ip(): string
{
    return is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}
