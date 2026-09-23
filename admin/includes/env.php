<?php
declare(strict_types=1);

final class Env
{
    public static array $vars = [];
    public static bool $cargado = false;
}

function env_parse(string $contenido): array
{
    $vars = [];
    foreach (preg_split('/\R/', $contenido) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#') {
            continue;
        }
        $pos = strpos($linea, '=');
        if ($pos === false) {
            continue;
        }
        $clave = trim(substr($linea, 0, $pos));
        $valor = trim(substr($linea, $pos + 1));
        $largo = strlen($valor);
        if ($largo >= 2 && ($valor[0] === '"' || $valor[0] === "'") && $valor[$largo - 1] === $valor[0]) {
            $valor = substr($valor, 1, -1);
        }
        $vars[$clave] = $valor;
    }
    return $vars;
}

function env_load(string $ruta): void
{
    $contenido = @file_get_contents($ruta);
    if ($contenido === false) {
        throw new RuntimeException("No se pudo leer $ruta");
    }
    Env::$vars = env_parse($contenido);
    Env::$cargado = true;
}

function env_load_first(array $rutas): void
{
    foreach ($rutas as $ruta) {
        if (is_readable($ruta)) {
            env_load($ruta);
            return;
        }
    }
    throw new RuntimeException('No se encontró el archivo .env');
}

function env_loaded(): bool
{
    return Env::$cargado;
}

function env(string $clave, ?string $default = null): ?string
{
    $valor = Env::$vars[$clave] ?? null;
    return ($valor === null || $valor === '') ? $default : $valor;
}

function env_set(string $clave, ?string $valor): void
{
    Env::$vars[$clave] = $valor;
}
