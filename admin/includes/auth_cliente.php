<?php
declare(strict_types=1);

const CLIENTE_CLAVE_MIN = 6;
const CLIENTE_LOGIN_MAX_INTENTOS = 5; // por IP y por cuenta, dentro de LOGIN_VENTANA_MIN minutos
const CLIENTE_TOKEN_HORAS = ['invitacion' => 72, 'recuperacion' => 2];
const RECUPERAR_MAX_POR_IP = 5; // pedidos de recuperación por IP, dentro de LOGIN_VENTANA_MIN minutos

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

/**
 * Emite un token de un solo uso y devuelve el valor en claro (es lo que va en el link).
 * En la base queda solo su sha256, y un token nuevo invalida el anterior del mismo usuario.
 */
function cliente_token_emitir(int $usuarioId, string $tipo): string
{
    if (!isset(CLIENTE_TOKEN_HORAS[$tipo])) {
        throw new InvalidArgumentException("Tipo de token desconocido: $tipo");
    }
    $plano = bin2hex(random_bytes(32));
    $horas = CLIENTE_TOKEN_HORAS[$tipo];
    db()->prepare("UPDATE usuarios_cliente SET token_hash = ?, token_tipo = ?, token_expira = NOW() + INTERVAL $horas HOUR WHERE id = ?")
        ->execute([hash('sha256', $plano), $tipo, $usuarioId]);
    return $plano;
}

function cliente_token_usuario(string $token, string $tipo): ?array
{
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }
    return q_one(
        'SELECT * FROM usuarios_cliente WHERE token_hash = ? AND token_tipo = ? AND token_expira > NOW() AND activo = 1',
        [hash('sha256', $token), $tipo]
    );
}

function cliente_token_valido(string $token, string $tipo): bool
{
    return cliente_token_usuario($token, $tipo) !== null;
}

/** Fija la contraseña con un token válido y lo deja inservible. Devuelve el usuario_id. */
function cliente_token_consumir(string $token, string $tipo, string $clave): int
{
    $u = cliente_token_usuario($token, $tipo) ?? throw new HttpError(410, 'Este link venció o ya se usó.');
    cliente_validar_clave($clave);
    // El UPDATE condicionado al hash hace atómico el "un solo uso": dos pedidos a la vez no ganan los dos.
    $st = db()->prepare('UPDATE usuarios_cliente SET password_hash = ?, token_hash = NULL, token_tipo = NULL, token_expira = NULL WHERE id = ? AND token_hash = ?');
    $st->execute([password_hash($clave, PASSWORD_DEFAULT), $u['id'], $u['token_hash']]);
    if ($st->rowCount() !== 1) {
        throw new HttpError(410, 'Este link venció o ya se usó.');
    }
    db()->prepare("DELETE FROM login_intentos WHERE ambito = 'cliente' AND clave = ?")->execute([$u['email']]);
    return (int)$u['id'];
}

/**
 * Pedido de "olvidé mi contraseña". Responde siempre igual: nunca revela si el email existe.
 * Solo manda el mail a cuentas activas que ya activaron su contraseña.
 */
function cliente_recuperar_solicitar(string $email, string $ip): void
{
    $email = cliente_email_normalizar($email);
    $pedidos = (int)q_val(
        "SELECT COUNT(*) FROM login_intentos WHERE ambito = 'recuperar' AND ip = ? AND creado_en > (NOW() - INTERVAL " . LOGIN_VENTANA_MIN . ' MINUTE)',
        [$ip]
    );
    if ($pedidos >= RECUPERAR_MAX_POR_IP) {
        return;
    }
    db()->prepare("INSERT INTO login_intentos (ip, ambito) VALUES (?, 'recuperar')")->execute([$ip]);
    $u = q_one('SELECT * FROM usuarios_cliente WHERE email = ? AND activo = 1 AND password_hash IS NOT NULL', [$email]);
    if ($u === null) {
        return;
    }
    $token = cliente_token_emitir((int)$u['id'], 'recuperacion');
    mail_enviar('recuperacion', $u['email'], ['link' => portal_url('/clientes/recuperar?token=' . $token)]);
}
