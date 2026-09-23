<?php
declare(strict_types=1);

final class Db
{
    public static ?PDO $pdo = null;
}

function db(): PDO
{
    if (Db::$pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', env('DB_HOST', '127.0.0.1'), (string)env('DB_NAME'));
        $pdo = new PDO($dsn, (string)env('DB_USER'), env('DB_PASS') ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET time_zone = '-03:00'");
        Db::$pdo = $pdo;
    }
    return Db::$pdo;
}
