<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../admin/includes/env.php';

env_load(__DIR__ . '/../.env.testing');

if (!str_ends_with((string)env('DB_NAME'), '_test')) {
    fwrite(STDERR, "DB_NAME de .env.testing tiene que terminar en _test. Abortado para no borrar datos reales.\n");
    exit(1);
}

require_once __DIR__ . '/../admin/includes/bootstrap.php';
require_once __DIR__ . '/DbTestCase.php';

ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vezza-tests.log');

$pdo = db();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tabla) {
    $pdo->exec('DROP TABLE `' . str_replace('`', '', $tabla) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
migrar($pdo);
