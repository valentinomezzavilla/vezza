<?php
declare(strict_types=1);

// TEMPORAL: diagnóstico del 500 en producción. Se borra apenas se encuentre la causa.
// No imprime valores del .env ni contraseñas: solo si existen, tamaños y el tipo de error.
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
ini_set('display_errors', '0');

// Solo responde a quien conoce la clave (?k=...). Acá va únicamente su hash SHA-256.
$clave = is_string($_GET['k'] ?? null) ? $_GET['k'] : '';
if (!hash_equals('553585ed46f9ddc2babed0b0ce5d9421fa3c9bc079572eac3a5018b55517ecfb', hash('sha256', $clave))) {
    http_response_code(404);
    exit;
}

function linea(string $k, mixed $v): void
{
    echo str_pad($k, 34) . (is_bool($v) ? ($v ? 'sí' : 'no') : (string)$v) . "\n";
}

echo "== Entorno ==\n";
linea('PHP', PHP_VERSION);
linea('open_basedir', ini_get('open_basedir') ?: '(vacío)');
linea('session.save_path', (string)ini_get('session.save_path'));
linea('extensión pdo_mysql', extension_loaded('pdo_mysql'));
linea('extensión fileinfo', extension_loaded('fileinfo'));

echo "\n== Rutas del .env ==\n";
$candidatos = [
    'un nivel arriba de public_html' => dirname(__DIR__, 2) . '/.env',
    'dentro de public_html' => dirname(__DIR__) . '/.env',
];
foreach ($candidatos as $nombre => $ruta) {
    echo "[$nombre]\n";
    linea('  ruta', $ruta);
    linea('  existe', file_exists($ruta));
    linea('  legible', is_readable($ruta));
    linea('  tamaño (bytes)', file_exists($ruta) ? (string)filesize($ruta) : '-');
}

echo "\n== Carpetas privadas ==\n";
$base = dirname(__DIR__, 2);
foreach (['vezza_sessions', 'vezza_uploads/comprobantes'] as $carpeta) {
    linea($carpeta . ' existe', is_dir("$base/$carpeta"));
    linea($carpeta . ' escribible', is_writable("$base/$carpeta"));
}

echo "\n== Arranque del panel ==\n";
try {
    require __DIR__ . '/includes/bootstrap.php';
    linea('bootstrap', 'OK');
    foreach (['ADMIN_USERNAME', 'ADMIN_PASSWORD_HASH', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'APP_ENV'] as $clave) {
        linea("  $clave definida", env($clave) !== null);
    }
    $hash = (string)env('ADMIN_PASSWORD_HASH');
    linea('  hash empieza con $2y$', str_starts_with($hash, '$2y$'));
    linea('  hash largo (esperado 60)', strlen($hash));
    linea('  APP_ENV', (string)env('APP_ENV'));
} catch (Throwable $e) {
    linea('bootstrap FALLÓ', get_class($e));
    linea('  mensaje', $e->getMessage());
    linea('  en', basename($e->getFile()) . ':' . $e->getLine());
    exit;
}

echo "\n== Sesión ==\n";
try {
    session_boot();
    linea('session_status activa', session_status() === PHP_SESSION_ACTIVE);
} catch (Throwable $e) {
    linea('session_boot FALLÓ', get_class($e));
    linea('  mensaje', $e->getMessage());
    linea('  en', basename($e->getFile()) . ':' . $e->getLine());
}

echo "\n== Base de datos ==\n";
try {
    $n = (int)db()->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
    linea('conexión', 'OK');
    linea('  tablas en la base', $n);
} catch (Throwable $e) {
    linea('conexión FALLÓ', get_class($e));
    linea('  mensaje', $e->getMessage());
}
