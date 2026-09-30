<?php
declare(strict_types=1);

const CLIENTE_CLAVE_MIN = 6;
const CLIENTE_LOGIN_MAX_INTENTOS = 5; // por IP y por cuenta, dentro de LOGIN_VENTANA_MIN minutos

function session_boot_cliente(): void
{
    session_boot_ambito('vezza_cliente', 'cliente');
}

function cliente_email_normalizar(string $email): string
{
    return mb_strtolower(trim($email));
}

function cliente_validar_clave(string $clave): void
{
    $error = null;
    if (mb_strlen($clave) < CLIENTE_CLAVE_MIN) {
        $error = 'Tiene que tener al menos ' . CLIENTE_CLAVE_MIN . ' caracteres';
    } elseif (strlen($clave) > 72) {
        // bcrypt ignora lo que pasa de 72 bytes: se rechaza en vez de truncar en silencio.
        $error = 'Puede tener como máximo 72 caracteres';
    }
    if ($error !== null) {
        throw new HttpError(422, 'Revisá los datos marcados', ['clave' => $error]);
    }
}

function cliente_hash_falso(): string
{
    static $hash = null;
    // Hash real del mismo costo que los guardados: así la respuesta tarda igual exista o no la cuenta.
    return $hash ??= password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
}

function cliente_login_bloqueado(string $ip, string $email): bool
{
    $ventana = 'creado_en > (NOW() - INTERVAL ' . LOGIN_VENTANA_MIN . ' MINUTE)';
    $porIp = (int)q_val("SELECT COUNT(*) FROM login_intentos WHERE ambito = 'cliente' AND ip = ? AND $ventana", [$ip]);
    $porCuenta = (int)q_val("SELECT COUNT(*) FROM login_intentos WHERE ambito = 'cliente' AND clave = ? AND $ventana", [$email]);
    return $porIp >= CLIENTE_LOGIN_MAX_INTENTOS || $porCuenta >= CLIENTE_LOGIN_MAX_INTENTOS;
}

function cliente_auth_attempt(string $email, string $clave, string $ip): string
{
    $email = mb_substr(cliente_email_normalizar($email), 0, 255);
    db()->exec('DELETE FROM login_intentos WHERE creado_en < (NOW() - INTERVAL 1 DAY)');
    if (cliente_login_bloqueado($ip, $email)) {
        return 'bloqueado';
    }
    $u = q_one('SELECT * FROM usuarios_cliente WHERE email = ?', [$email]);
    $puedeEntrar = $u !== null && (int)$u['activo'] === 1 && $u['password_hash'] !== null;
    // Se verifica siempre contra algún hash para no filtrar por timing qué cuentas existen.
    $claveOk = password_verify($clave, $puedeEntrar ? $u['password_hash'] : cliente_hash_falso());
    if ($puedeEntrar && $claveOk) {
        db()->prepare("DELETE FROM login_intentos WHERE ambito = 'cliente' AND clave = ?")->execute([$email]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['cliente'] = ['usuario_id' => (int)$u['id']];
        $_SESSION['last_activity'] = time();
        unset($_SESSION['csrf']);
        db()->prepare('UPDATE usuarios_cliente SET ultimo_login = NOW() WHERE id = ?')->execute([$u['id']]);
        return 'ok';
    }
    db()->prepare("INSERT INTO login_intentos (ip, ambito, clave) VALUES (?, 'cliente', ?)")->execute([$ip, $email]);
    return 'invalido';
}

function cliente_logged(): bool
{
    return !empty($_SESSION['cliente']);
}

/**
 * Usuario del portal con la sesión abierta, o null. Se relee de la base en cada request:
 * desactivar un acceso corta la sesión en el siguiente request.
 */
function cliente_actual(): ?array
{
    $s = $_SESSION['cliente'] ?? null;
    if (!is_array($s) || !isset($s['usuario_id'])) {
        return null;
    }
    $u = q_one(
        'SELECT id AS usuario_id, cliente_id, email FROM usuarios_cliente WHERE id = ? AND activo = 1 AND password_hash IS NOT NULL',
        [(int)$s['usuario_id']]
    );
    if ($u === null) {
        unset($_SESSION['cliente']);
        return null;
    }
    return ['usuario_id' => (int)$u['usuario_id'], 'cliente_id' => (int)$u['cliente_id'], 'email' => $u['email']];
}

/** Guard de páginas del portal: devuelve el cliente_id de la sesión o redirige al login. */
function require_cliente(): int
{
    session_boot_cliente();
    $u = cliente_actual();
    if ($u === null) {
        header('Location: /clientes/login', true, 302);
        exit;
    }
    return $u['cliente_id'];
}

/** Guard de la API del portal: devuelve el cliente_id de la sesión o responde 401. */
function require_cliente_api(): int
{
    $u = cliente_actual();
    if ($u === null) {
        throw new HttpError(401, 'Sesión expirada');
    }
    return $u['cliente_id'];
}

function cliente_logout(): void
{
    auth_logout();
}
