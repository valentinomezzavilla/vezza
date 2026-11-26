<?php
declare(strict_types=1);

function migraciones_dir(): string
{
    return dirname(__DIR__, 2) . '/db/migrations';
}

function sql_split(string $sql): array
{
    $sinComentarios = preg_replace('/^\s*--.*$/m', '', $sql);
    $partes = preg_split('/;\s*(?:\R|$)/', (string)$sinComentarios);
    return array_values(array_filter(array_map('trim', $partes), fn(string $s) => $s !== ''));
}

function migraciones_pendientes(PDO $pdo, ?string $dir = null): array
{
    $dir ??= migraciones_dir();
    $pdo->exec('CREATE TABLE IF NOT EXISTS migraciones (
        nombre VARCHAR(190) NOT NULL PRIMARY KEY,
        aplicada_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $hechas = $pdo->query('SELECT nombre FROM migraciones')->fetchAll(PDO::FETCH_COLUMN);
    $archivos = glob($dir . '/*.sql') ?: [];
    sort($archivos);
    $pendientes = [];
    foreach ($archivos as $archivo) {
        $nombre = basename($archivo, '.sql');
        if (!in_array($nombre, $hechas, true)) {
            $pendientes[$nombre] = $archivo;
        }
    }
    return $pendientes;
}

/**
 * Los nombres de las migraciones sin aplicar, solo leyendo (no crea la tabla `migraciones`). Nunca falla: si no se
 * puede consultar la base devuelve [], porque se usa para avisar y no tiene que romper la pantalla que avisa.
 */
function migraciones_sin_aplicar(?PDO $pdo = null, ?string $dir = null): array
{
    try {
        $hechas = ($pdo ?? db())->query('SELECT nombre FROM migraciones')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable) {
        return [];
    }
    $nombres = array_map(fn(string $a) => basename($a, '.sql'), glob(($dir ?? migraciones_dir()) . '/*.sql') ?: []);
    sort($nombres);
    return array_values(array_diff($nombres, $hechas));
}

/** El texto que se muestra cuando faltan migraciones: cuáles son y dónde aplicarlas. */
function aviso_migraciones(array $pendientes): string
{
    return 'Hay ' . (count($pendientes) === 1 ? 'una migración pendiente' : count($pendientes) . ' migraciones pendientes')
        . ' (' . implode(', ', $pendientes) . '). Aplicala' . (count($pendientes) === 1 ? '' : 's')
        . ' desde Migraciones; hasta entonces algunas secciones pueden dar error.';
}

function migrar(PDO $pdo, ?string $dir = null): array
{
    $aplicadas = [];
    foreach (migraciones_pendientes($pdo, $dir) as $nombre => $archivo) {
        foreach (sql_split((string)file_get_contents($archivo)) as $sentencia) {
            $pdo->exec($sentencia);
        }
        $pdo->prepare('INSERT IGNORE INTO migraciones (nombre) VALUES (?)')->execute([$nombre]);
        $aplicadas[] = $nombre;
    }
    return $aplicadas;
}
