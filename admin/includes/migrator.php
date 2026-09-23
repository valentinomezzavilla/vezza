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
