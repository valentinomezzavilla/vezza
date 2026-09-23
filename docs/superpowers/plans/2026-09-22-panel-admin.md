# Panel admin VEZZA — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sumar a vezzadev.com un panel privado (`/admin-login`, `/admin/*`, `/api/*`) para gestionar clientes, procesos, cobros, gastos, tareas, fixs, agenda y reportes, sin tocar la landing.

**Architecture:** PHP 8 plano + MySQL/MariaDB dentro del mismo `public_html`. La lógica vive en funciones dentro de `admin/includes/` (repos por entidad + helpers genéricos de validación/CRUD). Los `api/*.php` son despachadores de ~10 líneas que delegan en esos repos. Las páginas `admin/*.php` renderizan un layout y un JS por página consume la API. `.htaccess` gana reglas al final para URLs limpias y bloqueos.

**Tech Stack:** PHP ≥ 8.1 (PDO MySQL), MariaDB/MySQL, JS vanilla, SortableJS 1.15 (vendorizado), PHPUnit 11 (solo dev, vía Composer), XAMPP en local.

**Spec:** `docs/superpowers/specs/2026-09-22-panel-admin-design.md`

## Global Constraints

- No modificar `index.html`, `css/`, `js/`, `assets/`, `imgs/`, `robots.txt`, `sitemap.xml` ni las líneas existentes de `.htaccess`. A `.htaccess` solo se le **agregan** bloques al final.
- Trabajar en la rama `panel-admin`. **Nunca** hacer push a `main` antes de la Task 23: cada push a `main` despliega solo a producción.
- Código de producción compatible con PHP 8.1. No usar `readonly class`, tipos DNF, constantes en traits ni nada que requiera 8.2 o más. PHPUnit 11 necesita PHP 8.2 **solo en local**; XAMPP trae 8.2.
- Sin frameworks ni dependencias de runtime. La única librería de front es SortableJS, con copia local. Composer se usa solo como `require-dev`, y `vendor/` está en `.gitignore`.
- Todo el SQL con PDO preparado y placeholders posicionales `?`. Con `EMULATE_PREPARES=false`, un placeholder con nombre no se puede repetir. Los nombres de tabla y columna salen siempre de constantes del código, nunca del input.
- **JS:**
  - Nunca usar `innerHTML` con datos: siempre `Panel.el(...)` o `textContent`.
  - Sin `<script>` inline ni atributos `style=""` en el HTML, por la CSP.
  - Los estilos dinámicos se aplican solo con `elemento.style.x = ...`.
- **Montos:** `DECIMAL(12,2)`. Viajan en JSON como string (`"1500.50"`).
- **Moneda:** `CHAR(3)` en mayúsculas. Los totales se agrupan siempre por moneda, sin conversión.
- **Fechas y hora:**
  - Formatos: fechas `YYYY-MM-DD`; fecha y hora `YYYY-MM-DD HH:MM:SS`.
  - Zona horaria: PHP usa `America/Argentina/Buenos_Aires` y MySQL `SET time_zone = '-03:00'` (Argentina no tiene horario de verano).
  - Toda lógica que dependa de "hoy" usa `hoy()`. No usar `CURDATE()` ni `date('Y-m-d')`.
- **Marca:** fondo `#F5F3EE`, texto `#15131C`, acento `#3D2FE0`. Títulos en Sora, texto en Manrope. Textos en español rioplatense con voseo.
- **Assets del panel:** llevan `?v=` + la constante `PANEL_ASSET_V` de `admin/includes/layout.php`. Subila cada vez que cambies CSS o JS del panel después del primer deploy.
- Cada commit termina con la línea `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Tests:** se corren con `php vendor/bin/phpunit` contra la base `vezza_admin_test`. El bootstrap aborta si `DB_NAME` no termina en `_test`.

## Review Focus

1. **Drag & drop con el kanban filtrado por cliente.** La tarjeta tiene que quedar justo antes de la tarjeta visible sobre la que se soltó; las tarjetas ocultas de otros clientes no deben moverla. Por eso la API usa `antes_de` (id de la tarjeta siguiente o `null`) en lugar de un índice.
   - Test: `ProcesosTest::test_reordenar_con_antes_de_ignora_tarjetas_de_otros_clientes` (Task 9).
   - Test: `FixsTest::test_mover_con_antes_de_y_cambiar_columna` (Task 16).
2. **Hash bcrypt en `.env`.** El hash empieza con `$2y$12$…`. El parser no debe interpolar variables ni cortar el valor en un `$` o en un `=`.
   - Test: `EnvTest::test_quita_comillas_y_no_interpola_signos_pesos` (Task 1).
3. **Monto con coma decimal.** En un celular con teclado es-AR se escribe `1500,5`; tiene que guardarse como `1500.50`.
   - Test: `ValidateTest::test_decimal_acepta_coma_y_normaliza` (Task 3).
   - Test: `CobrosTest::test_normaliza_monto_y_moneda` (Task 11).
4. **Proceso de otro cliente.** Un cobro o fix con un proceso de otro cliente, o un cambio de cliente que deja asociado un proceso ajeno, tiene que responder 422 en `proceso_id`.
   - Test: `CobrosTest::test_cambiar_cliente_con_proceso_ajeno_falla` (Task 11).
   - Test: `FixsTest::test_proceso_de_otro_cliente_falla` (Task 16).
5. **Vaciar un campo opcional en una edición.** Si se borra el email en el formulario, tiene que quedar `NULL` en la base, no el valor viejo.
   - Test: `ValidateTest::test_string_vacio_en_opcional_devuelve_null` (Task 3).
   - Test: `ClientesTest::test_update_vaciar_campo_lo_deja_null` (Task 7).

## Mapa de archivos

| Archivo | Responsabilidad |
|---|---|
| `admin/includes/bootstrap.php` | Carga todos los includes y repos, el `.env` y la zona horaria |
| `admin/includes/env.php` | Parser del `.env` y `env()` / `env_set()` |
| `admin/includes/clock.php` | `hoy()`, `clock_set()`, `dias_entre()` |
| `admin/includes/db.php` | `db()`: conexión PDO única |
| `admin/includes/migrator.php` | `sql_split()`, `migraciones_pendientes()`, `migrar()` |
| `admin/includes/http.php` | `HttpError`, `json_out()`, `request_json()`, `route_id()`, `e()`, headers del panel |
| `admin/includes/validate.php` | `validate()`, `validar_valor()`, `filtros()` |
| `admin/includes/crud.php` | `q_all` / `q_one` / `q_val`, `crud_*`, `where_eq`, `sql_where`, `siguiente_orden`, `reordenar`, `tx` |
| `admin/includes/auth.php` | Sesión, login con bloqueo por intentos, `require_admin()`, `safe_next()` |
| `admin/includes/csrf.php` | `csrf_token()`, `csrf_check()` |
| `admin/includes/api.php` | `api_run()`, `api_resource()` |
| `admin/includes/comprobantes.php` | Guardar, servir y borrar adjuntos |
| `admin/includes/layout.php` | `layout_start()` / `layout_end()` y navegación |
| `admin/includes/repos/*.php` | Una por entidad: clientes, notas_cliente, procesos, subtareas, cobros, suscripciones, gastos, tareas, fixs, eventos, agenda, dashboard, reportes |
| `api/*.php` | Endpoints JSON (despachan a los repos) |
| `admin/*.php` | Páginas |
| `admin/assets/admin.css`, `admin.js` | Estilos y helpers comunes (`Panel`) |
| `admin/assets/mod-*.js` | Módulos por entidad: campos de formulario, alta, edición e item de lista |
| `admin/assets/<página>.js` | Lógica de cada página |
| `admin/assets/kanban.js`, `vendor/sortable.min.js` | Tablero drag & drop |
| `db/migrations/001_inicial.sql`, `db/migrate.php` | Esquema y CLI de migraciones |
| `scripts/hash-password.php`, `verificar-landing.sh`, `verificar-panel.sh` | Utilidades de CLI |
| `tests/*` | PHPUnit |

---

### Task 1: Entorno, tooling y `.env`

**Files:**
- Create: `.gitignore`, `.env.example`, `.env.testing.example`, `composer.json`, `phpunit.xml`
- Create: `admin/includes/bootstrap.php`, `admin/includes/env.php`, `admin/includes/clock.php`
- Create: `tests/bootstrap.php`, `tests/EnvTest.php`

**Interfaces:**
- Produces: `env_parse(string): array`, `env_load(string): void`, `env_load_first(array): void`, `env_loaded(): bool`, `env(string $k, ?string $default = null): ?string` (un valor vacío devuelve el default), `env_set(string, ?string): void`, `hoy(): string`, `clock_set(?string): void`, `dias_entre(string $desde, string $hasta): int` (con signo). `admin/includes/bootstrap.php` hace `require_once` de todo `admin/includes/*.php` y `admin/includes/repos/*.php`, así que las tasks siguientes solo crean archivos y no editan el bootstrap.

- [ ] **Step 1: Instalar el entorno local (una sola vez, manual)**

  1. Instalá XAMPP para Windows con PHP 8.2 (https://www.apachefriends.org) en `C:\xampp`, con los componentes Apache, MySQL (MariaDB) y PHP.
  2. Agregá `C:\xampp\php` al PATH de Windows y abrí una terminal nueva.
  3. Instalá Composer con el instalador de Windows (https://getcomposer.org/Composer-Setup.exe). Cuando pregunte por PHP, elegí `C:\xampp\php\php.exe`.
  4. Desde el XAMPP Control Panel arrancá **MySQL**.
  5. Creá las bases:

```bash
"/c/xampp/mysql/bin/mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS vezza_admin CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE IF NOT EXISTS vezza_admin_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php -v
composer --version
```

Resultado esperado: `PHP 8.2.x` y `Composer version 2.x`.

- [ ] **Step 2: Crear la rama de trabajo**

```bash
git checkout -b panel-admin
```

- [ ] **Step 3: Crear los archivos de tooling**

`.gitignore`:

```gitignore
.env
.env.*
!.env.example
!.env.testing.example
vendor/
.phpunit.cache/
```

`.env.example`:

```dotenv
# Copiá este archivo a .env (en producción: UN NIVEL ARRIBA de public_html).
APP_ENV=local
ADMIN_USERNAME=valen
# Generalo con: php scripts/hash-password.php
ADMIN_PASSWORD_HASH=
DB_HOST=127.0.0.1
DB_NAME=vezza_admin
DB_USER=root
DB_PASS=
# Vacío = ../vezza_uploads/comprobantes (fuera de public_html)
UPLOADS_DIR=
# Opcional: carpeta propia para las sesiones de PHP
SESSIONS_DIR=
```

`.env.testing.example`:

```dotenv
APP_ENV=testing
DB_HOST=127.0.0.1
DB_NAME=vezza_admin_test
DB_USER=root
DB_PASS=
UPLOADS_DIR=
```

`composer.json`:

```json
{
    "name": "vezza/panel",
    "description": "Panel admin de VEZZA (Composer solo para tests)",
    "type": "project",
    "require": {
        "php": ">=8.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "config": {
        "sort-packages": true
    }
}
```

`phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         cacheDirectory=".phpunit.cache"
         colors="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="panel">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

Después corré:

```bash
cp .env.testing.example .env.testing
composer install
```

- [ ] **Step 4: Escribir el test que falla**

`tests/bootstrap.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../admin/includes/env.php';

env_load(__DIR__ . '/../.env.testing');

require_once __DIR__ . '/../admin/includes/bootstrap.php';
```

`tests/EnvTest.php`:

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    public function test_parsea_claves_valores_y_comentarios(): void
    {
        $vars = env_parse("# comentario\nA=1\n\nB = hola mundo \nLINEA_INVALIDA\n");
        $this->assertSame(['A' => '1', 'B' => 'hola mundo'], $vars);
    }

    public function test_quita_comillas_y_no_interpola_signos_pesos(): void
    {
        $hash = '$2y$12$abcdefghijklmnopqrstuuJ0vYpG6o5b8qF9m0xZkq3yQy1u2W3e';
        $vars = env_parse('H1=' . $hash . "\n" . "H2='" . $hash . "'\n" . 'H3="' . $hash . '"' . "\n");
        $this->assertSame($hash, $vars['H1']);
        $this->assertSame($hash, $vars['H2']);
        $this->assertSame($hash, $vars['H3']);
    }

    public function test_valor_con_signo_igual_se_conserva(): void
    {
        $this->assertSame('a=b=c', env_parse('DSN=a=b=c')['DSN']);
    }

    public function test_env_devuelve_default_si_falta_o_esta_vacio(): void
    {
        env_set('X_VACIA', '');
        $this->assertSame('def', env('X_VACIA', 'def'));
        $this->assertNull(env('X_NO_EXISTE'));
        env_set('X_VACIA', null);
    }

    public function test_hoy_se_puede_fijar_para_tests(): void
    {
        clock_set('2026-01-31');
        $this->assertSame('2026-01-31', hoy());
        clock_set(null);
        $this->assertSame(date('Y-m-d'), hoy());
    }

    public function test_dias_entre_tiene_signo(): void
    {
        $this->assertSame(3, dias_entre('2026-02-26', '2026-03-01'));
        $this->assertSame(-1, dias_entre('2026-03-01', '2026-02-28'));
        $this->assertSame(0, dias_entre('2026-03-01', '2026-03-01'));
    }
}
```

- [ ] **Step 5: Correr el test y confirmar que falla**

Run: `php vendor/bin/phpunit tests/EnvTest.php`
Resultado esperado: error fatal `Failed opening required '.../admin/includes/env.php'`.

- [ ] **Step 6: Implementar**

`admin/includes/env.php`:

```php
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
```

`admin/includes/clock.php`:

```php
<?php
declare(strict_types=1);

final class Clock
{
    public static ?string $hoy = null;
}

function hoy(): string
{
    return Clock::$hoy ?? date('Y-m-d');
}

function clock_set(?string $fecha): void
{
    Clock::$hoy = $fecha;
}

function dias_entre(string $desde, string $hasta): int
{
    return (int)(new DateTimeImmutable($desde))->diff(new DateTimeImmutable($hasta))->format('%r%a');
}
```

`admin/includes/bootstrap.php`:

```php
<?php
declare(strict_types=1);

foreach (glob(__DIR__ . '/*.php') as $archivo) {
    if (basename($archivo) !== 'bootstrap.php') {
        require_once $archivo;
    }
}
foreach (glob(__DIR__ . '/repos/*.php') ?: [] as $archivo) {
    require_once $archivo;
}

date_default_timezone_set('America/Argentina/Buenos_Aires');

if (!env_loaded()) {
    // Producción: .env un nivel arriba de public_html. Local: raíz del repo.
    env_load_first([dirname(__DIR__, 3) . '/.env', dirname(__DIR__, 2) . '/.env']);
}
```

- [ ] **Step 7: Correr el test y confirmar que pasa**

Run: `php vendor/bin/phpunit tests/EnvTest.php`
Resultado esperado: `OK (6 tests, ...)`.

- [ ] **Step 8: Commit**

Este commit incluye también la spec y el plan.

```bash
git add .gitignore .env.example .env.testing.example composer.json composer.lock phpunit.xml admin/includes tests docs/superpowers
git commit -m "$(cat <<'EOF'
Preparar tooling del panel admin y carga del .env

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 2: Conexión, esquema inicial y migrador

**Files:**
- Create: `admin/includes/db.php`, `admin/includes/migrator.php`
- Create: `db/migrations/001_inicial.sql`, `db/migrate.php`
- Create: `tests/DbTestCase.php`, `tests/MigratorTest.php`
- Modify: `tests/bootstrap.php` (resetea la base de test y migra)

**Interfaces:**
- Consumes: `env()` (Task 1).
- Produces:
  - `db(): PDO` (singleton; `Db::$pdo` se puede reemplazar)
  - `sql_split(string): array`
  - `migraciones_pendientes(PDO, ?string $dir = null): array` (nombre ⇒ ruta)
  - `migrar(PDO, ?string $dir = null): array` (nombres aplicados)
  - `DbTestCase` con transacción por test, más `assertHttp(int $status, callable): HttpError` y `errores422(callable): array` (usan `HttpError`, que llega en la Task 3)
  - Tablas: `migraciones`, `clientes`, `notas_cliente`, `procesos`, `subtareas`, `cobros`, `suscripciones`, `gastos`, `tareas_personales`, `fixs`, `eventos`, `login_intentos`

- [ ] **Step 1: Escribir el test que falla**

`tests/DbTestCase.php`:

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

abstract class DbTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        db()->beginTransaction();
        $_SESSION = [];
        clock_set(null);
    }

    protected function tearDown(): void
    {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        clock_set(null);
        parent::tearDown();
    }

    protected function assertHttp(int $status, callable $fn): HttpError
    {
        try {
            $fn();
        } catch (HttpError $e) {
            $this->assertSame($status, $e->status, 'Mensaje: ' . $e->getMessage());
            return $e;
        }
        $this->fail("Se esperaba HttpError $status");
    }

    protected function errores422(callable $fn): array
    {
        return $this->assertHttp(422, $fn)->campos;
    }
}
```

`tests/MigratorTest.php`:

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    public function test_sql_split_separa_sentencias_e_ignora_comentarios(): void
    {
        $sql = "-- comentario\nCREATE TABLE a (id INT);\n\nINSERT INTO a VALUES (1);\n-- fin\n";
        $this->assertSame(['CREATE TABLE a (id INT)', 'INSERT INTO a VALUES (1)'], sql_split($sql));
    }

    public function test_esquema_inicial_completo_y_registrado(): void
    {
        $tablas = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $esperadas = ['migraciones', 'clientes', 'notas_cliente', 'procesos', 'subtareas', 'cobros',
            'suscripciones', 'gastos', 'tareas_personales', 'fixs', 'eventos', 'login_intentos'];
        foreach ($esperadas as $tabla) {
            $this->assertContains($tabla, $tablas);
        }
        $this->assertSame([], migraciones_pendientes(db()));
    }

    public function test_migrar_aplica_solo_las_pendientes(): void
    {
        $dir = sys_get_temp_dir() . '/vezza_mig_' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/900_prueba.sql", "CREATE TABLE IF NOT EXISTS prueba_mig (id INT);\n");
        try {
            $this->assertSame(['900_prueba'], migrar(db(), $dir));
            $this->assertSame([], migrar(db(), $dir));
        } finally {
            db()->exec('DROP TABLE IF EXISTS prueba_mig');
            db()->prepare('DELETE FROM migraciones WHERE nombre = ?')->execute(['900_prueba']);
            unlink("$dir/900_prueba.sql");
            rmdir($dir);
        }
    }
}
```

Reemplazá `tests/bootstrap.php` completo:

```php
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
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `php vendor/bin/phpunit tests/MigratorTest.php`
Resultado esperado: error `Call to undefined function db()`.

- [ ] **Step 3: Implementar la conexión y el migrador**

`admin/includes/db.php`:

```php
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
```

`admin/includes/migrator.php`:

```php
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
```

`db/migrate.php`:

```php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../admin/includes/bootstrap.php';

$aplicadas = migrar(db());
echo $aplicadas
    ? 'Aplicadas: ' . implode(', ', $aplicadas) . PHP_EOL
    : 'No hay migraciones pendientes.' . PHP_EOL;
```

- [ ] **Step 4: Escribir el esquema**

`db/migrations/001_inicial.sql`:

```sql
-- VEZZA · Panel admin · esquema inicial
-- En el primer deploy se importa a mano en phpMyAdmin; se registra solo en `migraciones`.

CREATE TABLE IF NOT EXISTS migraciones (
  nombre VARCHAR(190) NOT NULL PRIMARY KEY,
  aplicada_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clientes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(255) NOT NULL,
  email VARCHAR(255) NULL,
  telefono VARCHAR(50) NULL,
  rubro VARCHAR(255) NULL,
  estado ENUM('activo','pausado','finalizado') NOT NULL DEFAULT 'activo',
  fecha_inicio DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_clientes_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notas_cliente (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  contenido TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_notas_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE procesos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  titulo VARCHAR(255) NOT NULL,
  descripcion TEXT NULL,
  estado ENUM('por_hacer','en_curso','en_revision','entregado') NOT NULL DEFAULT 'por_hacer',
  prioridad ENUM('baja','media','alta') NOT NULL DEFAULT 'media',
  fecha_inicio DATE NULL,
  fecha_entrega_estimada DATE NULL,
  orden INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_procesos_estado (estado, orden),
  CONSTRAINT fk_procesos_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subtareas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  proceso_id INT UNSIGNED NOT NULL,
  titulo VARCHAR(255) NOT NULL,
  completada TINYINT(1) NOT NULL DEFAULT 0,
  orden INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_subtareas_orden (proceso_id, orden),
  CONSTRAINT fk_subtareas_proceso FOREIGN KEY (proceso_id) REFERENCES procesos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cobros (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  proceso_id INT UNSIGNED NULL,
  concepto VARCHAR(255) NULL,
  monto DECIMAL(12,2) NOT NULL,
  moneda CHAR(3) NOT NULL,
  fecha_vencimiento DATE NOT NULL,
  fecha_pago DATE NULL,
  estado ENUM('pendiente','pagado') NOT NULL DEFAULT 'pendiente',
  metodo_pago VARCHAR(100) NULL,
  comprobante_url VARCHAR(500) NULL,
  comprobante_archivo VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cobros_estado_venc (estado, fecha_vencimiento),
  KEY idx_cobros_pago (fecha_pago),
  CONSTRAINT fk_cobros_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE RESTRICT,
  CONSTRAINT fk_cobros_proceso FOREIGN KEY (proceso_id) REFERENCES procesos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE suscripciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  servicio VARCHAR(255) NOT NULL,
  categoria VARCHAR(100) NULL,
  monto DECIMAL(12,2) NOT NULL,
  moneda CHAR(3) NOT NULL,
  frecuencia ENUM('mensual','anual') NOT NULL,
  fecha_proximo_cobro DATE NOT NULL,
  activa TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_suscripciones_proximas (activa, fecha_proximo_cobro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gastos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  suscripcion_id INT UNSIGNED NULL,
  concepto VARCHAR(255) NOT NULL,
  categoria VARCHAR(100) NULL,
  monto DECIMAL(12,2) NOT NULL,
  moneda CHAR(3) NOT NULL,
  fecha DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_gastos_fecha (fecha),
  CONSTRAINT fk_gastos_suscripcion FOREIGN KEY (suscripcion_id) REFERENCES suscripciones (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tareas_personales (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  titulo VARCHAR(255) NOT NULL,
  descripcion TEXT NULL,
  estado ENUM('pendiente','en_curso','hecha') NOT NULL DEFAULT 'pendiente',
  prioridad ENUM('baja','media','alta') NOT NULL DEFAULT 'media',
  fecha_vencimiento DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tareas_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fixs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  proceso_id INT UNSIGNED NULL,
  titulo VARCHAR(255) NOT NULL,
  descripcion TEXT NULL,
  estado ENUM('reportado','en_progreso','resuelto') NOT NULL DEFAULT 'reportado',
  prioridad ENUM('baja','media','alta') NOT NULL DEFAULT 'media',
  fecha_reportado DATE NOT NULL,
  fecha_resuelto DATE NULL,
  orden INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_fixs_estado (estado, orden),
  CONSTRAINT fk_fixs_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE,
  CONSTRAINT fk_fixs_proceso FOREIGN KEY (proceso_id) REFERENCES procesos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE eventos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NULL,
  titulo VARCHAR(255) NOT NULL,
  descripcion TEXT NULL,
  fecha_hora DATETIME NOT NULL,
  duracion_min INT NULL,
  tipo ENUM('reunion','llamada','recordatorio','otro') NOT NULL DEFAULT 'otro',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_eventos_fecha (fecha_hora),
  CONSTRAINT fk_eventos_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_intentos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_login_ip (ip, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO migraciones (nombre) VALUES ('001_inicial');
```

- [ ] **Step 5: Correr el test y confirmar que pasa**

Run: `php vendor/bin/phpunit`
Resultado esperado: `OK (9 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
git add admin/includes/db.php admin/includes/migrator.php db tests
git commit -m "$(cat <<'EOF'
Agregar esquema inicial del panel y migrador

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 3: HTTP, validación y CRUD genérico

**Files:**
- Create: `admin/includes/http.php`, `admin/includes/validate.php`, `admin/includes/crud.php`
- Test: `tests/ValidateTest.php`, `tests/CrudTest.php`

**Interfaces:**
- Consumes: `db()` (Task 2).
- Produces:
  - `HttpError(int $status, string $mensaje, array $campos = [])` con las propiedades públicas `status` y `campos`.
  - `Http::$ultimoStatus` y `Http::$cuerpoPrueba` (para tests).
  - `send_panel_headers(): void`, `json_out(int, mixed = null): void`, `request_json(): array`, `route_id(): ?int`, `e(mixed): string`.
  - `validate(array $input, array $schema, bool $parcial = false): array`
    - Tipos de regla: `string`, `text`, `email`, `url`, `int`, `decimal`, `date`, `datetime`, `enum`, `bool`, `currency`, `fk`.
    - Opciones: `required`, `notnull`, `max`, `min`, `values`, `table`.
    - Un string vacío o `null` en un campo opcional ⇒ `null`.
    - Los campos desconocidos se ignoran.
    - Si hay errores: `HttpError(422, ..., [campo => motivo])`.
  - `validar_valor(mixed, array): mixed`
  - `filtros(array $get, array $schema): array` (ignora vacíos y valida)
  - `q_all(string, array = []): array`, `q_one(...): ?array`, `q_val(...): mixed`
  - `crud_exists(string $tabla, int $id): bool`, `crud_find(...)` (404 si no existe), `crud_insert(string, array): int`, `crud_update(string, int, array): void`, `crud_delete(string, int): void`
  - `where_eq(array $filtros, array $columnas): array` ⇒ `[$partes, $params]`
  - `sql_where(array $partes): string`
  - `siguiente_orden(string $tabla, string $grupoCol, int|string $grupoVal): int`
  - `reordenar(string $tabla, int $id, string $grupoCol, int|string $grupoVal, ?int $antesDe): void`
  - `tx(callable): mixed` (respeta una transacción que ya esté abierta)

- [ ] **Step 1: Escribir los tests que fallan**

`tests/ValidateTest.php`:

```php
<?php
declare(strict_types=1);

final class ValidateTest extends DbTestCase
{
    public function test_requerido_falta_en_alta(): void
    {
        $campos = $this->errores422(fn() => validate([], ['nombre' => ['type' => 'string', 'required' => true]]));
        $this->assertSame(['nombre' => 'Es obligatorio'], $campos);
    }

    public function test_parcial_ignora_requeridos_ausentes_pero_no_vacios(): void
    {
        $schema = ['nombre' => ['type' => 'string', 'required' => true]];
        $this->assertSame([], validate([], $schema, true));
        $this->assertArrayHasKey('nombre', $this->errores422(fn() => validate(['nombre' => '  '], $schema, true)));
    }

    public function test_string_vacio_en_opcional_devuelve_null(): void
    {
        $this->assertSame(['email' => null], validate(['email' => ''], ['email' => ['type' => 'email']], true));
    }

    public function test_notnull_permite_ausente_pero_no_vacio(): void
    {
        $schema = ['estado' => ['type' => 'enum', 'values' => ['a', 'b'], 'notnull' => true]];
        $this->assertSame([], validate([], $schema));
        $this->assertArrayHasKey('estado', $this->errores422(fn() => validate(['estado' => ''], $schema)));
    }

    public function test_recorta_espacios_e_ignora_campos_desconocidos(): void
    {
        $this->assertSame(['nombre' => 'Ana'], validate(['nombre' => '  Ana ', 'hackeo' => 'x'], ['nombre' => ['type' => 'string']]));
    }

    public function test_decimal_acepta_coma_y_normaliza(): void
    {
        $schema = ['monto' => ['type' => 'decimal']];
        $this->assertSame('1500.50', validate(['monto' => '1500,5'], $schema)['monto']);
        $this->assertSame('1500.00', validate(['monto' => 1500], $schema)['monto']);
        $this->assertSame('0.10', validate(['monto' => '0.1'], $schema)['monto']);
        $this->assertArrayHasKey('monto', $this->errores422(fn() => validate(['monto' => '-1'], $schema)));
        $this->assertArrayHasKey('monto', $this->errores422(fn() => validate(['monto' => 'abc'], $schema)));
        $this->assertArrayHasKey('monto', $this->errores422(fn() => validate(['monto' => '1.500,50'], $schema)));
    }

    public function test_fechas(): void
    {
        $schema = ['f' => ['type' => 'date']];
        $this->assertSame('2028-02-29', validate(['f' => '2028-02-29'], $schema)['f']);
        $this->assertArrayHasKey('f', $this->errores422(fn() => validate(['f' => '2026-02-30'], $schema)));
        $this->assertArrayHasKey('f', $this->errores422(fn() => validate(['f' => '22/09/2026'], $schema)));
    }

    public function test_datetime_acepta_formato_de_input_datetime_local(): void
    {
        $schema = ['fh' => ['type' => 'datetime']];
        $this->assertSame('2026-09-22 14:30:00', validate(['fh' => '2026-09-22T14:30'], $schema)['fh']);
        $this->assertSame('2026-09-22 09:05:10', validate(['fh' => '2026-09-22 09:05:10'], $schema)['fh']);
        $this->assertArrayHasKey('fh', $this->errores422(fn() => validate(['fh' => '2026-09-22 25:00'], $schema)));
    }

    public function test_enum_moneda_bool_email_url_int(): void
    {
        $this->assertArrayHasKey('e', $this->errores422(fn() => validate(['e' => 'z'], ['e' => ['type' => 'enum', 'values' => ['a']]])));
        $this->assertSame('USD', validate(['m' => 'usd'], ['m' => ['type' => 'currency']])['m']);
        $this->assertArrayHasKey('m', $this->errores422(fn() => validate(['m' => 'US'], ['m' => ['type' => 'currency']])));
        $this->assertSame(1, validate(['b' => true], ['b' => ['type' => 'bool']])['b']);
        $this->assertSame(0, validate(['b' => false], ['b' => ['type' => 'bool']])['b']);
        $this->assertSame(0, validate(['b' => '0'], ['b' => ['type' => 'bool']])['b']);
        $this->assertArrayHasKey('b', $this->errores422(fn() => validate(['b' => 'x'], ['b' => ['type' => 'bool']])));
        $this->assertArrayHasKey('c', $this->errores422(fn() => validate(['c' => 'no-es-mail'], ['c' => ['type' => 'email']])));
        $this->assertSame('https://x.com/a', validate(['u' => 'https://x.com/a'], ['u' => ['type' => 'url']])['u']);
        $this->assertArrayHasKey('u', $this->errores422(fn() => validate(['u' => 'javascript:alert(1)'], ['u' => ['type' => 'url']])));
        $this->assertSame(7, validate(['n' => '7'], ['n' => ['type' => 'int']])['n']);
        $this->assertArrayHasKey('n', $this->errores422(fn() => validate(['n' => '0'], ['n' => ['type' => 'int', 'min' => 1]])));
        $this->assertArrayHasKey('n', $this->errores422(fn() => validate(['n' => ['1']], ['n' => ['type' => 'int']])));
    }

    public function test_string_respeta_maximo(): void
    {
        $this->assertArrayHasKey('t', $this->errores422(fn() => validate(['t' => str_repeat('a', 51)], ['t' => ['type' => 'string', 'max' => 50]])));
        $this->assertSame(str_repeat('ñ', 50), validate(['t' => str_repeat('ñ', 50)], ['t' => ['type' => 'string', 'max' => 50]])['t']);
    }

    public function test_filtros_ignora_vacios_y_valida(): void
    {
        $schema = ['estado' => ['type' => 'enum', 'values' => ['a']], 'q' => ['type' => 'string']];
        $this->assertSame(['estado' => 'a'], filtros(['estado' => 'a', 'q' => '', 'otro' => 'x'], $schema));
        $this->assertArrayHasKey('estado', $this->errores422(fn() => filtros(['estado' => 'z'], $schema)));
    }
}
```

`tests/CrudTest.php`:

```php
<?php
declare(strict_types=1);

final class CrudTest extends DbTestCase
{
    private function cliente(string $nombre = 'Acme'): int
    {
        return crud_insert('clientes', ['nombre' => $nombre]);
    }

    public function test_insert_find_update_delete(): void
    {
        $id = $this->cliente();
        $this->assertSame('Acme', crud_find('clientes', $id)['nombre']);
        crud_update('clientes', $id, ['rubro' => 'Gastronomía']);
        $fila = crud_find('clientes', $id);
        $this->assertSame('Gastronomía', $fila['rubro']);
        $this->assertSame('Acme', $fila['nombre']);
        crud_delete('clientes', $id);
        $this->assertFalse(crud_exists('clientes', $id));
    }

    public function test_find_update_delete_inexistente_da_404(): void
    {
        $this->assertHttp(404, fn() => crud_find('clientes', 999999));
        $this->assertHttp(404, fn() => crud_update('clientes', 999999, ['nombre' => 'x']));
        $this->assertHttp(404, fn() => crud_delete('clientes', 999999));
    }

    public function test_fk_valida_existencia(): void
    {
        $id = $this->cliente();
        $regla = ['type' => 'fk', 'table' => 'clientes'];
        $this->assertSame($id, validar_valor((string)$id, $regla));
        $this->expectException(InvalidArgumentException::class);
        validar_valor(999999, $regla);
    }

    public function test_helpers_de_consulta(): void
    {
        $id = $this->cliente('Beta');
        $this->assertSame('Beta', q_val('SELECT nombre FROM clientes WHERE id = ?', [$id]));
        $this->assertNull(q_one('SELECT * FROM clientes WHERE id = ?', [999999]));
        $this->assertCount(1, q_all('SELECT * FROM clientes WHERE id = ?', [$id]));
        [$partes, $params] = where_eq(['a' => 1, 'b' => null], ['a' => 'x.a', 'b' => 'x.b', 'c' => 'x.c']);
        $this->assertSame(['x.a = ?'], $partes);
        $this->assertSame([1], $params);
        $this->assertSame(' WHERE x.a = ?', sql_where($partes));
        $this->assertSame('', sql_where([]));
    }

    public function test_siguiente_orden_y_reordenar(): void
    {
        $c = $this->cliente();
        $ids = [];
        foreach (['A', 'B', 'C'] as $t) {
            $ids[$t] = crud_insert('procesos', [
                'cliente_id' => $c, 'titulo' => $t,
                'orden' => siguiente_orden('procesos', 'estado', 'por_hacer'),
            ]);
        }
        $orden = fn() => q_all("SELECT titulo FROM procesos WHERE estado = 'por_hacer' ORDER BY orden, id");
        $this->assertSame(['A', 'B', 'C'], array_column($orden(), 'titulo'));

        reordenar('procesos', $ids['C'], 'estado', 'por_hacer', $ids['A']);
        $this->assertSame(['C', 'A', 'B'], array_column($orden(), 'titulo'));

        reordenar('procesos', $ids['C'], 'estado', 'por_hacer', null);
        $this->assertSame(['A', 'B', 'C'], array_column($orden(), 'titulo'));

        reordenar('procesos', $ids['A'], 'estado', 'por_hacer', 999999);
        $this->assertSame(['B', 'C', 'A'], array_column($orden(), 'titulo'));
    }

    public function test_tx_dentro_de_transaccion_abierta(): void
    {
        $this->assertSame(5, tx(fn() => 5));
        $this->assertTrue(db()->inTransaction());
    }
}
```

- [ ] **Step 2: Correr los tests y confirmar que fallan**

Run: `php vendor/bin/phpunit tests/ValidateTest.php tests/CrudTest.php`
Resultado esperado: errores del tipo `Call to undefined function validate()` o `crud_insert()`.

- [ ] **Step 3: Implementar**

`admin/includes/http.php`:

```php
<?php
declare(strict_types=1);

final class HttpError extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $mensaje,
        public readonly array $campos = [],
    ) {
        parent::__construct($mensaje, $status);
    }
}

final class Http
{
    public static int $ultimoStatus = 200;
    public static ?string $cuerpoPrueba = null;
}

function send_panel_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    header('Expires: 0');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}

function json_out(int $status, mixed $cuerpo = null): void
{
    Http::$ultimoStatus = $status;
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    if ($status !== 204) {
        echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

function request_json(): array
{
    $crudo = Http::$cuerpoPrueba ?? (string)file_get_contents('php://input');
    if (trim($crudo) === '') {
        return [];
    }
    $datos = json_decode($crudo, true);
    if (!is_array($datos)) {
        throw new HttpError(400, 'El cuerpo no es JSON válido');
    }
    return $datos;
}

function route_id(): ?int
{
    $id = $_GET['id'] ?? null;
    if ($id === null || $id === '') {
        return null;
    }
    if (!is_string($id) || !ctype_digit($id) || (int)$id < 1) {
        throw new HttpError(404, 'No encontrado');
    }
    return (int)$id;
}

function e(mixed $texto): string
{
    return htmlspecialchars((string)$texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
```

`admin/includes/validate.php`:

```php
<?php
declare(strict_types=1);

function validate(array $input, array $schema, bool $parcial = false): array
{
    $datos = [];
    $errores = [];
    foreach ($schema as $campo => $regla) {
        if (!array_key_exists($campo, $input)) {
            if (!$parcial && !empty($regla['required'])) {
                $errores[$campo] = 'Es obligatorio';
            }
            continue;
        }
        $valor = is_string($input[$campo]) ? trim($input[$campo]) : $input[$campo];
        if ($valor === null || $valor === '') {
            if (!empty($regla['required']) || !empty($regla['notnull'])) {
                $errores[$campo] = 'Es obligatorio';
            } else {
                $datos[$campo] = null;
            }
            continue;
        }
        try {
            $datos[$campo] = validar_valor($valor, $regla);
        } catch (InvalidArgumentException $e) {
            $errores[$campo] = $e->getMessage();
        }
    }
    if ($errores) {
        throw new HttpError(422, 'Revisá los datos marcados', $errores);
    }
    return $datos;
}

function validar_valor(mixed $v, array $r): mixed
{
    switch ($r['type']) {
        case 'string':
        case 'text':
            if (!is_string($v) && !is_int($v) && !is_float($v)) {
                throw new InvalidArgumentException('Tiene que ser texto');
            }
            $v = (string)$v;
            $max = $r['max'] ?? ($r['type'] === 'string' ? 255 : 16000);
            if (mb_strlen($v) > $max) {
                throw new InvalidArgumentException("Máximo $max caracteres");
            }
            return $v;

        case 'email':
            if (!is_string($v) || mb_strlen($v) > 255 || filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Email inválido');
            }
            return $v;

        case 'url':
            $s = validar_valor($v, ['type' => 'string', 'max' => $r['max'] ?? 500]);
            $esquema = strtolower((string)parse_url($s, PHP_URL_SCHEME));
            if (filter_var($s, FILTER_VALIDATE_URL) === false || !in_array($esquema, ['http', 'https'], true)) {
                throw new InvalidArgumentException('Link inválido (tiene que empezar con https://)');
            }
            return $s;

        case 'int':
            if (!is_int($v) && !(is_string($v) && preg_match('/^-?\d{1,10}$/', $v))) {
                throw new InvalidArgumentException('Tiene que ser un número entero');
            }
            $v = (int)$v;
            if (isset($r['min']) && $v < $r['min']) {
                throw new InvalidArgumentException("Mínimo {$r['min']}");
            }
            if (isset($r['max']) && $v > $r['max']) {
                throw new InvalidArgumentException("Máximo {$r['max']}");
            }
            return $v;

        case 'decimal':
            $s = is_string($v) ? str_replace(',', '.', $v) : $v;
            if (!is_int($s) && !is_float($s) && !(is_string($s) && preg_match('/^\d+(\.\d+)?$/', $s))) {
                throw new InvalidArgumentException(is_string($s) && str_starts_with($s, '-') ? 'No puede ser negativo' : 'Tiene que ser un número (ej. 1500,50)');
            }
            $n = round((float)$s, 2);
            if ($n < 0) {
                throw new InvalidArgumentException('No puede ser negativo');
            }
            if ($n > 9999999999.99) {
                throw new InvalidArgumentException('Monto demasiado grande');
            }
            return number_format($n, 2, '.', '');

        case 'date':
            if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                throw new InvalidArgumentException('Fecha inválida');
            }
            return $v;

        case 'datetime':
            if (!is_string($v) || !preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/', $v, $m)) {
                throw new InvalidArgumentException('Fecha y hora inválidas');
            }
            validar_valor($m[1], ['type' => 'date']);
            $seg = $m[4] ?? '00';
            if ((int)$m[2] > 23 || (int)$m[3] > 59 || (int)$seg > 59) {
                throw new InvalidArgumentException('Hora inválida');
            }
            return "{$m[1]} {$m[2]}:{$m[3]}:$seg";

        case 'enum':
            if (!is_string($v) || !in_array($v, $r['values'], true)) {
                throw new InvalidArgumentException('Valor no permitido');
            }
            return $v;

        case 'bool':
            if (is_bool($v)) {
                return $v ? 1 : 0;
            }
            if ($v === 0 || $v === 1 || $v === '0' || $v === '1') {
                return (int)$v;
            }
            throw new InvalidArgumentException('Tiene que ser sí o no');

        case 'currency':
            if (!is_string($v) || !preg_match('/^[A-Za-z]{3}$/', $v)) {
                throw new InvalidArgumentException('Moneda inválida (3 letras, ej. ARS)');
            }
            return strtoupper($v);

        case 'fk':
            $id = validar_valor($v, ['type' => 'int', 'min' => 1]);
            if (!crud_exists($r['table'], $id)) {
                throw new InvalidArgumentException('No existe');
            }
            return $id;
    }
    throw new LogicException('Tipo de regla desconocido: ' . $r['type']);
}

function filtros(array $get, array $schema): array
{
    $presentes = array_filter(
        array_intersect_key($get, $schema),
        fn($v) => $v !== '' && $v !== null
    );
    return validate($presentes, $schema);
}
```

`admin/includes/crud.php`:

```php
<?php
declare(strict_types=1);

function q_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function q_one(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $fila = $st->fetch();
    return $fila === false ? null : $fila;
}

function q_val(string $sql, array $params = []): mixed
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $valor = $st->fetchColumn();
    return $valor === false ? null : $valor;
}

function crud_exists(string $tabla, int $id): bool
{
    return q_val("SELECT 1 FROM `$tabla` WHERE id = ?", [$id]) !== null;
}

function crud_find(string $tabla, int $id): array
{
    $fila = q_one("SELECT * FROM `$tabla` WHERE id = ?", [$id]);
    if ($fila === null) {
        throw new HttpError(404, 'No encontrado');
    }
    return $fila;
}

function crud_insert(string $tabla, array $datos): int
{
    $columnas = array_keys($datos);
    $sql = sprintf(
        'INSERT INTO `%s` (%s) VALUES (%s)',
        $tabla,
        implode(', ', array_map(fn(string $c) => "`$c`", $columnas)),
        implode(', ', array_fill(0, count($columnas), '?'))
    );
    db()->prepare($sql)->execute(array_values($datos));
    return (int)db()->lastInsertId();
}

function crud_update(string $tabla, int $id, array $datos): void
{
    crud_find($tabla, $id);
    if (!$datos) {
        return;
    }
    $sets = implode(', ', array_map(fn(string $c) => "`$c` = ?", array_keys($datos)));
    db()->prepare("UPDATE `$tabla` SET $sets WHERE id = ?")->execute([...array_values($datos), $id]);
}

function crud_delete(string $tabla, int $id): void
{
    crud_find($tabla, $id);
    db()->prepare("DELETE FROM `$tabla` WHERE id = ?")->execute([$id]);
}

function where_eq(array $filtros, array $columnas): array
{
    $partes = [];
    $params = [];
    foreach ($columnas as $clave => $columna) {
        if (!array_key_exists($clave, $filtros) || $filtros[$clave] === null) {
            continue;
        }
        $partes[] = "$columna = ?";
        $params[] = $filtros[$clave];
    }
    return [$partes, $params];
}

function sql_where(array $partes): string
{
    return $partes ? ' WHERE ' . implode(' AND ', $partes) : '';
}

function siguiente_orden(string $tabla, string $grupoCol, int|string $grupoVal): int
{
    return (int)q_val("SELECT COALESCE(MAX(orden), -1) + 1 FROM `$tabla` WHERE `$grupoCol` = ?", [$grupoVal]);
}

/** Ubica $id justo antes de $antesDe (o al final si es null o no está en el grupo) y renumera el grupo. */
function reordenar(string $tabla, int $id, string $grupoCol, int|string $grupoVal, ?int $antesDe): void
{
    $filas = q_all("SELECT id FROM `$tabla` WHERE `$grupoCol` = ? AND id <> ? ORDER BY orden, id", [$grupoVal, $id]);
    $ids = array_map('intval', array_column($filas, 'id'));
    $pos = $antesDe === null ? false : array_search($antesDe, $ids, true);
    array_splice($ids, $pos === false ? count($ids) : $pos, 0, [$id]);
    $st = db()->prepare("UPDATE `$tabla` SET orden = ? WHERE id = ?");
    foreach ($ids as $i => $rid) {
        $st->execute([$i, $rid]);
    }
}

function tx(callable $fn): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->beginTransaction();
    try {
        $resultado = $fn();
        $pdo->commit();
        return $resultado;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
```

- [ ] **Step 4: Correr los tests y confirmar que pasan**

Run: `php vendor/bin/phpunit`
Resultado esperado: `OK`, con todos los tests en verde.

- [ ] **Step 5: Commit**

```bash
git add admin/includes/http.php admin/includes/validate.php admin/includes/crud.php tests/ValidateTest.php tests/CrudTest.php
git commit -m "$(cat <<'EOF'
Agregar validación por esquema y helpers de CRUD del panel

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 4: Autenticación, CSRF y despachador de API

**Files:**
- Create: `admin/includes/auth.php`, `admin/includes/csrf.php`, `admin/includes/api.php`
- Test: `tests/AuthTest.php`, `tests/CsrfTest.php`, `tests/ApiTest.php`

**Interfaces:**
- Consumes: `env()`, `db()`, `q_val()`, `HttpError`, `json_out()`, `request_json()`, `route_id()`, `send_panel_headers()`.
- Produces:
  - `session_boot(): void`: en CLI solo inicializa `$_SESSION`; en web configura la cookie `vezza_admin` y arranca la sesión.
  - `auth_attempt(string $usuario, string $clave, string $ip): string`: devuelve `'ok'`, `'invalido'` o `'bloqueado'`.
  - `auth_logged(): bool`, `auth_logout(): void`
  - `require_admin(): void`: si no hay sesión, redirige con 302 y corta la ejecución.
  - `require_admin_api(): void`: si no hay sesión, lanza `HttpError 401`.
  - `safe_next(?string): string`, `client_ip(): string`
  - `csrf_token(): string`, `csrf_check(?string): void`: si el token no es válido, lanza `HttpError 403`.
  - `api_run(callable): void`: manda los headers del panel, arranca la sesión, exige login, exige CSRF en todo lo que no sea GET y convierte las excepciones en JSON.
  - `api_resource(array $h)`: las claves `list($get)`, `get($id)`, `create($input)`, `update($id, $input)` y `delete($id)` son opcionales. Si falta la que corresponde al método ⇒ 405.

- [ ] **Step 1: Escribir los tests que fallan**

`tests/AuthTest.php`:

```php
<?php
declare(strict_types=1);

final class AuthTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        env_set('ADMIN_USERNAME', 'valen');
        env_set('ADMIN_PASSWORD_HASH', password_hash('clave-secreta', PASSWORD_BCRYPT, ['cost' => 4]));
    }

    public function test_login_correcto_marca_la_sesion(): void
    {
        $this->assertSame('ok', auth_attempt('valen', 'clave-secreta', '1.1.1.1'));
        $this->assertTrue(auth_logged());
    }

    public function test_usuario_o_clave_incorrectos(): void
    {
        $this->assertSame('invalido', auth_attempt('valen', 'otra', '1.1.1.1'));
        $this->assertSame('invalido', auth_attempt('otro', 'clave-secreta', '1.1.1.1'));
        $this->assertFalse(auth_logged());
    }

    public function test_sexto_intento_queda_bloqueado_aunque_la_clave_sea_correcta(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('invalido', auth_attempt('valen', 'mal', '2.2.2.2'));
        }
        $this->assertSame('bloqueado', auth_attempt('valen', 'clave-secreta', '2.2.2.2'));
        $this->assertFalse(auth_logged());
        $this->assertSame('ok', auth_attempt('valen', 'clave-secreta', '3.3.3.3'));
    }

    public function test_intentos_de_hace_mas_de_15_minutos_no_cuentan(): void
    {
        for ($i = 0; $i < 5; $i++) {
            db()->exec("INSERT INTO login_intentos (ip, creado_en) VALUES ('4.4.4.4', NOW() - INTERVAL 16 MINUTE)");
        }
        $this->assertSame('ok', auth_attempt('valen', 'clave-secreta', '4.4.4.4'));
    }

    public function test_login_exitoso_borra_los_intentos_de_esa_ip(): void
    {
        auth_attempt('valen', 'mal', '5.5.5.5');
        auth_attempt('valen', 'clave-secreta', '5.5.5.5');
        $this->assertSame(0, (int)q_val("SELECT COUNT(*) FROM login_intentos WHERE ip = '5.5.5.5'"));
    }

    public function test_sin_hash_o_usuario_configurado_nunca_entra(): void
    {
        env_set('ADMIN_PASSWORD_HASH', '');
        $this->assertSame('invalido', auth_attempt('valen', '', '6.6.6.6'));
        env_set('ADMIN_PASSWORD_HASH', password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]));
        env_set('ADMIN_USERNAME', '');
        $this->assertSame('invalido', auth_attempt('', 'x', '6.6.6.6'));
    }

    public function test_safe_next_solo_acepta_rutas_del_panel(): void
    {
        $this->assertSame('/admin/clientes/5', safe_next('/admin/clientes/5'));
        $this->assertSame('/admin/cobros?cliente_id=3', safe_next('/admin/cobros?cliente_id=3'));
        $this->assertSame('/admin', safe_next('/admin'));
        foreach ([null, '', '//evil.com', 'https://evil.com', '/admin-login', '/adminx', '/admin/../x', '/admin//evil.com', '/', "/admin\r\nX: y", "/admin\n"] as $malo) {
            $this->assertSame('/admin', safe_next($malo), 'Aceptó: ' . var_export($malo, true));
        }
    }

    public function test_logout_limpia_la_sesion(): void
    {
        auth_attempt('valen', 'clave-secreta', '7.7.7.7');
        auth_logout();
        $this->assertFalse(auth_logged());
    }

    public function test_require_admin_api_sin_sesion_da_401(): void
    {
        $this->assertHttp(401, fn() => require_admin_api());
        $_SESSION['admin'] = true;
        require_admin_api();
        $this->addToAssertionCount(1);
    }
}
```

`tests/CsrfTest.php`:

```php
<?php
declare(strict_types=1);

final class CsrfTest extends DbTestCase
{
    public function test_token_estable_dentro_de_la_sesion(): void
    {
        $t = csrf_token();
        $this->assertSame(64, strlen($t));
        $this->assertSame($t, csrf_token());
        csrf_check($t);
        $this->addToAssertionCount(1);
    }

    public function test_token_invalido_o_ausente_da_403(): void
    {
        csrf_token();
        $this->assertHttp(403, fn() => csrf_check('otro'));
        $this->assertHttp(403, fn() => csrf_check(null));
    }

    public function test_sin_token_en_sesion_da_403(): void
    {
        $this->assertHttp(403, fn() => csrf_check(''));
    }
}
```

`tests/ApiTest.php`:

```php
<?php
declare(strict_types=1);

final class ApiTest extends DbTestCase
{
    private array $handlers;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION['admin'] = true;
        $this->handlers = [
            'list' => fn(array $get) => [['id' => 1]],
            'get' => fn(int $id) => ['id' => $id],
            'create' => fn(array $in) => ['id' => 9] + $in,
            'update' => fn(int $id, array $in) => ['id' => $id] + $in,
            'delete' => fn(int $id) => null,
        ];
    }

    private function llamar(string $metodo, ?string $id = null, ?string $cuerpo = null, bool $conCsrf = true, ?array $handlers = null): array
    {
        $_SERVER['REQUEST_METHOD'] = $metodo;
        $_GET = $id === null ? [] : ['id' => $id];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        if ($conCsrf) {
            $_SERVER['HTTP_X_CSRF_TOKEN'] = csrf_token();
        }
        Http::$cuerpoPrueba = $cuerpo;
        ob_start();
        api_resource($handlers ?? $this->handlers);
        $salida = (string)ob_get_clean();
        Http::$cuerpoPrueba = null;
        return [Http::$ultimoStatus, $salida === '' ? null : json_decode($salida, true)];
    }

    public function test_sin_sesion_da_401(): void
    {
        $_SESSION = [];
        [$status, $cuerpo] = $this->llamar('GET');
        $this->assertSame(401, $status);
        $this->assertSame('Sesión expirada', $cuerpo['error']);
    }

    public function test_post_sin_csrf_da_403(): void
    {
        [$status] = $this->llamar('POST', null, '{}', false);
        $this->assertSame(403, $status);
    }

    public function test_get_lista_y_detalle(): void
    {
        $this->assertSame([200, ['data' => [['id' => 1]]]], $this->llamar('GET'));
        $this->assertSame([200, ['data' => ['id' => 4]]], $this->llamar('GET', '4'));
    }

    public function test_id_invalido_da_404_y_metodo_no_soportado_405(): void
    {
        $this->assertSame(404, $this->llamar('GET', 'abc')[0]);
        $this->assertSame(405, $this->llamar('PUT')[0]);
        $this->assertSame(405, $this->llamar('PATCH', '3')[0]);
    }

    public function test_crear_actualizar_borrar(): void
    {
        $this->assertSame([201, ['data' => ['id' => 9, 'a' => 1]]], $this->llamar('POST', null, '{"a":1}'));
        $this->assertSame([200, ['data' => ['id' => 3, 'b' => 2]]], $this->llamar('PUT', '3', '{"b":2}'));
        $this->assertSame([204, null], $this->llamar('DELETE', '3'));
    }

    public function test_json_invalido_da_400(): void
    {
        $this->assertSame(400, $this->llamar('POST', null, '{roto')[0]);
    }

    public function test_errores_de_validacion_y_genericos(): void
    {
        $h = $this->handlers;
        $h['create'] = fn() => throw new HttpError(422, 'Revisá', ['nombre' => 'Es obligatorio']);
        $this->assertSame([422, ['error' => 'Revisá', 'campos' => ['nombre' => 'Es obligatorio']]], $this->llamar('POST', null, '{}', true, $h));
        $h['list'] = fn() => throw new RuntimeException('detalle interno');
        $this->assertSame([500, ['error' => 'Error interno']], $this->llamar('GET', null, null, true, $h));
    }
}
```

- [ ] **Step 2: Correr los tests y confirmar que fallan**

Run: `php vendor/bin/phpunit tests/AuthTest.php tests/CsrfTest.php tests/ApiTest.php`
Resultado esperado: error `Call to undefined function auth_attempt()`.

- [ ] **Step 3: Implementar**

`admin/includes/auth.php`:

```php
<?php
declare(strict_types=1);

const LOGIN_MAX_INTENTOS = 5;
const LOGIN_VENTANA_MIN = 15;
const SESION_DURACION = 604800; // 7 días

function session_boot(): void
{
    if (PHP_SAPI === 'cli') {
        $_SESSION ??= [];
        return;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $dir = env('SESSIONS_DIR');
    if ($dir !== null) {
        session_save_path($dir);
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
```

`admin/includes/csrf.php`:

```php
<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(?string $token): void
{
    $esperado = $_SESSION['csrf'] ?? '';
    if (!is_string($esperado) || $esperado === '' || !is_string($token) || !hash_equals($esperado, $token)) {
        throw new HttpError(403, 'Token de seguridad inválido. Recargá la página.');
    }
}
```

`admin/includes/api.php`:

```php
<?php
declare(strict_types=1);

function api_run(callable $fn): void
{
    send_panel_headers();
    try {
        session_boot();
        require_admin_api();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
            csrf_check(is_string($token) ? $token : null);
        }
        $fn();
    } catch (HttpError $e) {
        $cuerpo = ['error' => $e->getMessage()];
        if ($e->campos) {
            $cuerpo['campos'] = $e->campos;
        }
        json_out($e->status, $cuerpo);
    } catch (Throwable $e) {
        error_log('[panel] ' . $e);
        json_out(500, ['error' => 'Error interno']);
    }
}

function api_resource(array $h): void
{
    api_run(function () use ($h): void {
        $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $id = route_id();
        $mapa = $id === null
            ? ['GET' => 'list', 'POST' => 'create']
            : ['GET' => 'get', 'PUT' => 'update', 'DELETE' => 'delete'];
        $accion = $mapa[$metodo] ?? null;
        if ($accion === null || !isset($h[$accion])) {
            throw new HttpError(405, 'Método no permitido');
        }
        switch ($accion) {
            case 'list':
                json_out(200, ['data' => $h['list']($_GET)]);
                break;
            case 'get':
                json_out(200, ['data' => $h['get']($id)]);
                break;
            case 'create':
                json_out(201, ['data' => $h['create'](request_json())]);
                break;
            case 'update':
                json_out(200, ['data' => $h['update']($id, request_json())]);
                break;
            case 'delete':
                $h['delete']($id);
                json_out(204);
                break;
        }
    });
}
```

- [ ] **Step 4: Correr los tests y confirmar que pasan**

Run: `php vendor/bin/phpunit`
Resultado esperado: `OK`.

- [ ] **Step 5: Commit**

```bash
git add admin/includes/auth.php admin/includes/csrf.php admin/includes/api.php tests/AuthTest.php tests/CsrfTest.php tests/ApiTest.php
git commit -m "$(cat <<'EOF'
Agregar login con bloqueo por intentos, CSRF y despachador de API

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 5: Apache local, `.htaccess`, scripts de verificación y hash

**Files:**
- Modify: `.htaccess` (se agrega un bloque **al final**)
- Create: `scripts/hash-password.php`, `scripts/verificar-landing.sh`, `scripts/verificar-panel.sh`
- Create: `.env` local (no se commitea)

**Interfaces:**
- Produces: URLs limpias `/admin-login`, `/admin-logout`, `/admin`, `/admin/{seccion}`, `/admin/clientes/{id}`, `/admin/procesos/{id}`, `/api/{recurso}`, `/api/{recurso}/{id}`, `/api/suscripciones/{id}/pagar` y `/api/reportes/balance`. Además bloquea con 404 `/.env*`, `composer.*`, `phpunit.xml`, `/admin/includes/`, `/db/`, `/scripts/`, `/tests/`, `/vendor/` y `/docs/`.

- [ ] **Step 1: Configurar Apache de XAMPP (una sola vez, manual)**

  1. Agregá este bloque al final de `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

```apache
Listen 8080
<VirtualHost *:8080>
    DocumentRoot "C:/Users/valen/OneDrive/Escritorio/VEZZA"
    <Directory "C:/Users/valen/OneDrive/Escritorio/VEZZA">
        AllowOverride All
        Require local
    </Directory>
    # El .htaccess fuerza https; en local simulamos que ya venimos por https
    # para no tocar esa regla. "early" hace que se aplique antes de mod_rewrite.
    RequestHeader set X-Forwarded-Proto "https" early
</VirtualHost>
```

  2. En `C:\xampp\apache\conf\httpd.conf` verificá que estén descomentadas estas líneas (XAMPP las trae activas):
     - `LoadModule rewrite_module modules/mod_rewrite.so`
     - `LoadModule headers_module modules/mod_headers.so`
     - `Include conf/extra/httpd-vhosts.conf`
  3. Reiniciá Apache desde el XAMPP Control Panel.

- [ ] **Step 2: Crear los scripts de verificación y guardar la línea de base de la landing ANTES de tocar `.htaccess`**

`scripts/verificar-landing.sh`:

```bash
#!/usr/bin/env bash
# Guarda una "huella" de la landing pública: hash del HTML + headers relevantes.
# Uso: scripts/verificar-landing.sh <url-base> <archivo-salida>
# Comparar: diff antes.txt despues.txt  (no tiene que haber diferencias)
set -euo pipefail
base="${1:?Pasá la URL base, ej. http://localhost:8080}"
salida="${2:?Pasá el archivo de salida}"
{
  echo "## sha256 de /"
  curl -s --compressed "$base/" | sha256sum | cut -d' ' -f1
  for ruta in / /css/styles.css /js/main.js /robots.txt /sitemap.xml /assets/icons/favicon.svg; do
    echo "## $ruta"
    curl -sI -H 'Accept-Encoding: br, gzip' "$base$ruta" | tr -d '\r' \
      | grep -iE '^(HTTP/|cache-control|content-type|content-encoding|strict-transport-security|x-frame-options|x-content-type-options|referrer-policy|permissions-policy|location)' \
      | sort
  done
} > "$salida"
echo "Guardado en $salida"
```

`scripts/verificar-panel.sh`:

```bash
#!/usr/bin/env bash
# Verifica rutas, bloqueos y headers del panel.
# Uso: scripts/verificar-panel.sh <url-base>
set -uo pipefail
base="${1:?Pasá la URL base, ej. http://localhost:8080}"
fallos=0

esperar() {
  local esperado="$1" ruta="$2" real
  real=$(curl -s -o /dev/null -w '%{http_code}' "$base$ruta")
  if [[ "$real" == "$esperado" ]]; then
    echo "OK    $real $ruta"
  else
    echo "FALLA $real (esperaba $esperado) $ruta"
    fallos=$((fallos + 1))
  fi
}

esperar 200 /
esperar 200 /admin-login
esperar 302 /admin
esperar 302 /admin/clientes
esperar 302 /admin/clientes/1
esperar 401 /api/clientes
esperar 401 /api/clientes/1
esperar 401 /api/reportes/balance
for ruta in /.env /.env.example /.env.testing /composer.json /composer.lock /phpunit.xml \
            /db/migrations/001_inicial.sql /db/migrate.php /admin/includes/env.php \
            /admin/includes/repos/clientes.php /scripts/hash-password.php /tests/bootstrap.php \
            /vendor/autoload.php /docs/superpowers/specs/2026-09-22-panel-admin-design.md; do
  esperar 404 "$ruta"
done

cabeceras=$(curl -sI "$base/admin-login" | tr -d '\r')
for patron in '^x-robots-tag: noindex' '^cache-control: no-store' '^content-security-policy:'; do
  if grep -qi "$patron" <<<"$cabeceras"; then
    echo "OK    header $patron"
  else
    echo "FALLA falta header $patron en /admin-login"
    fallos=$((fallos + 1))
  fi
done

echo
if [[ $fallos -eq 0 ]]; then echo "Todo OK"; else echo "$fallos verificaciones fallaron"; exit 1; fi
```

Después corré:

```bash
chmod +x scripts/*.sh
scripts/verificar-landing.sh http://localhost:8080 "$TEMP/landing-antes-local.txt"
scripts/verificar-landing.sh https://vezzadev.com "$TEMP/landing-antes-prod.txt"
cat "$TEMP/landing-antes-local.txt"
```

Resultado esperado: `HTTP/1.1 200 OK` en `/`, con los headers de caché de la landing. **No commitees los archivos de `$TEMP`**: son la línea de base contra la que se compara en las Tasks 6 y 23.

- [ ] **Step 3: Agregar el bloque del panel al FINAL de `.htaccess`**

Agregá esto después de la última línea actual (`</IfModule>` del bloque `mod_headers`). No modifiques nada de lo que ya está:

```apache

# ==========================================================================
# Panel admin (/admin, /api). Agregado al final: no modifica nada de arriba.
# ==========================================================================
<IfModule mod_alias.c>
  RedirectMatch 404 (?i)^/(admin/includes|db|scripts|tests|vendor|docs)(/|$)
  RedirectMatch 404 (?i)^/(\.env|composer\.(json|lock)$|phpunit\.xml)
</IfModule>

<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteRule ^admin-login$ admin/login.php [L,QSA]
  RewriteRule ^admin-logout$ admin/logout.php [L,QSA]
  RewriteRule ^admin/?$ admin/index.php [L,QSA]
  RewriteRule ^admin/clientes/([0-9]+)$ admin/cliente.php?id=$1 [L,QSA]
  RewriteRule ^admin/procesos/([0-9]+)$ admin/proceso.php?id=$1 [L,QSA]
  RewriteRule ^admin/(clientes|procesos|cobros|gastos|tareas|fixs|agenda|reportes|migraciones)$ admin/$1.php [L,QSA]
  RewriteRule ^api/suscripciones/([0-9]+)/pagar$ api/suscripciones.php?id=$1&accion=pagar [L,QSA]
  RewriteRule ^api/reportes/balance$ api/reportes.php [L,QSA]
  RewriteRule ^api/([a-z-]+)/([0-9]+)$ api/$1.php?id=$2 [L,QSA]
  RewriteRule ^api/([a-z-]+)$ api/$1.php [L,QSA]
</IfModule>
```

- [ ] **Step 4: Crear el script del hash y el `.env` local**

`scripts/hash-password.php`:

```php
<?php
declare(strict_types=1);

// Genera ADMIN_PASSWORD_HASH sin que la contraseña quede en ningún archivo.
// Uso (desde PowerShell o CMD): php scripts/hash-password.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function leer_oculto(string $mensaje): string
{
    fwrite(STDOUT, $mensaje);
    if (DIRECTORY_SEPARATOR === '\\') {
        $cmd = 'powershell -NoProfile -Command "$p = Read-Host -AsSecureString; '
            . '[Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';
        $valor = rtrim((string)shell_exec($cmd), "\r\n");
    } else {
        system('stty -echo');
        $valor = rtrim((string)fgets(STDIN), "\r\n");
        system('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }
    return $valor;
}

$clave = leer_oculto('Contraseña nueva: ');
$repetida = leer_oculto('Repetila: ');

if ($clave === '' || $clave !== $repetida) {
    fwrite(STDERR, "Las contraseñas no coinciden o están vacías.\n");
    exit(1);
}
if (strlen($clave) < 12) {
    fwrite(STDERR, "Usá al menos 12 caracteres.\n");
    exit(1);
}

echo PHP_EOL . 'ADMIN_PASSWORD_HASH=' . password_hash($clave, PASSWORD_BCRYPT, ['cost' => 12]) . PHP_EOL;
```

Después corré:

```bash
cp .env.example .env
php scripts/hash-password.php
```

Pegá la línea `ADMIN_PASSWORD_HASH=...` que devuelve en `.env`. En `UPLOADS_DIR` poné `C:/Users/valen/vezza_uploads/comprobantes`, así los adjuntos locales no terminan en el Escritorio. Después aplicá la migración en la base local:

```bash
php db/migrate.php
```

Resultado esperado: `Aplicadas: 001_inicial`.

- [ ] **Step 5: Commit**

Todavía no se verifica el panel: faltan las páginas, que llegan en la Task 6.

```bash
git add .htaccess scripts
git commit -m "$(cat <<'EOF'
Agregar rutas y bloqueos del panel en .htaccess y scripts de verificación

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 6: Esqueleto del panel (layout, estilos, helpers JS, login, logout, migraciones)

**Files:**
- Create: `admin/includes/layout.php`
- Create: `admin/assets/admin.css`, `admin/assets/admin.js`
- Create: `admin/login.php`, `admin/logout.php`, `admin/index.php` (versión inicial que se reemplaza en la Task 20), `admin/migraciones.php`

**Interfaces:**
- Consumes: todo `admin/includes/*` de las Tasks 1 a 4.
- Produces (PHP):
  - `layout_start(string $titulo, string $activo, array $scripts = [], array $data = []): void`
    - `$scripts` son rutas relativas a `/admin/assets/`.
    - `$data` se vuelca como atributos `data-*` en el `<body>`.
    - El `<h1 id="titulo">` y el contenedor `#page-actions` quedan disponibles para el JS.
  - `layout_end(): void`
  - La constante `PANEL_ASSET_V`.
- Produces (JS, objeto global `Panel`):
  - **API:** `api(metodo, url, cuerpo)`, `get(url, params)` (devuelve `data`), `post`, `put`, `del`, `qs(obj)`.
  - **DOM:** `el(tag, attrs, ...hijos)`, `llenar(nodo, ...hijos)`.
  - **Avisos y diálogos:** `toast(msg, tipo)`, `manejarError(err)`, `confirmar(msg, textoBoton)`.
  - **Formularios:** `modalForm({ titulo, campos, valores, enviar, eliminar, textoBoton })`, que resuelve con el resultado de `enviar`, con `{ eliminado: true }` o con `null`.
  - **Formato y fechas:** `fmtMonto(monto, moneda)`, `fmtFecha(iso, conAnio)`, `fmtHora(dt)`, `fmtFechaHora(dt)`, `aFecha(iso)`, `iso(date)`, `hoy()`, `diasHasta(iso)`.
  - **Etiquetas y badges:** `ETQ`, `opciones(mapa)`, `badge(texto, variante)`, `badgeEstado(mapa, valor)`, `vacio(texto)`.
  - **Clientes:** `clientes(refrescar)` (lista cacheada), `opcionesClientes(lista)`.
  - **Página:** `acciones(...botones)`, `boton(texto, onclick, variante)`, `tabs(contenedor, [{ id, label, render(panel) }])` ⇒ `{ activar(id) }`.
  - `ApiError` con `status` y `campos`.
- Definición de un campo de formulario:
  - `{ name, label, type, required, options, vacio, default, placeholder, list, maxlength, min, max, filtro, soloLectura, ayuda }`
  - `type` puede ser `text`, `email`, `tel`, `url`, `number`, `money`, `date`, `datetime-local`, `textarea`, `select` o `checkbox`.
  - En `options` cada opción es `[valor, etiqueta, grupo?]`.
  - `filtro: 'cliente_id'` oculta las opciones cuyo grupo no coincide con el valor elegido en ese campo.
  - `default` solo se aplica si `valores` no trae la clave.

- [ ] **Step 1: Crear el layout**

`admin/includes/layout.php`:

```php
<?php
declare(strict_types=1);

const PANEL_ASSET_V = '1';

const NAV_PRINCIPAL = [
    'inicio' => ['/admin', 'Inicio'],
    'clientes' => ['/admin/clientes', 'Clientes'],
    'procesos' => ['/admin/procesos', 'Procesos'],
    'cobros' => ['/admin/cobros', 'Cobros'],
];

const NAV_MAS = [
    'gastos' => ['/admin/gastos', 'Gastos'],
    'tareas' => ['/admin/tareas', 'Tareas'],
    'fixs' => ['/admin/fixs', 'Fixs'],
    'agenda' => ['/admin/agenda', 'Agenda'],
    'reportes' => ['/admin/reportes', 'Reportes'],
];

function nav_links(array $items, string $activo): string
{
    $html = '';
    foreach ($items as $clave => [$href, $texto]) {
        $actual = $clave === $activo ? ' aria-current="page"' : '';
        $html .= '<a href="' . e($href) . '"' . $actual . '>' . e($texto) . '</a>';
    }
    return $html;
}

function form_logout(): string
{
    return '<form method="post" action="/admin-logout">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<button type="submit">Salir</button></form>';
}

function layout_head(string $titulo): void
{
    $v = PANEL_ASSET_V;
    ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#F5F3EE">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($titulo) ?> · VEZZA Admin</title>
<link rel="icon" href="/assets/icons/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Sora:wght@600;700&display=swap">
<link rel="stylesheet" href="/admin/assets/admin.css?v=<?= $v ?>">
    <?php
}

function layout_start(string $titulo, string $activo, array $scripts = [], array $data = []): void
{
    send_panel_headers();
    header('Content-Type: text/html; charset=utf-8');
    layout_head($titulo);
    $v = PANEL_ASSET_V;
    echo '<script src="/admin/assets/admin.js?v=' . $v . '" defer></script>' . "\n";
    foreach ($scripts as $script) {
        echo '<script src="/admin/assets/' . e($script) . '?v=' . $v . '" defer></script>' . "\n";
    }
    $attrs = '';
    foreach ($data as $clave => $valor) {
        $attrs .= ' data-' . e($clave) . '="' . e($valor) . '"';
    }
    $masActivo = array_key_exists($activo, NAV_MAS) || $activo === 'migraciones';
    ?>
</head>
<body<?= $attrs ?>>
<a class="skip" href="#main">Saltar al contenido</a>
<nav class="sidebar" aria-label="Principal">
  <div class="marca">VEZZA</div>
  <?= nav_links(NAV_PRINCIPAL + NAV_MAS, $activo) ?>
  <a href="/admin/migraciones"<?= $activo === 'migraciones' ? ' aria-current="page"' : '' ?>>Migraciones</a>
  <?= form_logout() ?>
</nav>
<nav class="bottom-nav" aria-label="Principal (móvil)">
  <?= nav_links(NAV_PRINCIPAL, $activo) ?>
  <details class="nav-mas<?= $masActivo ? ' activo' : '' ?>">
    <summary>Más</summary>
    <div class="nav-mas-menu">
      <?= nav_links(NAV_MAS, $activo) ?>
      <a href="/admin/migraciones">Migraciones</a>
      <?= form_logout() ?>
    </div>
  </details>
</nav>
<main id="main" class="main">
  <header class="page-head">
    <h1 id="titulo"><?= e($titulo) ?></h1>
    <div class="page-actions" id="page-actions"></div>
  </header>
    <?php
}

function layout_end(): void
{
    echo "</main>\n<div id=\"toasts\" class=\"toasts\" aria-live=\"polite\"></div>\n</body>\n</html>\n";
}

function pagina_no_encontrada(string $texto, string $volverHref, string $volverTexto, string $activo): void
{
    http_response_code(404);
    layout_start('No encontrado', $activo);
    echo '<p>' . e($texto) . ' <a href="' . e($volverHref) . '">' . e($volverTexto) . '</a></p>';
    layout_end();
    exit;
}
```

- [ ] **Step 2: Crear los estilos**

`admin/assets/admin.css`:

```css
/* VEZZA · Panel admin — mobile-first */
:root {
  --bg: #F5F3EE;
  --surface: #FFFFFF;
  --surface-2: #ECE9E1;
  --text: #15131C;
  --muted: #5E5A6B;
  --line: #E1DDD2;
  --accent: #3D2FE0;
  --accent-hover: #3226C0;
  --accent-soft: #E9E6FC;
  --ok: #1F7A4D;
  --ok-soft: #E3F3EA;
  --warn: #9A5B00;
  --warn-soft: #FBEFD9;
  --danger: #B42318;
  --danger-soft: #FBE4E1;
  --violeta: #7A4FD6;
  --radius: 12px;
  --shadow: 0 1px 2px rgba(21, 19, 28, .06), 0 2px 8px rgba(21, 19, 28, .05);
  --font: 'Manrope', system-ui, -apple-system, 'Segoe UI', sans-serif;
  --font-title: 'Sora', var(--font);
  --nav-h: 64px;
}

*, *::before, *::after { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }
body {
  margin: 0;
  background: var(--bg);
  color: var(--text);
  font: 15px/1.5 var(--font);
  padding-bottom: calc(var(--nav-h) + env(safe-area-inset-bottom));
}
h1, h2, h3 { font-family: var(--font-title); line-height: 1.25; margin: 0; }
h1 { font-size: 1.4rem; }
h2 { font-size: 1.1rem; }
h3 { font-size: 1rem; }
a { color: var(--accent); }
p { margin: 0 0 8px; }
:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
[hidden] { display: none !important; }
.negativo { color: var(--danger); }

.skip { position: absolute; left: -999px; top: 8px; }
.skip:focus { left: 16px; z-index: 100; background: var(--surface); padding: 8px 12px; border-radius: 8px; }

.main { max-width: 1200px; margin: 0 auto; padding: 16px; }
.page-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin: 4px 0 16px; }
.page-actions { display: flex; gap: 8px; flex-wrap: wrap; }

/* ---------- Navegación ---------- */
.sidebar { display: none; }
.bottom-nav {
  position: fixed; left: 0; right: 0; bottom: 0; z-index: 50;
  height: calc(var(--nav-h) + env(safe-area-inset-bottom));
  padding-bottom: env(safe-area-inset-bottom);
  background: var(--surface); border-top: 1px solid var(--line);
  display: grid; grid-template-columns: repeat(5, 1fr);
}
.bottom-nav > a, .bottom-nav summary {
  display: flex; align-items: center; justify-content: center;
  height: var(--nav-h); color: var(--muted); text-decoration: none;
  font-size: .8rem; font-weight: 700; cursor: pointer; list-style: none;
}
.bottom-nav summary::-webkit-details-marker { display: none; }
.bottom-nav > a[aria-current="page"], .bottom-nav .activo > summary { color: var(--accent); }
.nav-mas { position: relative; }
.nav-mas-menu {
  position: absolute; right: 8px; bottom: calc(100% + 8px); min-width: 210px;
  background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
  box-shadow: 0 8px 24px rgba(21, 19, 28, .15); padding: 6px; display: grid;
}
.nav-mas-menu a, .nav-mas-menu button, .sidebar a, .sidebar button {
  display: block; width: 100%; min-height: 44px; padding: 11px 14px; border: 0; border-radius: 8px;
  background: none; color: var(--text); font: inherit; font-weight: 600; text-align: left;
  text-decoration: none; cursor: pointer;
}
.nav-mas-menu a:hover, .nav-mas-menu button:hover, .sidebar a:hover, .sidebar button:hover { background: var(--bg); }
.nav-mas-menu a[aria-current="page"], .sidebar a[aria-current="page"] { background: var(--accent-soft); color: var(--accent); }
.nav-mas-menu form, .sidebar form { margin: 0; }

@media (min-width: 900px) {
  body { padding-bottom: 0; padding-left: 232px; }
  .bottom-nav { display: none; }
  .sidebar {
    display: flex; flex-direction: column; gap: 2px;
    position: fixed; top: 0; bottom: 0; left: 0; width: 232px; overflow-y: auto;
    background: var(--surface); border-right: 1px solid var(--line); padding: 20px 12px;
  }
  .sidebar .marca { font-family: var(--font-title); font-weight: 700; font-size: 1.2rem; padding: 4px 14px 16px; }
  .sidebar form { margin-top: auto; }
  .main { padding: 24px 32px; }
}

/* ---------- Botones ---------- */
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  min-height: 44px; padding: 0 16px; border-radius: 10px; border: 1px solid var(--line);
  background: var(--surface); color: var(--text); font: inherit; font-weight: 700;
  text-decoration: none; cursor: pointer; white-space: nowrap;
}
.btn:hover { border-color: var(--text); }
.btn:disabled { opacity: .6; cursor: default; }
.btn-primario { background: var(--accent); border-color: var(--accent); color: #fff; }
.btn-primario:hover { background: var(--accent-hover); border-color: var(--accent-hover); }
.btn-peligro { color: var(--danger); }
.btn-chico { min-height: 36px; padding: 0 12px; font-size: .875rem; }
.btn[aria-pressed="true"] { background: var(--accent-soft); border-color: var(--accent); color: var(--accent); }
.boton-texto { display: grid; gap: 4px; padding: 0; border: 0; background: none; color: inherit; font: inherit; text-align: left; cursor: pointer; }

/* ---------- Formularios ---------- */
.campo { display: grid; gap: 6px; margin-bottom: 14px; font-weight: 600; font-size: .875rem; }
.campo label { font-weight: 700; font-size: .875rem; }
.campo input, .campo select, .campo textarea, .filtro {
  width: 100%; min-height: 44px; padding: 10px 12px; border: 1px solid var(--line); border-radius: 10px;
  background: var(--surface); color: var(--text); font: inherit; font-size: 16px; font-weight: 500;
}
.campo textarea, textarea.filtro { min-height: 110px; resize: vertical; }
.campo input:disabled, .campo select:disabled { background: var(--bg); color: var(--muted); }
.campo-check { display: flex; align-items: center; gap: 10px; }
.campo-check input { width: 22px; height: 22px; min-height: 0; }
.campo-error { color: var(--danger); font-size: .8125rem; font-weight: 600; }
.campo-error:empty { display: none; }
.campo.invalido input, .campo.invalido select, .campo.invalido textarea { border-color: var(--danger); }
.form-error { background: var(--danger-soft); color: var(--danger); padding: 10px 12px; border-radius: 10px; margin-bottom: 12px; font-weight: 600; }
.aviso { background: var(--accent-soft); color: var(--text); padding: 10px 12px; border-radius: 10px; margin-bottom: 12px; }
.toolbar { display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 16px; }
.toolbar .filtro { width: auto; flex: 1 1 160px; }
.toolbar .campo { flex: 1 1 150px; margin: 0; }

/* ---------- Tarjetas y listas ---------- */
.card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 16px; box-shadow: var(--shadow); }
.lista { display: grid; gap: 10px; }
.item {
  display: grid; gap: 4px; width: 100%; padding: 14px 16px; text-align: left;
  background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
  color: inherit; font: inherit; text-decoration: none;
}
a.item:hover, button.item:hover, .item.clickeable:hover { border-color: var(--accent); cursor: pointer; }
.item-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
.item-titulo { font-weight: 700; text-decoration: none; color: inherit; }
a.item-titulo:hover { color: var(--accent); }
.item-sub { color: var(--muted); font-size: .875rem; }
.item-acciones { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin-top: 6px; }
.item-acciones .filtro { width: auto; min-height: 36px; padding: 4px 8px; font-size: 14px; }
.meta { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: .75rem; font-weight: 700; background: var(--bg); color: var(--muted); white-space: nowrap; }
.badge-ok { background: var(--ok-soft); color: var(--ok); }
.badge-warn { background: var(--warn-soft); color: var(--warn); }
.badge-danger { background: var(--danger-soft); color: var(--danger); }
.badge-accent { background: var(--accent-soft); color: var(--accent); }
.vacio { text-align: center; color: var(--muted); padding: 32px 16px; border: 1px dashed var(--line); border-radius: var(--radius); }
.nota-texto { white-space: pre-wrap; overflow-wrap: anywhere; margin: 4px 0 0; }
.datos { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-top: 12px; }
.grid-2 { display: grid; gap: 16px; }
@media (min-width: 900px) { .grid-2 { grid-template-columns: 1fr 1fr; } }
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 16px; }
.stat { display: block; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 14px; color: inherit; text-decoration: none; }
a.stat:hover { border-color: var(--accent); }
.stat-label { color: var(--muted); font-size: .8125rem; font-weight: 700; }
.stat-valor { font-family: var(--font-title); font-size: 1.3rem; font-weight: 700; }
.seccion { margin-top: 24px; }
.seccion > h2, .seccion > .item-top { margin-bottom: 10px; }

/* ---------- Tabs ---------- */
.tabs { display: flex; gap: 4px; overflow-x: auto; border-bottom: 1px solid var(--line); margin-bottom: 16px; scrollbar-width: none; }
.tabs button { flex: 0 0 auto; min-height: 44px; padding: 0 14px; border: 0; border-bottom: 3px solid transparent; background: none; color: var(--muted); font: inherit; font-weight: 700; cursor: pointer; }
.tabs button[aria-selected="true"] { color: var(--accent); border-bottom-color: var(--accent); }

/* ---------- Kanban ---------- */
.kanban { display: grid; grid-auto-flow: column; grid-auto-columns: 85%; gap: 12px; overflow-x: auto; scroll-snap-type: x mandatory; padding-bottom: 8px; }
.kanban-col { scroll-snap-align: start; display: flex; flex-direction: column; min-height: 220px; background: var(--surface-2); border-radius: var(--radius); padding: 10px; }
.kanban-col h3 { display: flex; justify-content: space-between; align-items: center; padding: 4px 4px 10px; font-size: .9rem; }
.kanban-lista { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; align-content: start; flex: 1; min-height: 60px; }
.kanban-card { display: grid; gap: 6px; padding: 12px; background: var(--surface); border: 1px solid var(--line); border-radius: 10px; box-shadow: var(--shadow); cursor: grab; }
.kanban-card .filtro { min-height: 36px; padding: 4px 8px; font-size: 14px; }
.sortable-ghost { opacity: .4; }
.sortable-chosen { box-shadow: 0 8px 24px rgba(21, 19, 28, .18); }
@media (min-width: 900px) { .kanban { grid-auto-columns: minmax(250px, 1fr); } }

/* ---------- Checklist ---------- */
.checklist { list-style: none; margin: 12px 0 0; padding: 0; display: grid; gap: 6px; }
.check-item { display: flex; align-items: center; gap: 10px; padding: 8px 10px; background: var(--surface); border: 1px solid var(--line); border-radius: 10px; }
.check-item input[type="checkbox"] { width: 22px; height: 22px; flex: 0 0 auto; }
.check-item .texto { flex: 1; min-width: 0; }
.check-item.hecha .item-titulo { text-decoration: line-through; color: var(--muted); }
.asa { cursor: grab; color: var(--muted); padding: 4px 6px; user-select: none; }
.progreso { height: 6px; background: var(--line); border-radius: 999px; overflow: hidden; margin: 8px 0 12px; }
.progreso > span { display: block; height: 100%; background: var(--accent); }

/* ---------- Modal ---------- */
dialog.modal { border: 0; border-radius: 16px; padding: 0; width: min(560px, calc(100% - 32px)); max-height: calc(100% - 32px); background: var(--surface); color: var(--text); box-shadow: 0 20px 60px rgba(21, 19, 28, .25); }
dialog.modal::backdrop { background: rgba(21, 19, 28, .45); }
dialog.modal form { display: flex; flex-direction: column; max-height: inherit; }
.modal-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 12px 12px 12px 20px; border-bottom: 1px solid var(--line); }
.modal-body { padding: 16px 20px; overflow-y: auto; flex: 1; }
.modal-pie { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; padding: 12px 20px 20px; border-top: 1px solid var(--line); }
.modal-pie .separador { flex: 1; }
.btn-cerrar { min-width: 44px; min-height: 44px; border: 0; background: none; color: var(--muted); font-size: 1.6rem; line-height: 1; cursor: pointer; }
@media (max-width: 639px) {
  dialog.modal { width: 100%; max-width: 100%; height: 100%; max-height: 100%; margin: 0; border-radius: 0; }
  dialog.modal form { height: 100%; }
}

/* ---------- Toasts ---------- */
.toasts { position: fixed; left: 50%; transform: translateX(-50%); bottom: calc(var(--nav-h) + 16px + env(safe-area-inset-bottom)); z-index: 200; display: grid; gap: 8px; width: min(420px, calc(100% - 32px)); }
.toast { background: var(--text); color: #fff; padding: 12px 16px; border-radius: 10px; box-shadow: var(--shadow); font-weight: 700; }
.toast-error { background: var(--danger); }
@media (min-width: 900px) { .toasts { bottom: 24px; } }

/* ---------- Tabla (desktop) → tarjetas (móvil) ---------- */
.tabla { width: 100%; border-collapse: collapse; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; }
.tabla th, .tabla td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--line); }
.tabla th { font-size: .8125rem; color: var(--muted); }
.tabla .num { text-align: right; font-variant-numeric: tabular-nums; }
@media (max-width: 639px) {
  .tabla thead { display: none; }
  .tabla, .tabla tbody, .tabla tr, .tabla td { display: block; width: 100%; }
  .tabla tr { padding: 8px 0; border-bottom: 1px solid var(--line); }
  .tabla td { display: flex; justify-content: space-between; gap: 12px; border: 0; padding: 4px 12px; }
  .tabla td::before { content: attr(data-label); color: var(--muted); font-weight: 700; font-size: .8125rem; }
}
.barras { display: grid; gap: 4px; min-width: 120px; }
.barra { height: 8px; border-radius: 999px; }
.barra-ing { background: var(--ok); }
.barra-gas { background: var(--danger); }

/* ---------- Calendario ---------- */
.cal-nav { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.cal-nav h2 { flex: 1; text-align: center; text-transform: capitalize; }
.cal-grid { display: none; }
.cal-lista { display: grid; gap: 14px; }
.cal-dia h3 { font-size: .875rem; color: var(--muted); margin-bottom: 6px; text-transform: capitalize; }
.cal-dia.hoy h3 { color: var(--accent); }
.cal-item {
  display: flex; align-items: center; gap: 8px; width: 100%; min-height: 44px; margin-bottom: 6px; padding: 8px 10px;
  background: var(--surface); border: 1px solid var(--line); border-left: 4px solid var(--c, var(--muted)); border-radius: 8px;
  color: var(--text); font: inherit; text-align: left; cursor: pointer;
}
.cal-item .hora { font-weight: 700; font-size: .8125rem; color: var(--muted); }
.cal-item .titulo { min-width: 0; }
.t-evento { --c: var(--accent); }
.t-cobro { --c: var(--ok); }
.t-cobro.vencido { --c: var(--danger); }
.t-suscripcion { --c: var(--warn); }
.t-entrega { --c: var(--violeta); }
.t-tarea { --c: var(--muted); }
.leyenda { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 14px; font-size: .8125rem; color: var(--muted); }
.leyenda span::before { content: ''; display: inline-block; width: 10px; height: 10px; margin-right: 6px; border-radius: 3px; background: var(--c); vertical-align: middle; }
@media (min-width: 900px) {
  .cal-lista { display: none; }
  .cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 1px; background: var(--line); border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; }
  .cal-grid .cab { background: var(--bg); padding: 6px; text-align: center; font-size: .75rem; font-weight: 700; color: var(--muted); }
  .cal-celda { display: flex; flex-direction: column; gap: 3px; min-height: 112px; padding: 6px; background: var(--surface); }
  .cal-celda.fuera { background: #FAF9F6; }
  .cal-celda.fuera .num-dia { color: var(--muted); }
  .num-dia { width: 26px; height: 26px; display: grid; place-items: center; border: 0; border-radius: 999px; background: none; color: var(--text); font: inherit; font-size: .75rem; font-weight: 700; cursor: pointer; }
  .num-dia:hover { background: var(--accent-soft); }
  .cal-celda.hoy .num-dia { background: var(--accent); color: #fff; }
  .cal-celda .cal-item { min-height: 0; margin: 0; padding: 2px 6px; font-size: .75rem; border-left-width: 3px; }
  .cal-celda .cal-item .titulo { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
}

/* ---------- Login ---------- */
.login-body { padding: 0; min-height: 100vh; display: grid; place-items: center; }
.login { width: min(400px, calc(100% - 32px)); }
.login h1 { margin-bottom: 4px; }
.login p { color: var(--muted); margin-bottom: 20px; }
.login .btn { width: 100%; }
```

- [ ] **Step 3: Crear los helpers JS**

`admin/assets/admin.js`:

```js
'use strict';

const Panel = (() => {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

  class ApiError extends Error {
    constructor(mensaje, status, campos) {
      super(mensaje);
      this.status = status;
      this.campos = campos || {};
    }
  }

  function qs(params) {
    if (!params) return '';
    const p = new URLSearchParams();
    for (const [k, v] of Object.entries(params)) {
      if (v !== null && v !== undefined && v !== '') p.set(k, v);
    }
    const s = p.toString();
    return s ? `?${s}` : '';
  }

  async function api(metodo, url, cuerpo) {
    const opciones = { method: metodo, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': csrf } };
    if (cuerpo instanceof FormData) {
      opciones.body = cuerpo;
    } else if (cuerpo !== undefined) {
      opciones.headers['Content-Type'] = 'application/json';
      opciones.body = JSON.stringify(cuerpo);
    }
    let res;
    try {
      res = await fetch(url, opciones);
    } catch {
      throw new ApiError('Sin conexión. Revisá tu internet y probá de nuevo.', 0);
    }
    if (res.status === 401) {
      location.href = `/admin-login?next=${encodeURIComponent(location.pathname + location.search)}`;
      throw new ApiError('Tu sesión expiró', 401);
    }
    if (res.status === 204) return null;
    let datos = null;
    try { datos = await res.json(); } catch { datos = null; }
    if (!res.ok) throw new ApiError(datos?.error || `Error ${res.status}`, res.status, datos?.campos);
    return datos;
  }

  const get = (url, params) => api('GET', url + qs(params)).then((r) => r.data);
  const post = (url, cuerpo) => api('POST', url, cuerpo ?? {}).then((r) => r?.data);
  const put = (url, cuerpo) => api('PUT', url, cuerpo).then((r) => r?.data);
  const del = (url) => api('DELETE', url).then(() => ({ eliminado: true }));

  function el(tag, attrs, ...hijos) {
    const nodo = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') nodo.className = v;
      else if (k === 'text') nodo.textContent = v;
      else if (k === 'dataset') Object.assign(nodo.dataset, v);
      else if (k.startsWith('on') && typeof v === 'function') nodo.addEventListener(k.slice(2).toLowerCase(), v);
      else if (v === true) nodo.setAttribute(k, '');
      else nodo.setAttribute(k, String(v));
    }
    for (const h of hijos.flat(Infinity)) {
      if (h === null || h === undefined || h === false) continue;
      nodo.append(h instanceof Node ? h : document.createTextNode(String(h)));
    }
    return nodo;
  }

  function llenar(nodo, ...hijos) {
    nodo.replaceChildren(...hijos.flat(Infinity).filter((h) => h !== null && h !== undefined && h !== false));
  }

  function toast(mensaje, tipo = 'ok') {
    const cont = document.getElementById('toasts');
    if (!cont) return;
    const t = el('div', { class: `toast${tipo === 'error' ? ' toast-error' : ''}`, role: tipo === 'error' ? 'alert' : 'status', text: mensaje });
    cont.append(t);
    setTimeout(() => t.remove(), tipo === 'error' ? 6000 : 3000);
  }

  function manejarError(err) {
    if (err?.status === 401) return;
    console.error(err);
    toast(err?.message || 'Algo salió mal', 'error');
  }

  function dialogo(titulo, contenido, pie) {
    const cerrar = el('button', { type: 'button', class: 'btn-cerrar', 'aria-label': 'Cerrar', text: '×' });
    const form = el('form', { novalidate: true },
      el('div', { class: 'modal-head' }, el('h2', { text: titulo }), cerrar),
      el('div', { class: 'modal-body' }, contenido),
      el('div', { class: 'modal-pie' }, pie));
    const d = el('dialog', { class: 'modal' }, form);
    cerrar.addEventListener('click', () => d.close());
    d.addEventListener('close', () => d.remove());
    document.body.append(d);
    d.showModal();
    return { d, form };
  }

  function confirmar(mensaje, textoBoton = 'Eliminar') {
    return new Promise((resolver) => {
      let ok = false;
      const cancelar = el('button', { type: 'button', class: 'btn', text: 'Cancelar' });
      const aceptar = el('button', { type: 'submit', class: 'btn btn-primario', text: textoBoton });
      const { d, form } = dialogo('Confirmar', el('p', { text: mensaje }), [cancelar, aceptar]);
      cancelar.addEventListener('click', () => d.close());
      form.addEventListener('submit', (e) => { e.preventDefault(); ok = true; d.close(); });
      d.addEventListener('close', () => resolver(ok));
      aceptar.focus();
    });
  }

  function crearCampo(def, valor) {
    const id = `f-${def.name}-${Math.random().toString(36).slice(2, 7)}`;
    const tipo = def.type || 'text';
    const inicial = valor === undefined ? (def.default ?? '') : (valor ?? '');
    let control;
    let datalist = null;
    if (tipo === 'textarea') {
      control = el('textarea', { id, name: def.name, rows: 4, placeholder: def.placeholder, disabled: def.soloLectura }, String(inicial));
    } else if (tipo === 'select') {
      control = el('select', { id, name: def.name, disabled: def.soloLectura });
      if (def.vacio !== undefined) control.append(el('option', { value: '', text: def.vacio }));
      for (const [value, label, grupo] of def.options || []) {
        control.append(el('option', { value, text: label, dataset: grupo !== undefined && grupo !== null ? { grupo: String(grupo) } : null }));
      }
      control.value = inicial === '' ? (control.options[0]?.value ?? '') : String(inicial);
    } else if (tipo === 'checkbox') {
      const marcado = valor === undefined || valor === null ? Boolean(def.default) : Boolean(Number(valor));
      control = el('input', { id, name: def.name, type: 'checkbox', checked: marcado, disabled: def.soloLectura });
    } else {
      let v = String(inicial);
      if (tipo === 'datetime-local' && v) v = v.replace(' ', 'T').slice(0, 16);
      const attrs = {
        id, name: def.name, type: tipo === 'money' ? 'text' : tipo, value: v, placeholder: def.placeholder,
        autocomplete: 'off', maxlength: def.maxlength, min: def.min, max: def.max, disabled: def.soloLectura,
      };
      if (tipo === 'money') attrs.inputmode = 'decimal';
      if (tipo === 'number') attrs.inputmode = 'numeric';
      if (def.list) {
        attrs.list = `${id}-list`;
        datalist = el('datalist', { id: `${id}-list` }, def.list.map((x) => el('option', { value: x })));
      }
      control = el('input', attrs);
    }
    const error = el('div', { class: 'campo-error' });
    const label = el('label', { for: id, text: def.label + (def.required ? ' *' : '') });
    const ayuda = def.ayuda ? el('small', { class: 'item-sub', text: def.ayuda }) : null;
    const fila = tipo === 'checkbox'
      ? el('div', { class: 'campo' }, el('div', { class: 'campo-check' }, control, label), ayuda, error)
      : el('div', { class: 'campo' }, label, control, datalist, ayuda, error);
    return { fila, control };
  }

  function filtrarOpciones(select, valor) {
    for (const op of select.options) {
      if (op.dataset.grupo === undefined) continue;
      const visible = valor !== '' && op.dataset.grupo === String(valor);
      op.hidden = !visible;
      op.disabled = !visible;
    }
  }

  function leerValores(controles) {
    const datos = {};
    for (const [nombre, { def, control }] of Object.entries(controles)) {
      if (def.soloLectura) continue;
      if (def.type === 'checkbox') {
        datos[nombre] = control.checked;
      } else {
        const v = control.value.trim();
        datos[nombre] = v === '' ? null : v;
      }
    }
    return datos;
  }

  function modalForm({ titulo, campos, valores = {}, enviar, eliminar = null, textoBoton = 'Guardar' }) {
    return new Promise((resolver) => {
      let resultado = null;
      const errorGeneral = el('div', { class: 'form-error', role: 'alert', hidden: true });
      const controles = {};
      const filas = campos.map((def) => {
        const { fila, control } = crearCampo(def, valores[def.name]);
        controles[def.name] = { def, control, fila };
        return fila;
      });
      for (const { def, control } of Object.values(controles)) {
        if (!def.filtro || !controles[def.filtro]) continue;
        const origen = controles[def.filtro].control;
        filtrarOpciones(control, origen.value);
        origen.addEventListener('change', () => {
          filtrarOpciones(control, origen.value);
          if (control.selectedOptions[0]?.hidden) control.value = '';
        });
      }

      const cancelar = el('button', { type: 'button', class: 'btn', text: 'Cancelar' });
      const guardar = el('button', { type: 'submit', class: 'btn btn-primario', text: textoBoton });
      const pie = [];
      if (eliminar) {
        pie.push(el('button', {
          type: 'button', class: 'btn btn-peligro', text: 'Eliminar',
          onclick: async () => {
            if (!(await confirmar('¿Seguro que querés eliminarlo? No se puede deshacer.'))) return;
            try { resultado = (await eliminar()) || { eliminado: true }; d.close(); } catch (err) { mostrarError(err); }
          },
        }), el('span', { class: 'separador' }));
      }
      pie.push(cancelar, guardar);
      const { d, form } = dialogo(titulo, [errorGeneral, ...filas], pie);
      cancelar.addEventListener('click', () => d.close());
      d.addEventListener('close', () => resolver(resultado));

      function mostrarError(err) {
        for (const { fila } of Object.values(controles)) {
          fila.classList.remove('invalido');
          fila.querySelector('.campo-error').textContent = '';
        }
        if (err?.status === 401) return;
        errorGeneral.textContent = err?.message || 'Algo salió mal';
        errorGeneral.hidden = false;
        let primero = null;
        for (const [nombre, msg] of Object.entries(err?.campos || {})) {
          const c = controles[nombre];
          if (!c) continue;
          c.fila.classList.add('invalido');
          c.fila.querySelector('.campo-error').textContent = msg;
          primero ??= c.control;
        }
        primero?.focus();
      }

      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        guardar.disabled = true;
        errorGeneral.hidden = true;
        try {
          resultado = await enviar(leerValores(controles));
          d.close();
        } catch (err) {
          mostrarError(err);
        } finally {
          guardar.disabled = false;
        }
      });
      form.querySelector('input:not([disabled]), select:not([disabled]), textarea:not([disabled])')?.focus();
    });
  }

  function aFecha(isoTexto) {
    const [y, m, d] = String(isoTexto).slice(0, 10).split('-').map(Number);
    return new Date(y, m - 1, d);
  }
  const iso = (f) => `${f.getFullYear()}-${String(f.getMonth() + 1).padStart(2, '0')}-${String(f.getDate()).padStart(2, '0')}`;
  const hoy = () => iso(new Date());
  const diasHasta = (isoTexto) => Math.round((aFecha(isoTexto) - aFecha(hoy())) / 86400000);

  function fmtMonto(monto, moneda) {
    const n = Number(monto);
    try {
      return new Intl.NumberFormat('es-AR', { style: 'currency', currency: moneda || 'ARS' }).format(n);
    } catch {
      return `${moneda} ${n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }
  }
  function fmtFecha(isoTexto, conAnio = false) {
    if (!isoTexto) return '';
    const f = aFecha(isoTexto);
    const opciones = { day: 'numeric', month: 'short' };
    if (conAnio || f.getFullYear() !== new Date().getFullYear()) opciones.year = 'numeric';
    return f.toLocaleDateString('es-AR', opciones);
  }
  const fmtHora = (dt) => String(dt).slice(11, 16);
  const fmtFechaHora = (dt) => `${fmtFecha(dt)} ${fmtHora(dt)}`;

  const ETQ = {
    estadoCliente: { activo: 'Activo', pausado: 'Pausado', finalizado: 'Finalizado' },
    estadoProceso: { por_hacer: 'Por hacer', en_curso: 'En curso', en_revision: 'En revisión', entregado: 'Entregado' },
    prioridad: { alta: 'Alta', media: 'Media', baja: 'Baja' },
    estadoCobro: { pendiente: 'Pendiente', vencido: 'Vencido', pagado: 'Pagado' },
    estadoTarea: { pendiente: 'Pendiente', en_curso: 'En curso', hecha: 'Hecha' },
    estadoFix: { reportado: 'Reportado', en_progreso: 'En progreso', resuelto: 'Resuelto' },
    tipoEvento: { reunion: 'Reunión', llamada: 'Llamada', recordatorio: 'Recordatorio', otro: 'Otro' },
    frecuencia: { mensual: 'Mensual', anual: 'Anual' },
  };
  const VARIANTE = {
    activo: 'ok', pagado: 'ok', entregado: 'ok', hecha: 'ok', resuelto: 'ok',
    vencido: 'danger', alta: 'danger',
    pausado: 'warn', pendiente: 'warn', reportado: 'warn',
    en_curso: 'accent', en_revision: 'accent', en_progreso: 'accent',
  };
  const opciones = (mapa) => Object.entries(mapa);
  const badge = (texto, variante) => el('span', { class: `badge${variante ? ` badge-${variante}` : ''}`, text: texto });
  const badgeEstado = (mapa, valor) => badge(ETQ[mapa]?.[valor] ?? valor, VARIANTE[valor]);
  const vacio = (texto) => el('div', { class: 'vacio', text: texto });

  let cacheClientes = null;
  function clientes(refrescar = false) {
    if (refrescar || !cacheClientes) {
      cacheClientes = get('/api/clientes').catch((err) => { cacheClientes = null; throw err; });
    }
    return cacheClientes;
  }
  const opcionesClientes = (lista) => lista.map((c) => [String(c.id), c.nombre]);

  const boton = (texto, onclick, variante = '') =>
    el('button', { type: 'button', class: `btn${variante ? ` btn-${variante}` : ''}`, text: texto, onclick });
  const acciones = (...botones) => llenar(document.getElementById('page-actions'), botones);

  function tabs(contenedor, lista) {
    const panel = el('div', { role: 'tabpanel' });
    const botones = lista.map((t) => el('button', { type: 'button', role: 'tab', 'aria-selected': 'false', text: t.label, onclick: () => activar(t.id) }));
    llenar(contenedor, el('div', { class: 'tabs', role: 'tablist' }, botones), panel);
    function activar(id) {
      const t = lista.find((x) => x.id === id) || lista[0];
      lista.forEach((x, i) => botones[i].setAttribute('aria-selected', String(x.id === t.id)));
      history.replaceState(null, '', `${location.pathname}${location.search}#${t.id}`);
      llenar(panel, el('p', { class: 'item-sub', text: 'Cargando…' }));
      Promise.resolve().then(() => t.render(panel)).catch(manejarError);
    }
    activar(location.hash.slice(1));
    return { activar, actual: () => location.hash.slice(1) };
  }

  document.addEventListener('click', (e) => {
    const abierto = document.querySelector('.nav-mas[open]');
    if (abierto && !abierto.contains(e.target)) abierto.removeAttribute('open');
  });

  return {
    ApiError, api, get, post, put, del, qs, el, llenar, toast, manejarError, confirmar, modalForm,
    aFecha, iso, hoy, diasHasta, fmtMonto, fmtFecha, fmtHora, fmtFechaHora,
    ETQ, opciones, badge, badgeEstado, vacio, clientes, opcionesClientes, boton, acciones, tabs,
  };
})();
```

- [ ] **Step 4: Crear las páginas base**

`admin/login.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

session_boot();
$nextCrudo = $_GET['next'] ?? $_POST['next'] ?? null;
$next = safe_next(is_string($nextCrudo) ? $nextCrudo : null);

if (auth_logged()) {
    header('Location: ' . $next, true, 302);
    exit;
}

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $token = $_POST['csrf'] ?? null;
        csrf_check(is_string($token) ? $token : null);
        $usuario = is_string($_POST['usuario'] ?? null) ? $_POST['usuario'] : '';
        $clave = is_string($_POST['clave'] ?? null) ? $_POST['clave'] : '';
        $resultado = auth_attempt($usuario, $clave, client_ip());
        if ($resultado === 'ok') {
            header('Location: ' . $next, true, 303);
            exit;
        }
        $error = $resultado === 'bloqueado'
            ? 'Demasiados intentos, probá en unos minutos.'
            : 'Usuario o contraseña incorrectos.';
        http_response_code($resultado === 'bloqueado' ? 429 : 401);
    } catch (HttpError $e) {
        $error = $e->getMessage();
        http_response_code($e->status);
    }
}

send_panel_headers();
header('Content-Type: text/html; charset=utf-8');
layout_head('Ingresar');
?>
</head>
<body class="login-body">
<main class="login card">
  <h1>VEZZA Admin</h1>
  <p>Ingresá para ver el panel.</p>
  <?php if ($error !== null): ?>
    <div class="form-error" role="alert"><?= e($error) ?></div>
  <?php endif; ?>
  <form method="post" action="/admin-login">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <div class="campo">
      <label for="usuario">Usuario</label>
      <input id="usuario" name="usuario" autocomplete="username" autocapitalize="none" required autofocus>
    </div>
    <div class="campo">
      <label for="clave">Contraseña</label>
      <input id="clave" name="clave" type="password" autocomplete="current-password" required>
    </div>
    <button class="btn btn-primario" type="submit">Ingresar</button>
  </form>
</main>
</body>
</html>
```

`admin/logout.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

session_boot();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: /admin', true, 303);
    exit;
}
try {
    $token = $_POST['csrf'] ?? null;
    csrf_check(is_string($token) ? $token : null);
} catch (HttpError) {
    header('Location: /admin', true, 303);
    exit;
}
auth_logout();
header('Location: /admin-login', true, 303);
```

`admin/index.php` (versión inicial; la Task 20 la reemplaza por el dashboard):

```php
<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();
layout_start('Inicio', 'inicio');
?>
<p class="aviso">El panel está funcionando. El resumen del negocio llega en la Task 20.</p>
<?php
layout_end();
```

`admin/migraciones.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();

$aplicadas = null;
$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $token = $_POST['csrf'] ?? null;
        csrf_check(is_string($token) ? $token : null);
        $aplicadas = migrar(db());
    } catch (HttpError $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[panel] ' . $e);
        $error = 'Falló la migración: ' . $e->getMessage();
    }
}
$pendientes = array_keys(migraciones_pendientes(db()));

layout_start('Migraciones', 'migraciones');
?>
<?php if ($error !== null): ?>
  <div class="form-error" role="alert"><?= e($error) ?></div>
<?php endif; ?>
<?php if ($aplicadas !== null): ?>
  <div class="aviso"><?= $aplicadas ? 'Aplicadas: ' . e(implode(', ', $aplicadas)) : 'No había migraciones pendientes.' ?></div>
<?php endif; ?>
<section class="card">
  <?php if ($pendientes): ?>
    <p>Hay <?= count($pendientes) ?> migración(es) pendiente(s):</p>
    <ul><?php foreach ($pendientes as $nombre): ?><li><?= e($nombre) ?></li><?php endforeach; ?></ul>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <button class="btn btn-primario" type="submit">Aplicar migraciones</button>
    </form>
  <?php else: ?>
    <p>La base está al día.</p>
  <?php endif; ?>
</section>
<?php
layout_end();
```

- [ ] **Step 5: Verificar en el navegador y con los scripts**

Correr:

```bash
php -l admin/login.php && php -l admin/logout.php && php -l admin/index.php && php -l admin/migraciones.php && php -l admin/includes/layout.php
scripts/verificar-panel.sh http://localhost:8080
scripts/verificar-landing.sh http://localhost:8080 "$TEMP/landing-despues-local.txt"
diff "$TEMP/landing-antes-local.txt" "$TEMP/landing-despues-local.txt" && echo "Landing sin cambios"
php vendor/bin/phpunit
```

Resultado esperado:
- En `verificar-panel.sh`, todo tiene que dar `OK` **salvo** estas rutas, que todavía dan `FALLA 404` porque sus archivos llegan después:
  - `/admin/clientes` y `/admin/clientes/1`: Task 8.
  - `/api/clientes` y `/api/clientes/1`: Task 7.
  - `/api/reportes/balance`: Task 21.
- `diff` no muestra diferencias y se imprime `Landing sin cambios`.
- PHPUnit da `OK`.

Checklist manual en el navegador (usá las DevTools con vista de iPhone, 390px de ancho):
- [ ] `http://localhost:8080/admin` redirige a `/admin-login?next=%2Fadmin`.
- [ ] Con una contraseña incorrecta aparece "Usuario o contraseña incorrectos."
- [ ] Al sexto intento aparece "Demasiados intentos…". Para destrabar: `"/c/xampp/mysql/bin/mysql.exe" -u root vezza_admin -e "DELETE FROM login_intentos"`.
- [ ] Con los datos correctos entra a `/admin` y se ve el aviso "El panel está funcionando".
- [ ] En el celular se ve la barra inferior con Inicio, Clientes, Procesos, Cobros y Más. "Más" abre el menú y se cierra al tocar afuera.
- [ ] En desktop (900px o más) se ve el sidebar.
- [ ] `/admin/migraciones` dice "La base está al día."
- [ ] "Salir" vuelve al login, y `/admin` otra vez redirige al login.
- [ ] En la consola del navegador no hay errores de CSP.

- [ ] **Step 6: Commit**

```bash
git add admin/includes/layout.php admin/assets/admin.css admin/assets/admin.js admin/login.php admin/logout.php admin/index.php admin/migraciones.php
git commit -m "$(cat <<'EOF'
Agregar login, layout mobile-first y helpers JS del panel

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 7: Clientes y bitácora (backend)

**Files:**
- Create: `admin/includes/repos/clientes.php`, `admin/includes/repos/notas_cliente.php`
- Create: `api/clientes.php`, `api/notas-cliente.php`
- Test: `tests/ClientesTest.php`, `tests/NotasClienteTest.php`

**Interfaces:**
- Consumes: `validate`, `filtros`, `crud_*`, `q_*`, `where_eq`, `sql_where`, `api_resource`.
- Produces:
  - `CLIENTE_ESTADOS`, `clientes_schema(): array`
  - `clientes_list(array $get)`: filtros `estado` y `q`. Cada fila suma `procesos_activos` (int) y `fixs_abiertos` (int).
  - `clientes_get(int)`, `clientes_create(array)`, `clientes_update(int, array)`: devuelven la fila.
  - `clientes_delete(int)`: 409 si el cliente tiene cobros.
  - `notas_cliente_list(array $get)`: `cliente_id` obligatorio; orden de la más nueva a la más vieja.
  - `notas_cliente_get`, `notas_cliente_create` (`cliente_id` y `contenido`), `notas_cliente_update` (solo `contenido`), `notas_cliente_delete`.

- [ ] **Step 1: Escribir los tests que fallan**

`tests/ClientesTest.php`:

```php
<?php
declare(strict_types=1);

final class ClientesTest extends DbTestCase
{
    public function test_crear_y_obtener(): void
    {
        $c = clientes_create(['nombre' => '  Panadería Sol ', 'email' => 'hola@sol.com', 'fecha_inicio' => '2026-01-10']);
        $this->assertSame('Panadería Sol', $c['nombre']);
        $this->assertSame('activo', $c['estado']);
        $this->assertSame('2026-01-10', $c['fecha_inicio']);
        $this->assertSame($c['id'], clientes_get($c['id'])['id']);
    }

    public function test_nombre_obligatorio_y_email_valido(): void
    {
        $campos = $this->errores422(fn() => clientes_create(['email' => 'no-es-mail']));
        $this->assertArrayHasKey('nombre', $campos);
        $this->assertArrayHasKey('email', $campos);
    }

    public function test_estado_invalido(): void
    {
        $this->assertArrayHasKey('estado', $this->errores422(fn() => clientes_create(['nombre' => 'X', 'estado' => 'borrado'])));
    }

    public function test_update_vaciar_campo_lo_deja_null(): void
    {
        $c = clientes_create(['nombre' => 'X', 'email' => 'a@b.com', 'telefono' => '11 5555-5555']);
        $c2 = clientes_update($c['id'], ['email' => '']);
        $this->assertNull($c2['email']);
        $this->assertSame('11 5555-5555', $c2['telefono']);
        $this->assertSame('X', $c2['nombre']);
    }

    public function test_list_filtra_y_busca(): void
    {
        clientes_create(['nombre' => 'Alfa']);
        clientes_create(['nombre' => 'Beta', 'estado' => 'pausado']);
        clientes_create(['nombre' => 'Alfalfa', 'estado' => 'finalizado']);
        $this->assertSame(['Alfa', 'Alfalfa'], array_column(clientes_list(['q' => 'ALFA']), 'nombre'));
        $this->assertSame(['Beta'], array_column(clientes_list(['estado' => 'pausado']), 'nombre'));
        $this->assertSame(['Alfa', 'Beta', 'Alfalfa'], array_column(clientes_list([]), 'nombre'));
        $this->assertSame([], clientes_list(['q' => '%']));
    }

    public function test_list_incluye_contadores(): void
    {
        $c = clientes_create(['nombre' => 'X']);
        crud_insert('procesos', ['cliente_id' => $c['id'], 'titulo' => 'P1', 'estado' => 'en_curso']);
        crud_insert('procesos', ['cliente_id' => $c['id'], 'titulo' => 'P2', 'estado' => 'entregado']);
        crud_insert('fixs', ['cliente_id' => $c['id'], 'titulo' => 'F1', 'fecha_reportado' => '2026-01-01']);
        crud_insert('fixs', ['cliente_id' => $c['id'], 'titulo' => 'F2', 'estado' => 'resuelto', 'fecha_reportado' => '2026-01-01']);
        $fila = clientes_list([])[0];
        $this->assertSame(1, $fila['procesos_activos']);
        $this->assertSame(1, $fila['fixs_abiertos']);
    }

    public function test_borrar_cliente_con_cobros_da_409(): void
    {
        $c = clientes_create(['nombre' => 'X']);
        crud_insert('cobros', ['cliente_id' => $c['id'], 'monto' => '10.00', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-01-01']);
        $this->assertHttp(409, fn() => clientes_delete($c['id']));
        $this->assertTrue(crud_exists('clientes', $c['id']));
    }

    public function test_borrar_cliente_sin_cobros_borra_en_cascada(): void
    {
        $c = clientes_create(['nombre' => 'X']);
        $nota = crud_insert('notas_cliente', ['cliente_id' => $c['id'], 'contenido' => 'hola']);
        $proc = crud_insert('procesos', ['cliente_id' => $c['id'], 'titulo' => 'P']);
        clientes_delete($c['id']);
        $this->assertFalse(crud_exists('clientes', $c['id']));
        $this->assertFalse(crud_exists('notas_cliente', $nota));
        $this->assertFalse(crud_exists('procesos', $proc));
    }

    public function test_get_inexistente_da_404(): void
    {
        $this->assertHttp(404, fn() => clientes_get(999999));
    }
}
```

`tests/NotasClienteTest.php`:

```php
<?php
declare(strict_types=1);

final class NotasClienteTest extends DbTestCase
{
    public function test_list_exige_cliente(): void
    {
        $this->assertArrayHasKey('cliente_id', $this->errores422(fn() => notas_cliente_list([])));
    }

    public function test_crear_y_listar_de_la_mas_nueva_a_la_mas_vieja(): void
    {
        $c = clientes_create(['nombre' => 'X']);
        $otro = clientes_create(['nombre' => 'Y']);
        notas_cliente_create(['cliente_id' => $c['id'], 'contenido' => 'primera']);
        notas_cliente_create(['cliente_id' => $c['id'], 'contenido' => "segunda\ncon salto"]);
        notas_cliente_create(['cliente_id' => $otro['id'], 'contenido' => 'ajena']);
        $this->assertSame(["segunda\ncon salto", 'primera'], array_column(notas_cliente_list(['cliente_id' => (string)$c['id']]), 'contenido'));
    }

    public function test_update_solo_cambia_contenido(): void
    {
        $c = clientes_create(['nombre' => 'X']);
        $otro = clientes_create(['nombre' => 'Y']);
        $n = notas_cliente_create(['cliente_id' => $c['id'], 'contenido' => 'a']);
        $n2 = notas_cliente_update($n['id'], ['contenido' => 'b', 'cliente_id' => $otro['id']]);
        $this->assertSame('b', $n2['contenido']);
        $this->assertSame($c['id'], $n2['cliente_id']);
    }

    public function test_validaciones(): void
    {
        $this->assertArrayHasKey('cliente_id', $this->errores422(fn() => notas_cliente_create(['cliente_id' => 999999, 'contenido' => 'x'])));
        $c = clientes_create(['nombre' => 'X']);
        $this->assertArrayHasKey('contenido', $this->errores422(fn() => notas_cliente_create(['cliente_id' => $c['id'], 'contenido' => '   '])));
    }

    public function test_borrar(): void
    {
        $c = clientes_create(['nombre' => 'X']);
        $n = notas_cliente_create(['cliente_id' => $c['id'], 'contenido' => 'a']);
        notas_cliente_delete($n['id']);
        $this->assertFalse(crud_exists('notas_cliente', $n['id']));
    }
}
```

- [ ] **Step 2: Correr los tests y confirmar que fallan**

Run: `php vendor/bin/phpunit tests/ClientesTest.php tests/NotasClienteTest.php`
Resultado esperado: error `Call to undefined function clientes_create()`.

- [ ] **Step 3: Implementar**

`admin/includes/repos/clientes.php`:

```php
<?php
declare(strict_types=1);

const CLIENTE_ESTADOS = ['activo', 'pausado', 'finalizado'];

function clientes_schema(): array
{
    return [
        'nombre' => ['type' => 'string', 'required' => true],
        'email' => ['type' => 'email'],
        'telefono' => ['type' => 'string', 'max' => 50],
        'rubro' => ['type' => 'string'],
        'estado' => ['type' => 'enum', 'values' => CLIENTE_ESTADOS, 'notnull' => true],
        'fecha_inicio' => ['type' => 'date'],
    ];
}

function clientes_list(array $get): array
{
    $f = filtros($get, [
        'estado' => ['type' => 'enum', 'values' => CLIENTE_ESTADOS],
        'q' => ['type' => 'string', 'max' => 100],
    ]);
    [$partes, $params] = where_eq($f, ['estado' => 'c.estado']);
    if (isset($f['q'])) {
        $partes[] = 'c.nombre LIKE ?';
        $params[] = '%' . addcslashes($f['q'], '%_\\') . '%';
    }
    $sql = "SELECT c.*,
              (SELECT COUNT(*) FROM procesos p WHERE p.cliente_id = c.id AND p.estado <> 'entregado') AS procesos_activos,
              (SELECT COUNT(*) FROM fixs x WHERE x.cliente_id = c.id AND x.estado <> 'resuelto') AS fixs_abiertos
            FROM clientes c" . sql_where($partes) . "
            ORDER BY FIELD(c.estado, 'activo', 'pausado', 'finalizado'), c.nombre, c.id";
    return q_all($sql, $params);
}

function clientes_get(int $id): array
{
    return crud_find('clientes', $id);
}

function clientes_create(array $input): array
{
    return clientes_get(crud_insert('clientes', validate($input, clientes_schema())));
}

function clientes_update(int $id, array $input): array
{
    crud_update('clientes', $id, validate($input, clientes_schema(), true));
    return clientes_get($id);
}

function clientes_delete(int $id): void
{
    crud_find('clientes', $id);
    if ((int)q_val('SELECT COUNT(*) FROM cobros WHERE cliente_id = ?', [$id]) > 0) {
        throw new HttpError(409, 'Este cliente tiene cobros registrados. Marcalo como finalizado en lugar de borrarlo.');
    }
    crud_delete('clientes', $id);
}
```

`admin/includes/repos/notas_cliente.php`:

```php
<?php
declare(strict_types=1);

function notas_cliente_list(array $get): array
{
    $f = filtros($get, ['cliente_id' => ['type' => 'int', 'required' => true]]);
    return q_all('SELECT * FROM notas_cliente WHERE cliente_id = ? ORDER BY created_at DESC, id DESC', [$f['cliente_id']]);
}

function notas_cliente_get(int $id): array
{
    return crud_find('notas_cliente', $id);
}

function notas_cliente_create(array $input): array
{
    $datos = validate($input, [
        'cliente_id' => ['type' => 'fk', 'table' => 'clientes', 'required' => true],
        'contenido' => ['type' => 'text', 'required' => true],
    ]);
    return notas_cliente_get(crud_insert('notas_cliente', $datos));
}

function notas_cliente_update(int $id, array $input): array
{
    crud_update('notas_cliente', $id, validate($input, ['contenido' => ['type' => 'text', 'required' => true]], true));
    return notas_cliente_get($id);
}

function notas_cliente_delete(int $id): void
{
    crud_delete('notas_cliente', $id);
}
```

`api/clientes.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource([
    'list' => 'clientes_list',
    'get' => 'clientes_get',
    'create' => 'clientes_create',
    'update' => 'clientes_update',
    'delete' => 'clientes_delete',
]);
```

`api/notas-cliente.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource([
    'list' => 'notas_cliente_list',
    'get' => 'notas_cliente_get',
    'create' => 'notas_cliente_create',
    'update' => 'notas_cliente_update',
    'delete' => 'notas_cliente_delete',
]);
```

- [ ] **Step 4: Correr los tests y confirmar que pasan**

Run: `php vendor/bin/phpunit`
Resultado esperado: `OK`.

- [ ] **Step 5: Commit**

```bash
git add admin/includes/repos/clientes.php admin/includes/repos/notas_cliente.php api/clientes.php api/notas-cliente.php tests/ClientesTest.php tests/NotasClienteTest.php
git commit -m "$(cat <<'EOF'
Agregar API de clientes y bitácora por cliente

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 8: Clientes y bitácora (interfaz)

**Files:**
- Create: `admin/assets/mod-clientes.js`, `admin/assets/clientes.js`, `admin/assets/cliente.js`
- Create: `admin/clientes.php`, `admin/cliente.php`

**Interfaces:**
- Consumes: `Panel.*` (Task 6) y los endpoints `/api/clientes` y `/api/notas-cliente` (Task 7).
- Produces:
  - Objeto global `Clientes` con:
    - `campos()`
    - `nuevo()`: devuelve una promesa con el cliente creado o `null`.
    - `editar(c)`: devuelve una promesa con el cliente, `{ eliminado: true }` o `null`.
  - En `cliente.js`, la constante `TABS`: array de `{ id, label, render(panel) }` que la Task 22 reemplaza por la lista completa.

- [ ] **Step 1: Crear el módulo y la lista**

`admin/assets/mod-clientes.js`:

```js
'use strict';

const Clientes = {
  campos() {
    return [
      { name: 'nombre', label: 'Nombre', required: true },
      { name: 'email', label: 'Email', type: 'email' },
      { name: 'telefono', label: 'Teléfono', type: 'tel' },
      { name: 'rubro', label: 'Rubro', placeholder: 'Gastronomía, salud, retail…' },
      { name: 'estado', label: 'Estado', type: 'select', options: Panel.opciones(Panel.ETQ.estadoCliente) },
      { name: 'fecha_inicio', label: 'Fecha de inicio', type: 'date', default: Panel.hoy() },
    ];
  },
  async nuevo() {
    const r = await Panel.modalForm({ titulo: 'Nuevo cliente', campos: this.campos(), enviar: (d) => Panel.post('/api/clientes', d) });
    if (r) Panel.clientes(true);
    return r;
  },
  async editar(c) {
    const r = await Panel.modalForm({
      titulo: 'Editar cliente',
      campos: this.campos(),
      valores: c,
      enviar: (d) => Panel.put(`/api/clientes/${c.id}`, d),
      eliminar: () => Panel.del(`/api/clientes/${c.id}`),
    });
    if (r) Panel.clientes(true);
    return r;
  },
};
```

`admin/clientes.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();
layout_start('Clientes', 'clientes', ['mod-clientes.js', 'clientes.js']);
?>
<div class="toolbar">
  <input class="filtro" type="search" id="buscar" placeholder="Buscar por nombre" aria-label="Buscar cliente">
  <select class="filtro" id="filtro-estado" aria-label="Filtrar por estado">
    <option value="">Todos los estados</option>
    <option value="activo">Activos</option>
    <option value="pausado">Pausados</option>
    <option value="finalizado">Finalizados</option>
  </select>
</div>
<div id="lista" class="lista" aria-live="polite"></div>
<?php
layout_end();
```

`admin/assets/clientes.js`:

```js
'use strict';

(() => {
  const { el } = Panel;
  const lista = document.getElementById('lista');
  const buscar = document.getElementById('buscar');
  const estado = document.getElementById('filtro-estado');
  let temporizador;

  Panel.acciones(Panel.boton('+ Nuevo cliente', async () => {
    const c = await Clientes.nuevo();
    if (c) location.href = `/admin/clientes/${c.id}`;
  }, 'primario'));

  function item(c) {
    const contacto = [c.rubro, c.email, c.telefono].filter(Boolean).join(' · ');
    return el('a', { class: 'item', href: `/admin/clientes/${c.id}` },
      el('div', { class: 'item-top' },
        el('span', { class: 'item-titulo', text: c.nombre }),
        Panel.badgeEstado('estadoCliente', c.estado)),
      el('div', { class: 'item-sub', text: contacto || 'Sin datos de contacto' }),
      el('div', { class: 'meta' },
        Panel.badge(`${c.procesos_activos} ${c.procesos_activos === 1 ? 'proceso activo' : 'procesos activos'}`, c.procesos_activos ? 'accent' : ''),
        c.fixs_abiertos ? Panel.badge(`${c.fixs_abiertos} ${c.fixs_abiertos === 1 ? 'fix abierto' : 'fixs abiertos'}`, 'warn') : null));
  }

  async function cargar() {
    try {
      const clientes = await Panel.get('/api/clientes', { q: buscar.value.trim(), estado: estado.value });
      Panel.llenar(lista, clientes.length ? clientes.map(item) : Panel.vacio('No hay clientes con ese filtro.'));
    } catch (err) {
      Panel.manejarError(err);
    }
  }

  buscar.addEventListener('input', () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(cargar, 250);
  });
  estado.addEventListener('change', cargar);
  cargar();
})();
```

- [ ] **Step 2: Crear la ficha del cliente, con la pestaña Bitácora**

`admin/cliente.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();

$id = is_string($_GET['id'] ?? null) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id < 1 || !crud_exists('clientes', $id)) {
    pagina_no_encontrada('Ese cliente no existe.', '/admin/clientes', 'Volver a clientes', 'clientes');
}

layout_start('Cliente', 'clientes', ['mod-clientes.js', 'cliente.js'], ['cliente-id' => $id]);
?>
<section id="ficha" class="card" aria-live="polite"></section>
<section id="pestanas" class="seccion"></section>
<?php
layout_end();
```

`admin/assets/cliente.js`:

```js
'use strict';

(() => {
  const { el } = Panel;
  const id = Number(document.body.dataset.clienteId);
  const ficha = document.getElementById('ficha');

  const dato = (label, valor, href) => el('div', {},
    el('div', { class: 'stat-label', text: label }),
    valor ? (href ? el('a', { href, text: valor }) : el('div', { text: valor })) : el('div', { class: 'item-sub', text: '—' }));

  async function cargarFicha() {
    const c = await Panel.get(`/api/clientes/${id}`);
    document.getElementById('titulo').textContent = c.nombre;
    document.title = `${c.nombre} · VEZZA Admin`;
    Panel.llenar(ficha,
      el('div', { class: 'item-top' },
        Panel.badgeEstado('estadoCliente', c.estado),
        Panel.boton('Editar', async () => {
          const r = await Clientes.editar(c);
          if (r?.eliminado) location.href = '/admin/clientes';
          else if (r) cargarFicha().catch(Panel.manejarError);
        }, 'chico')),
      el('div', { class: 'datos' },
        dato('Email', c.email, c.email ? `mailto:${c.email}` : null),
        dato('Teléfono', c.telefono, c.telefono ? `tel:${c.telefono.replace(/[^\d+]/g, '')}` : null),
        dato('Rubro', c.rubro),
        dato('Cliente desde', c.fecha_inicio ? Panel.fmtFecha(c.fecha_inicio, true) : null)));
  }

  async function renderBitacora(panel) {
    const notas = await Panel.get('/api/notas-cliente', { cliente_id: id });
    const texto = el('textarea', { class: 'filtro', rows: 3, placeholder: 'Escribí una nota…', 'aria-label': 'Nueva nota' });
    const agregar = el('button', { type: 'submit', class: 'btn btn-primario', text: 'Agregar nota' });
    const form = el('form', { class: 'card' }, texto, el('div', { class: 'item-acciones' }, agregar));
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!texto.value.trim()) return;
      agregar.disabled = true;
      try {
        await Panel.post('/api/notas-cliente', { cliente_id: id, contenido: texto.value });
        await renderBitacora(panel);
      } catch (err) {
        Panel.manejarError(err);
      } finally {
        agregar.disabled = false;
      }
    });
    const items = notas.map((n) => el('article', { class: 'item' },
      el('div', { class: 'item-top' },
        el('span', { class: 'item-sub', text: Panel.fmtFechaHora(n.created_at) }),
        Panel.boton('Editar', () => editarNota(n, panel), 'chico')),
      el('p', { class: 'nota-texto', text: n.contenido })));
    Panel.llenar(panel, form, el('div', { class: 'lista seccion' }, items.length ? items : Panel.vacio('Todavía no hay notas.')));
  }

  async function editarNota(n, panel) {
    const r = await Panel.modalForm({
      titulo: 'Editar nota',
      campos: [{ name: 'contenido', label: 'Nota', type: 'textarea', required: true }],
      valores: n,
      enviar: (d) => Panel.put(`/api/notas-cliente/${n.id}`, d),
      eliminar: () => Panel.del(`/api/notas-cliente/${n.id}`),
    });
    if (r) renderBitacora(panel).catch(Panel.manejarError);
  }

  const TABS = [
    { id: 'bitacora', label: 'Bitácora', render: renderBitacora },
  ];

  cargarFicha()
    .then(() => Panel.tabs(document.getElementById('pestanas'), TABS))
    .catch(Panel.manejarError);
})();
```

- [ ] **Step 3: Verificar en el navegador**

Checklist en `http://localhost:8080/admin/clientes`, en vista de celular (390px) y en desktop:
- [ ] "+ Nuevo cliente": si guardás sin nombre, el campo aparece en rojo con "Es obligatorio". Si ponés un email inválido, el error aparece bajo ese campo.
- [ ] Al crear el cliente lleva a su ficha, que muestra estado, email (link `mailto:`), teléfono (link `tel:`), rubro y fecha.
- [ ] En "Editar" podés vaciar el email y guardar: la ficha muestra "—".
- [ ] En la bitácora, al agregar una nota con saltos de línea, se ve arriba de todo y conserva los saltos. Editar y Eliminar funcionan; Eliminar pide confirmación.
- [ ] Escribí `<b>hola</b>` como nombre de un cliente: se ve el texto literal, sin negrita.
- [ ] En la lista, el buscador filtra mientras escribís y el filtro de estado funciona.
- [ ] `/admin/clientes/999999` muestra "Ese cliente no existe." con status 404.
- [ ] Para probar la sesión vencida: borrá la cookie `vezza_admin` en DevTools y tocá "Editar" → Guardar. Te tiene que llevar a `/admin-login?next=/admin/clientes/…`.

- [ ] **Step 4: Commit**

```bash
git add admin/clientes.php admin/cliente.php admin/assets/mod-clientes.js admin/assets/clientes.js admin/assets/cliente.js
git commit -m "$(cat <<'EOF'
Agregar pantallas de clientes y bitácora

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 9: Procesos y subtareas (backend)

**Files:**
- Create: `admin/includes/repos/procesos.php`, `admin/includes/repos/subtareas.php`
- Create: `api/procesos.php`, `api/subtareas.php`
- Test: `tests/ProcesosTest.php`, `tests/SubtareasTest.php`

**Interfaces:**
- Consumes: helpers de la Task 3 (`reordenar`, `siguiente_orden`, `tx`, …) y `clientes_create` (en los tests).
- Produces:
  - `PROCESO_ESTADOS`, `PRIORIDADES`, `procesos_schema(bool $alta)`
  - `procesos_list(array $get)`: filtros `cliente_id` y `estado`. Cada fila trae `cliente_nombre`, `subtareas_total` y `subtareas_hechas`.
  - `procesos_get`, `procesos_create`, `procesos_delete`
  - `procesos_update(int, array)`:
    - acepta `antes_de: int|null` para reordenar;
    - si cambia `estado` sin `antes_de`, el proceso va al final de la columna nueva;
    - `cliente_id` no se puede cambiar.
  - `validar_proceso_de_cliente(?int $procesoId, ?int $clienteId): void`: si no coinciden, lanza 422 en `proceso_id`. Lo usan cobros y fixs.
  - `subtareas_list(array $get)`: `proceso_id` obligatorio.
  - `subtareas_get`, `subtareas_create` (`proceso_id`, `titulo`), `subtareas_update` (`titulo`, `completada`, `antes_de`), `subtareas_delete`.

- [ ] **Step 1: Escribir los tests que fallan**

`tests/ProcesosTest.php`:

```php
<?php
declare(strict_types=1);

final class ProcesosTest extends DbTestCase
{
    private function cliente(string $nombre = 'Acme'): int
    {
        return clientes_create(['nombre' => $nombre])['id'];
    }

    private function titulos(string $estado): array
    {
        return array_column(procesos_list(['estado' => $estado]), 'titulo');
    }

    public function test_crear_con_valores_por_defecto(): void
    {
        $p = procesos_create(['cliente_id' => $this->cliente(), 'titulo' => 'Landing page']);
        $this->assertSame('por_hacer', $p['estado']);
        $this->assertSame('media', $p['prioridad']);
        $this->assertSame('Acme', $p['cliente_nombre']);
        $this->assertSame(0, $p['subtareas_total']);
        $this->assertSame(0, $p['orden']);
    }

    public function test_orden_secuencial_por_columna(): void
    {
        $c = $this->cliente();
        procesos_create(['cliente_id' => $c, 'titulo' => 'A']);
        $b = procesos_create(['cliente_id' => $c, 'titulo' => 'B']);
        $x = procesos_create(['cliente_id' => $c, 'titulo' => 'X', 'estado' => 'en_curso']);
        $this->assertSame(1, $b['orden']);
        $this->assertSame(0, $x['orden']);
    }

    public function test_validaciones(): void
    {
        $campos = $this->errores422(fn() => procesos_create(['titulo' => '']));
        $this->assertArrayHasKey('cliente_id', $campos);
        $this->assertArrayHasKey('titulo', $campos);
        $c = $this->cliente();
        $campos = $this->errores422(fn() => procesos_create([
            'cliente_id' => $c, 'titulo' => 'X', 'fecha_inicio' => '2026-05-10', 'fecha_entrega_estimada' => '2026-05-01',
        ]));
        $this->assertArrayHasKey('fecha_entrega_estimada', $campos);
    }

    public function test_update_parcial_valida_fechas_contra_lo_guardado_y_no_cambia_cliente(): void
    {
        $c = $this->cliente();
        $otro = $this->cliente('Otro');
        $p = procesos_create(['cliente_id' => $c, 'titulo' => 'X', 'fecha_entrega_estimada' => '2026-05-01']);
        $this->assertArrayHasKey('fecha_entrega_estimada', $this->errores422(fn() => procesos_update($p['id'], ['fecha_inicio' => '2026-06-01'])));
        $p2 = procesos_update($p['id'], ['prioridad' => 'alta', 'cliente_id' => $otro]);
        $this->assertSame('alta', $p2['prioridad']);
        $this->assertSame('X', $p2['titulo']);
        $this->assertSame($c, $p2['cliente_id']);
    }

    public function test_list_filtra_y_ordena_por_estado_y_orden(): void
    {
        $a = $this->cliente('A');
        $b = $this->cliente('B');
        procesos_create(['cliente_id' => $a, 'titulo' => 'A1', 'estado' => 'entregado']);
        procesos_create(['cliente_id' => $a, 'titulo' => 'A2']);
        procesos_create(['cliente_id' => $b, 'titulo' => 'B1', 'estado' => 'en_curso']);
        $this->assertSame(['A2', 'B1', 'A1'], array_column(procesos_list([]), 'titulo'));
        $this->assertSame(['A2', 'A1'], array_column(procesos_list(['cliente_id' => (string)$a]), 'titulo'));
        $this->assertSame(['B1'], $this->titulos('en_curso'));
    }

    public function test_cambiar_estado_sin_antes_de_va_al_final_de_la_columna(): void
    {
        $c = $this->cliente();
        procesos_create(['cliente_id' => $c, 'titulo' => 'Y', 'estado' => 'en_curso']);
        $x = procesos_create(['cliente_id' => $c, 'titulo' => 'X']);
        procesos_update($x['id'], ['estado' => 'en_curso']);
        $this->assertSame(['Y', 'X'], $this->titulos('en_curso'));
    }

    public function test_reordenar_con_antes_de_ignora_tarjetas_de_otros_clientes(): void
    {
        $a = $this->cliente('A');
        $b = $this->cliente('B');
        $a1 = procesos_create(['cliente_id' => $a, 'titulo' => 'A1']);
        procesos_create(['cliente_id' => $b, 'titulo' => 'B1']);
        $a2 = procesos_create(['cliente_id' => $a, 'titulo' => 'A2']);

        // Kanban filtrado por A muestra [A1, A2]; se arrastra A2 arriba de A1.
        procesos_update($a2['id'], ['estado' => 'por_hacer', 'antes_de' => $a1['id']]);
        $this->assertSame(['A2', 'A1', 'B1'], $this->titulos('por_hacer'));

        // Se suelta A1 al final de la columna visible (antes_de = null).
        procesos_update($a1['id'], ['antes_de' => null]);
        $this->assertSame(['A2', 'B1', 'A1'], $this->titulos('por_hacer'));
    }

    public function test_mover_a_otra_columna_antes_de_una_tarjeta(): void
    {
        $c = $this->cliente();
        $y = procesos_create(['cliente_id' => $c, 'titulo' => 'Y', 'estado' => 'en_revision']);
        $x = procesos_create(['cliente_id' => $c, 'titulo' => 'X']);
        procesos_update($x['id'], ['estado' => 'en_revision', 'antes_de' => $y['id']]);
        $this->assertSame(['X', 'Y'], $this->titulos('en_revision'));
        $this->assertSame([], $this->titulos('por_hacer'));
    }

    public function test_contadores_de_subtareas_y_borrado_en_cascada(): void
    {
        $p = procesos_create(['cliente_id' => $this->cliente(), 'titulo' => 'X']);
        $s1 = subtareas_create(['proceso_id' => $p['id'], 'titulo' => 'a']);
        subtareas_create(['proceso_id' => $p['id'], 'titulo' => 'b']);
        subtareas_update($s1['id'], ['completada' => true]);
        $p2 = procesos_get($p['id']);
        $this->assertSame(2, $p2['subtareas_total']);
        $this->assertSame(1, $p2['subtareas_hechas']);
        procesos_delete($p['id']);
        $this->assertFalse(crud_exists('subtareas', $s1['id']));
    }

    public function test_validar_proceso_de_cliente(): void
    {
        $a = $this->cliente('A');
        $b = $this->cliente('B');
        $p = procesos_create(['cliente_id' => $a, 'titulo' => 'X']);
        validar_proceso_de_cliente(null, $b);
        validar_proceso_de_cliente($p['id'], $a);
        $this->assertArrayHasKey('proceso_id', $this->errores422(fn() => validar_proceso_de_cliente($p['id'], $b)));
    }
}
```

`tests/SubtareasTest.php`:

```php
<?php
declare(strict_types=1);

final class SubtareasTest extends DbTestCase
{
    private function proceso(): int
    {
        $c = clientes_create(['nombre' => 'X'])['id'];
        return procesos_create(['cliente_id' => $c, 'titulo' => 'P'])['id'];
    }

    public function test_list_exige_proceso(): void
    {
        $this->assertArrayHasKey('proceso_id', $this->errores422(fn() => subtareas_list([])));
    }

    public function test_crear_tildar_y_ordenar(): void
    {
        $p = $this->proceso();
        $a = subtareas_create(['proceso_id' => $p, 'titulo' => 'Diseño']);
        $b = subtareas_create(['proceso_id' => $p, 'titulo' => 'Maquetado']);
        $c = subtareas_create(['proceso_id' => $p, 'titulo' => 'Deploy']);
        $this->assertSame(0, $a['completada']);
        $this->assertSame(1, subtareas_update($a['id'], ['completada' => true])['completada']);
        subtareas_update($c['id'], ['antes_de' => $a['id']]);
        $this->assertSame(['Deploy', 'Diseño', 'Maquetado'], array_column(subtareas_list(['proceso_id' => (string)$p]), 'titulo'));
        $this->assertSame('Deploy', subtareas_get($c['id'])['titulo']);
        subtareas_update($b['id'], ['titulo' => 'Maquetado responsive']);
        $this->assertSame('Maquetado responsive', subtareas_get($b['id'])['titulo']);
    }

    public function test_validaciones(): void
    {
        $this->assertArrayHasKey('proceso_id', $this->errores422(fn() => subtareas_create(['proceso_id' => 999999, 'titulo' => 'x'])));
        $s = subtareas_create(['proceso_id' => $this->proceso(), 'titulo' => 'x']);
        $this->assertArrayHasKey('completada', $this->errores422(fn() => subtareas_update($s['id'], ['completada' => 'tal vez'])));
        $this->assertArrayHasKey('titulo', $this->errores422(fn() => subtareas_update($s['id'], ['titulo' => ''])));
    }

    public function test_borrar(): void
    {
        $s = subtareas_create(['proceso_id' => $this->proceso(), 'titulo' => 'x']);
        subtareas_delete($s['id']);
        $this->assertFalse(crud_exists('subtareas', $s['id']));
    }
}
```

- [ ] **Step 2: Correr los tests y confirmar que fallan**

Run: `php vendor/bin/phpunit tests/ProcesosTest.php tests/SubtareasTest.php`
Resultado esperado: error `Call to undefined function procesos_create()`.

- [ ] **Step 3: Implementar**

`admin/includes/repos/procesos.php`:

```php
<?php
declare(strict_types=1);

const PROCESO_ESTADOS = ['por_hacer', 'en_curso', 'en_revision', 'entregado'];
const PRIORIDADES = ['baja', 'media', 'alta'];

const PROCESOS_SELECT = "SELECT p.*, c.nombre AS cliente_nombre,
        (SELECT COUNT(*) FROM subtareas s WHERE s.proceso_id = p.id) AS subtareas_total,
        (SELECT COUNT(*) FROM subtareas s WHERE s.proceso_id = p.id AND s.completada = 1) AS subtareas_hechas
    FROM procesos p JOIN clientes c ON c.id = p.cliente_id";

function procesos_schema(bool $alta): array
{
    $schema = [
        'titulo' => ['type' => 'string', 'required' => true],
        'descripcion' => ['type' => 'text'],
        'estado' => ['type' => 'enum', 'values' => PROCESO_ESTADOS, 'notnull' => true],
        'prioridad' => ['type' => 'enum', 'values' => PRIORIDADES, 'notnull' => true],
        'fecha_inicio' => ['type' => 'date'],
        'fecha_entrega_estimada' => ['type' => 'date'],
    ];
    if ($alta) {
        return ['cliente_id' => ['type' => 'fk', 'table' => 'clientes', 'required' => true]] + $schema;
    }
    $schema['antes_de'] = ['type' => 'int', 'min' => 1];
    return $schema;
}

function procesos_list(array $get): array
{
    $f = filtros($get, [
        'cliente_id' => ['type' => 'int'],
        'estado' => ['type' => 'enum', 'values' => PROCESO_ESTADOS],
    ]);
    [$partes, $params] = where_eq($f, ['cliente_id' => 'p.cliente_id', 'estado' => 'p.estado']);
    $sql = PROCESOS_SELECT . sql_where($partes)
        . " ORDER BY FIELD(p.estado, 'por_hacer', 'en_curso', 'en_revision', 'entregado'), p.orden, p.id";
    return q_all($sql, $params);
}

function procesos_get(int $id): array
{
    $p = q_one(PROCESOS_SELECT . ' WHERE p.id = ?', [$id]);
    if ($p === null) {
        throw new HttpError(404, 'No encontrado');
    }
    return $p;
}

function procesos_validar_fechas(?string $inicio, ?string $entrega): void
{
    if ($inicio !== null && $entrega !== null && $entrega < $inicio) {
        throw new HttpError(422, 'Revisá los datos marcados', ['fecha_entrega_estimada' => 'No puede ser anterior al inicio']);
    }
}

function procesos_create(array $input): array
{
    $datos = validate($input, procesos_schema(true));
    procesos_validar_fechas($datos['fecha_inicio'] ?? null, $datos['fecha_entrega_estimada'] ?? null);
    $datos['orden'] = siguiente_orden('procesos', 'estado', $datos['estado'] ?? 'por_hacer');
    return procesos_get(crud_insert('procesos', $datos));
}

function procesos_update(int $id, array $input): array
{
    $datos = validate($input, procesos_schema(false), true);
    $actual = crud_find('procesos', $id);
    $mover = array_key_exists('antes_de', $datos);
    $antesDe = $datos['antes_de'] ?? null;
    unset($datos['antes_de']);
    procesos_validar_fechas(
        array_key_exists('fecha_inicio', $datos) ? $datos['fecha_inicio'] : $actual['fecha_inicio'],
        array_key_exists('fecha_entrega_estimada', $datos) ? $datos['fecha_entrega_estimada'] : $actual['fecha_entrega_estimada'],
    );
    $estado = $datos['estado'] ?? $actual['estado'];
    tx(function () use ($id, $datos, $mover, $antesDe, $estado, $actual): void {
        crud_update('procesos', $id, $datos);
        if ($mover || $estado !== $actual['estado']) {
            reordenar('procesos', $id, 'estado', $estado, $antesDe);
        }
    });
    return procesos_get($id);
}

function procesos_delete(int $id): void
{
    crud_delete('procesos', $id);
}

function validar_proceso_de_cliente(?int $procesoId, ?int $clienteId): void
{
    if ($procesoId === null) {
        return;
    }
    $duenio = q_val('SELECT cliente_id FROM procesos WHERE id = ?', [$procesoId]);
    if ($duenio === null || (int)$duenio !== (int)$clienteId) {
        throw new HttpError(422, 'Revisá los datos marcados', ['proceso_id' => 'Ese proceso no es de este cliente']);
    }
}
```

`admin/includes/repos/subtareas.php`:

```php
<?php
declare(strict_types=1);

function subtareas_list(array $get): array
{
    $f = filtros($get, ['proceso_id' => ['type' => 'int', 'required' => true]]);
    return q_all('SELECT * FROM subtareas WHERE proceso_id = ? ORDER BY orden, id', [$f['proceso_id']]);
}

function subtareas_get(int $id): array
{
    return crud_find('subtareas', $id);
}

function subtareas_create(array $input): array
{
    $datos = validate($input, [
        'proceso_id' => ['type' => 'fk', 'table' => 'procesos', 'required' => true],
        'titulo' => ['type' => 'string', 'required' => true],
    ]);
    $datos['orden'] = siguiente_orden('subtareas', 'proceso_id', $datos['proceso_id']);
    return subtareas_get(crud_insert('subtareas', $datos));
}

function subtareas_update(int $id, array $input): array
{
    $datos = validate($input, [
        'titulo' => ['type' => 'string', 'required' => true],
        'completada' => ['type' => 'bool', 'notnull' => true],
        'antes_de' => ['type' => 'int', 'min' => 1],
    ], true);
    $actual = crud_find('subtareas', $id);
    $mover = array_key_exists('antes_de', $datos);
    $antesDe = $datos['antes_de'] ?? null;
    unset($datos['antes_de']);
    tx(function () use ($id, $datos, $mover, $antesDe, $actual): void {
        crud_update('subtareas', $id, $datos);
        if ($mover) {
            reordenar('subtareas', $id, 'proceso_id', (int)$actual['proceso_id'], $antesDe);
        }
    });
    return subtareas_get($id);
}

function subtareas_delete(int $id): void
{
    crud_delete('subtareas', $id);
}
```

`api/procesos.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource([
    'list' => 'procesos_list',
    'get' => 'procesos_get',
    'create' => 'procesos_create',
    'update' => 'procesos_update',
    'delete' => 'procesos_delete',
]);
```

`api/subtareas.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource([
    'list' => 'subtareas_list',
    'get' => 'subtareas_get',
    'create' => 'subtareas_create',
    'update' => 'subtareas_update',
    'delete' => 'subtareas_delete',
]);
```

- [ ] **Step 4: Correr los tests y confirmar que pasan**

Run: `php vendor/bin/phpunit`
Resultado esperado: `OK`.

- [ ] **Step 5: Commit**

```bash
git add admin/includes/repos/procesos.php admin/includes/repos/subtareas.php api/procesos.php api/subtareas.php tests/ProcesosTest.php tests/SubtareasTest.php
git commit -m "$(cat <<'EOF'
Agregar API de procesos con orden de kanban y subtareas

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 10: Procesos (interfaz: kanban, lista y detalle con checklist)

**Files:**
- Create: `admin/assets/vendor/sortable.min.js` (descargado)
- Create: `admin/assets/kanban.js`, `admin/assets/mod-procesos.js`, `admin/assets/procesos.js`, `admin/assets/proceso.js`
- Create: `admin/procesos.php`, `admin/proceso.php`

**Interfaces:**
- Consumes: `Panel.*`, `/api/procesos` y `/api/subtareas`.
- Produces:
  - Función global `kanbanBoard(contenedor, { columnas: [[estado, titulo]], items, tarjeta(item) ⇒ nodo | nodos, alMover(id, estado, antesDe) })`.
  - Objeto global `Procesos` con:
    - `COLUMNAS`, `campos(clientes)`
    - `nuevo(valores)` y `editar(p)`: promesas.
    - `selectorEstado(p, alCambiar)`: devuelve un `<select>`.
    - `resumen(p)`: fila de badges.
    - `item(p, recargar)`: tarjeta para listas.

- [ ] **Step 1: Descargar SortableJS**

```bash
mkdir -p admin/assets/vendor
curl -fsSL -o admin/assets/vendor/sortable.min.js https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js
head -c 120 admin/assets/vendor/sortable.min.js
```

Resultado esperado: la cabecera muestra `/*! Sortable 1.15.6 ...`. Si esa versión no existe, usá la última `1.15.x` que liste https://www.jsdelivr.com/package/npm/sortablejs.

- [ ] **Step 2: Crear el tablero genérico y el módulo de procesos**

`admin/assets/kanban.js`:

```js
'use strict';

function kanbanBoard(contenedor, { columnas, items, tarjeta, alMover }) {
  const { el } = Panel;
  const tablero = el('div', { class: 'kanban' });
  for (const [estado, titulo] of columnas) {
    const suyos = items.filter((i) => i.estado === estado);
    const lista = el('ul', { class: 'kanban-lista', dataset: { estado } },
      suyos.map((i) => el('li', { class: 'kanban-card', dataset: { id: String(i.id) } }, tarjeta(i))));
    tablero.append(el('section', { class: 'kanban-col', 'aria-label': titulo },
      el('h3', {}, el('span', { text: titulo }), Panel.badge(String(suyos.length))),
      lista));
    Sortable.create(lista, {
      group: 'kanban',
      animation: 150,
      delay: 180,
      delayOnTouchOnly: true,
      filter: 'select, a, button, input',
      preventOnFilter: false,
      onEnd: (evt) => {
        if (evt.from === evt.to && evt.oldIndex === evt.newIndex) return;
        const siguiente = evt.item.nextElementSibling;
        alMover(Number(evt.item.dataset.id), evt.to.dataset.estado, siguiente ? Number(siguiente.dataset.id) : null);
      },
    });
  }
  Panel.llenar(contenedor, tablero);
}
```

`admin/assets/mod-procesos.js`:

```js
'use strict';

const Procesos = {
  COLUMNAS: Object.entries(Panel.ETQ.estadoProceso),

  campos(clientes) {
    return [
      { name: 'cliente_id', label: 'Cliente', type: 'select', required: true, vacio: 'Elegí un cliente', options: Panel.opcionesClientes(clientes) },
      { name: 'titulo', label: 'Título', required: true, placeholder: 'Landing page' },
      { name: 'descripcion', label: 'Descripción', type: 'textarea' },
      { name: 'estado', label: 'Estado', type: 'select', options: Panel.opciones(Panel.ETQ.estadoProceso) },
      { name: 'prioridad', label: 'Prioridad', type: 'select', options: Panel.opciones(Panel.ETQ.prioridad), default: 'media' },
      { name: 'fecha_inicio', label: 'Fecha de inicio', type: 'date', default: Panel.hoy() },
      { name: 'fecha_entrega_estimada', label: 'Entrega estimada', type: 'date' },
    ];
  },

  async nuevo(valores = {}) {
    const clientes = await Panel.clientes();
    return Panel.modalForm({ titulo: 'Nuevo proceso', campos: this.campos(clientes), valores, enviar: (d) => Panel.post('/api/procesos', d) });
  },

  async editar(p) {
    const clientes = await Panel.clientes();
    const campos = this.campos(clientes).map((c) => (c.name === 'cliente_id' ? { ...c, soloLectura: true } : c));
    return Panel.modalForm({
      titulo: 'Editar proceso', campos, valores: p,
      enviar: (d) => Panel.put(`/api/procesos/${p.id}`, d),
      eliminar: () => Panel.del(`/api/procesos/${p.id}`),
    });
  },

  selectorEstado(p, alCambiar) {
    const s = Panel.el('select', { class: 'filtro', 'aria-label': `Estado de ${p.titulo}` },
      this.COLUMNAS.map(([v, l]) => Panel.el('option', { value: v, text: l })));
    s.value = p.estado;
    s.addEventListener('change', async () => {
      try {
        await Panel.put(`/api/procesos/${p.id}`, { estado: s.value });
        alCambiar();
      } catch (err) {
        s.value = p.estado;
        Panel.manejarError(err);
      }
    });
    return s;
  },

  resumen(p) {
    const abierto = p.estado !== 'entregado';
    const dias = p.fecha_entrega_estimada && abierto ? Panel.diasHasta(p.fecha_entrega_estimada) : null;
    let variante = '';
    if (dias !== null && dias < 0) variante = 'danger';
    else if (dias !== null && dias <= 7) variante = 'warn';
    return Panel.el('div', { class: 'meta' },
      p.prioridad !== 'media' ? Panel.badgeEstado('prioridad', p.prioridad) : null,
      p.subtareas_total ? Panel.badge(`${p.subtareas_hechas}/${p.subtareas_total}`, p.subtareas_hechas === p.subtareas_total ? 'ok' : '') : null,
      p.fecha_entrega_estimada ? Panel.badge(`Entrega ${Panel.fmtFecha(p.fecha_entrega_estimada)}`, variante) : null);
  },

  item(p, recargar) {
    const { el } = Panel;
    return el('div', { class: 'item' },
      el('div', { class: 'item-top' },
        el('a', { class: 'item-titulo', href: `/admin/procesos/${p.id}`, text: p.titulo }),
        Panel.badgeEstado('estadoProceso', p.estado)),
      el('div', { class: 'item-sub', text: p.cliente_nombre }),
      this.resumen(p),
      el('div', { class: 'item-acciones' }, this.selectorEstado(p, recargar)));
  },
};
```

- [ ] **Step 3: Crear la página de procesos**

`admin/procesos.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();
layout_start('Procesos', 'procesos', ['vendor/sortable.min.js', 'kanban.js', 'mod-procesos.js', 'procesos.js']);
?>
<div class="toolbar">
  <select class="filtro" id="filtro-cliente" aria-label="Filtrar por cliente">
    <option value="">Todos los clientes</option>
  </select>
  <select class="filtro" id="filtro-estado" aria-label="Filtrar por estado">
    <option value="">Todos los estados</option>
    <option value="por_hacer">Por hacer</option>
    <option value="en_curso">En curso</option>
    <option value="en_revision">En revisión</option>
    <option value="entregado">Entregado</option>
  </select>
  <button type="button" class="btn" id="vista-kanban" aria-pressed="true">Tablero</button>
  <button type="button" class="btn" id="vista-lista" aria-pressed="false">Lista</button>
</div>
<div id="contenido" aria-live="polite"></div>
<?php
layout_end();
```

`admin/assets/procesos.js`:

```js
'use strict';

(() => {
  const { el } = Panel;
  const contenido = document.getElementById('contenido');
  const fCliente = document.getElementById('filtro-cliente');
  const fEstado = document.getElementById('filtro-estado');
  const bKanban = document.getElementById('vista-kanban');
  const bLista = document.getElementById('vista-lista');
  const params = new URLSearchParams(location.search);
  let vista = params.get('vista') === 'lista' ? 'lista' : 'kanban';

  Panel.acciones(Panel.boton('+ Nuevo proceso', async () => {
    if (await Procesos.nuevo({ cliente_id: fCliente.value || undefined })) cargar();
  }, 'primario'));

  function marcarVista() {
    bKanban.setAttribute('aria-pressed', String(vista === 'kanban'));
    bLista.setAttribute('aria-pressed', String(vista === 'lista'));
  }

  function sincronizarUrl() {
    const q = Panel.qs({ cliente_id: fCliente.value, estado: fEstado.value, vista: vista === 'lista' ? 'lista' : '' });
    history.replaceState(null, '', location.pathname + q);
  }

  async function mover(id, estado, antesDe) {
    try {
      await Panel.put(`/api/procesos/${id}`, { estado, antes_de: antesDe });
    } catch (err) {
      Panel.manejarError(err);
    }
    cargar();
  }

  async function cargar() {
    sincronizarUrl();
    try {
      const procesos = await Panel.get('/api/procesos', { cliente_id: fCliente.value, estado: fEstado.value });
      if (vista === 'kanban') {
        kanbanBoard(contenido, {
          columnas: Procesos.COLUMNAS.filter(([k]) => !fEstado.value || k === fEstado.value),
          items: procesos,
          tarjeta: (p) => [
            el('a', { class: 'item-titulo', href: `/admin/procesos/${p.id}`, text: p.titulo }),
            el('div', { class: 'item-sub', text: p.cliente_nombre }),
            Procesos.resumen(p),
            Procesos.selectorEstado(p, cargar),
          ],
          alMover: mover,
        });
      } else {
        Panel.llenar(contenido, procesos.length
          ? el('div', { class: 'lista' }, procesos.map((p) => Procesos.item(p, cargar)))
          : Panel.vacio('No hay procesos con ese filtro.'));
      }
    } catch (err) {
      Panel.manejarError(err);
    }
  }

  async function iniciar() {
    const clientes = await Panel.clientes();
    fCliente.append(...clientes.map((c) => el('option', { value: String(c.id), text: c.nombre })));
    fCliente.value = params.get('cliente_id') || '';
    fEstado.value = params.get('estado') || '';
    marcarVista();
    await cargar();
  }

  fCliente.addEventListener('change', cargar);
  fEstado.addEventListener('change', cargar);
  bKanban.addEventListener('click', () => { vista = 'kanban'; marcarVista(); cargar(); });
  bLista.addEventListener('click', () => { vista = 'lista'; marcarVista(); cargar(); });
  iniciar().catch(Panel.manejarError);
})();
```

- [ ] **Step 4: Crear el detalle del proceso con el checklist**

`admin/proceso.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();

$id = is_string($_GET['id'] ?? null) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id < 1 || !crud_exists('procesos', $id)) {
    pagina_no_encontrada('Ese proceso no existe.', '/admin/procesos', 'Volver a procesos', 'procesos');
}

layout_start('Proceso', 'procesos', ['vendor/sortable.min.js', 'mod-procesos.js', 'proceso.js'], ['proceso-id' => $id]);
?>
<section id="ficha" class="card" aria-live="polite"></section>
<section class="seccion">
  <h2>Subtareas</h2>
  <div id="checklist"></div>
</section>
<?php
layout_end();
```

`admin/assets/proceso.js`:

```js
'use strict';

(() => {
  const { el } = Panel;
  const id = Number(document.body.dataset.procesoId);
  const ficha = document.getElementById('ficha');
  const checklist = document.getElementById('checklist');

  const dato = (label, valor) => el('div', {}, el('div', { class: 'stat-label', text: label }), el('div', { text: valor || '—' }));
  const recargarFicha = () => cargarFicha().catch(Panel.manejarError);
  const recargarSubtareas = () => cargarSubtareas().catch(Panel.manejarError);

  async function cargarFicha() {
    const p = await Panel.get(`/api/procesos/${id}`);
    document.getElementById('titulo').textContent = p.titulo;
    document.title = `${p.titulo} · VEZZA Admin`;
    Panel.llenar(ficha,
      el('div', { class: 'item-top' },
        el('a', { href: `/admin/clientes/${p.cliente_id}`, text: p.cliente_nombre }),
        Panel.boton('Editar', async () => {
          const r = await Procesos.editar(p);
          if (r?.eliminado) location.href = '/admin/procesos';
          else if (r) recargarFicha();
        }, 'chico')),
      p.descripcion ? el('p', { class: 'nota-texto', text: p.descripcion }) : null,
      Procesos.resumen(p),
      el('div', { class: 'item-acciones' }, Procesos.selectorEstado(p, recargarFicha)),
      el('div', { class: 'datos' },
        dato('Inicio', p.fecha_inicio && Panel.fmtFecha(p.fecha_inicio, true)),
        dato('Entrega estimada', p.fecha_entrega_estimada && Panel.fmtFecha(p.fecha_entrega_estimada, true))));
  }

  function itemSubtarea(s) {
    const check = el('input', { type: 'checkbox', checked: Boolean(s.completada), 'aria-label': `Completar "${s.titulo}"` });
    check.addEventListener('change', async () => {
      try {
        await Panel.put(`/api/subtareas/${s.id}`, { completada: check.checked });
        recargarSubtareas();
        recargarFicha();
      } catch (err) {
        check.checked = !check.checked;
        Panel.manejarError(err);
      }
    });
    return el('li', { class: `check-item${s.completada ? ' hecha' : ''}`, dataset: { id: String(s.id) } },
      el('span', { class: 'asa', 'aria-hidden': 'true', text: '⋮⋮' }),
      check,
      el('span', { class: 'texto item-titulo', text: s.titulo }),
      Panel.boton('Editar', () => editarSubtarea(s), 'chico'));
  }

  function formNueva() {
    const input = el('input', { class: 'filtro', placeholder: 'Nueva subtarea', 'aria-label': 'Nueva subtarea', maxlength: 255 });
    const form = el('form', { class: 'toolbar' }, input, el('button', { type: 'submit', class: 'btn btn-primario', text: 'Agregar' }));
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const titulo = input.value.trim();
      if (!titulo) return;
      try {
        await Panel.post('/api/subtareas', { proceso_id: id, titulo });
        await cargarSubtareas();
        checklist.querySelector('input.filtro')?.focus();
        recargarFicha();
      } catch (err) {
        Panel.manejarError(err);
      }
    });
    return form;
  }

  async function editarSubtarea(s) {
    const r = await Panel.modalForm({
      titulo: 'Editar subtarea',
      campos: [{ name: 'titulo', label: 'Título', required: true }],
      valores: s,
      enviar: (d) => Panel.put(`/api/subtareas/${s.id}`, d),
      eliminar: () => Panel.del(`/api/subtareas/${s.id}`),
    });
    if (r) { recargarSubtareas(); recargarFicha(); }
  }

  async function cargarSubtareas() {
    const subtareas = await Panel.get('/api/subtareas', { proceso_id: id });
    const hechas = subtareas.filter((s) => s.completada).length;
    const barra = el('span');
    barra.style.width = subtareas.length ? `${Math.round((hechas / subtareas.length) * 100)}%` : '0%';
    const ul = el('ul', { class: 'checklist' }, subtareas.map(itemSubtarea));
    Panel.llenar(checklist,
      formNueva(),
      el('div', { class: 'item-sub', text: `${hechas} de ${subtareas.length} completas` }),
      el('div', { class: 'progreso' }, barra),
      subtareas.length ? ul : Panel.vacio('Sumá la primera subtarea para armar el checklist.'));
    Sortable.create(ul, {
      handle: '.asa',
      animation: 150,
      onEnd: async (evt) => {
        if (evt.oldIndex === evt.newIndex) return;
        const siguiente = evt.item.nextElementSibling;
        try {
          await Panel.put(`/api/subtareas/${evt.item.dataset.id}`, { antes_de: siguiente ? Number(siguiente.dataset.id) : null });
        } catch (err) {
          Panel.manejarError(err);
        }
        recargarSubtareas();
      },
    });
  }

  Promise.all([cargarFicha(), cargarSubtareas()]).catch(Panel.manejarError);
})();
```

- [ ] **Step 5: Verificar en el navegador**

Checklist en `/admin/procesos` (con al menos 2 clientes y 4 procesos cargados):
- [ ] En desktop se ven 4 columnas con contadores. En el celular (390px) hay scroll horizontal con snap, a razón de una columna por pantalla.
- [ ] Al arrastrar una tarjeta a otra columna, después de recargar sigue ahí y en la misma posición. En el celular hay que mantener apretado un momento antes de arrastrar, y el scroll vertical no arrastra la tarjeta.
- [ ] Con el filtro de un cliente, al arrastrar su segunda tarjeta arriba de la primera y sacar el filtro, esa tarjeta queda justo antes de la otra.
- [ ] El selector de estado de la tarjeta mueve el proceso de columna.
- [ ] "Lista" muestra tarjetas; la URL conserva `?vista=lista&cliente_id=…` y sobrevive a F5.
- [ ] Si ponés una entrega anterior al inicio, el error aparece bajo "Entrega estimada".
- [ ] En el detalle del proceso: agregar subtareas, tildar (se tachan y el contador pasa a "1 de 3"), reordenar con el ícono ⋮⋮ y recargar para confirmar el orden, editar y eliminar.
- [ ] Al volver al tablero, la tarjeta muestra el badge "1/3".

- [ ] **Step 6: Commit**

```bash
git add admin/procesos.php admin/proceso.php admin/assets/vendor/sortable.min.js admin/assets/kanban.js admin/assets/mod-procesos.js admin/assets/procesos.js admin/assets/proceso.js
git commit -m "$(cat <<'EOF'
Agregar tablero de procesos con drag & drop y checklist de subtareas

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 11: Cobros y comprobantes (backend)

**Files:**
- Create: `admin/includes/repos/cobros.php`, `admin/includes/comprobantes.php`
- Create: `api/cobros.php`, `api/comprobante.php`
- Test: `tests/CobrosTest.php`, `tests/ComprobantesTest.php`

**Interfaces:**
- Consumes: `validar_proceso_de_cliente()` (Task 9), `hoy()`, helpers de CRUD.
- Produces:
  - `COBRO_ESTADOS`, `cobros_schema()`, `cobros_select(): string` (el primer `?` es la fecha de hoy), `cobro_publico(array): array`.
  - Cada cobro que devuelve la API trae `cliente_nombre`, `proceso_titulo`, `estado_efectivo` (`pendiente`, `vencido` o `pagado`) y `tiene_archivo` (bool). **Nunca** incluye `comprobante_archivo`.
  - `cobros_list(array $get)`: filtros `cliente_id`, `proceso_id`, `estado` (`pendiente`, `pagado` o `vencido`), `desde` y `hasta`, aplicados sobre `COALESCE(fecha_pago, fecha_vencimiento)`.
  - `cobros_get`, `cobros_create`, `cobros_update`, `cobros_delete` (el borrado también elimina el archivo).
  - `uploads_dir(): string`
  - `comprobante_guardar(int $cobroId, string $rutaTemporal, int $bytes): array`: devuelve el cobro público.
  - `comprobante_quitar(int $cobroId): void`
  - `comprobante_archivo(int $cobroId): array`: devuelve `[ruta, mime, ext]`.
  - `comprobante_borrar_archivo(?string $nombre): void`

- [ ] **Step 1: Escribir los tests que fallan**

`tests/CobrosTest.php`:

```php
<?php
declare(strict_types=1);

final class CobrosTest extends DbTestCase
{
    private int $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        clock_set('2026-03-10');
        $this->cliente = clientes_create(['nombre' => 'Acme'])['id'];
    }

    private function cobro(array $extra = []): array
    {
        return cobros_create($extra + [
            'cliente_id' => $this->cliente, 'monto' => '1000', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-03-20',
        ]);
    }

    public function test_crear_pendiente_sin_exponer_el_archivo_interno(): void
    {
        $c = $this->cobro(['concepto' => 'Anticipo 50%']);
        $this->assertSame('pendiente', $c['estado']);
        $this->assertSame('pendiente', $c['estado_efectivo']);
        $this->assertSame('1000.00', $c['monto']);
        $this->assertSame('Acme', $c['cliente_nombre']);
        $this->assertFalse($c['tiene_archivo']);
        $this->assertArrayNotHasKey('comprobante_archivo', $c);
    }

    public function test_normaliza_monto_y_moneda(): void
    {
        $c = $this->cobro(['monto' => '1500,5', 'moneda' => 'usd']);
        $this->assertSame('1500.50', $c['monto']);
        $this->assertSame('USD', $c['moneda']);
    }

    public function test_vencido_se_calcula_con_la_fecha_de_hoy(): void
    {
        $vencido = $this->cobro(['fecha_vencimiento' => '2026-03-09']);
        $hoy = $this->cobro(['fecha_vencimiento' => '2026-03-10']);
        $this->assertSame('vencido', $vencido['estado_efectivo']);
        $this->assertSame('pendiente', $hoy['estado_efectivo']);
        $this->assertSame([$vencido['id']], array_column(cobros_list(['estado' => 'vencido']), 'id'));
        $this->assertSame([$hoy['id']], array_column(cobros_list(['estado' => 'pendiente']), 'id'));
    }

    public function test_marcar_pagado_completa_fecha_de_pago_y_volver_a_pendiente_la_borra(): void
    {
        $c = $this->cobro(['fecha_vencimiento' => '2026-03-01']);
        $pagado = cobros_update($c['id'], ['estado' => 'pagado']);
        $this->assertSame('2026-03-10', $pagado['fecha_pago']);
        $this->assertSame('pagado', $pagado['estado_efectivo']);
        $conFecha = cobros_update($c['id'], ['fecha_pago' => '2026-03-05']);
        $this->assertSame('2026-03-05', $conFecha['fecha_pago']);
        $pendiente = cobros_update($c['id'], ['estado' => 'pendiente']);
        $this->assertNull($pendiente['fecha_pago']);
        $this->assertSame('vencido', $pendiente['estado_efectivo']);
    }

    public function test_proceso_tiene_que_ser_del_cliente(): void
    {
        $otro = clientes_create(['nombre' => 'Otro'])['id'];
        $ajeno = procesos_create(['cliente_id' => $otro, 'titulo' => 'Ajeno']);
        $this->assertArrayHasKey('proceso_id', $this->errores422(fn() => $this->cobro(['proceso_id' => $ajeno['id']])));
        $propio = procesos_create(['cliente_id' => $this->cliente, 'titulo' => 'Propio']);
        $this->assertSame('Propio', $this->cobro(['proceso_id' => $propio['id']])['proceso_titulo']);
    }

    public function test_cambiar_cliente_con_proceso_ajeno_falla(): void
    {
        $otro = clientes_create(['nombre' => 'Otro'])['id'];
        $propio = procesos_create(['cliente_id' => $this->cliente, 'titulo' => 'Propio']);
        $c = $this->cobro(['proceso_id' => $propio['id']]);
        $this->assertArrayHasKey('proceso_id', $this->errores422(fn() => cobros_update($c['id'], ['cliente_id' => $otro])));
        $movido = cobros_update($c['id'], ['cliente_id' => $otro, 'proceso_id' => null]);
        $this->assertSame($otro, $movido['cliente_id']);
    }

    public function test_link_de_comprobante_solo_http(): void
    {
        $this->assertArrayHasKey('comprobante_url', $this->errores422(fn() => $this->cobro(['comprobante_url' => 'javascript:alert(1)'])));
        $this->assertSame('https://mp.com/r/1', $this->cobro(['comprobante_url' => 'https://mp.com/r/1'])['comprobante_url']);
    }

    public function test_obligatorios(): void
    {
        $campos = $this->errores422(fn() => cobros_create(['cliente_id' => $this->cliente]));
        foreach (['monto', 'moneda', 'fecha_vencimiento'] as $campo) {
            $this->assertArrayHasKey($campo, $campos);
        }
    }

    public function test_orden_filtros_y_borrado(): void
    {
        $pagado = $this->cobro(['fecha_vencimiento' => '2026-01-01', 'estado' => 'pagado', 'fecha_pago' => '2026-01-05']);
        $tarde = $this->cobro(['fecha_vencimiento' => '2026-04-01']);
        $pronto = $this->cobro(['fecha_vencimiento' => '2026-03-15']);
        $this->assertSame([$pronto['id'], $tarde['id'], $pagado['id']], array_column(cobros_list([]), 'id'));
        $this->assertSame([$pagado['id']], array_column(cobros_list(['desde' => '2026-01-01', 'hasta' => '2026-01-31']), 'id'));
        $this->assertSame([$pagado['id']], array_column(cobros_list(['estado' => 'pagado']), 'id'));
        cobros_delete($tarde['id']);
        $this->assertHttp(404, fn() => cobros_get($tarde['id']));
    }
}
```

`tests/ComprobantesTest.php`:

```php
<?php
declare(strict_types=1);

final class ComprobantesTest extends DbTestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $dir;
    private array $cobro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vezza_up_' . bin2hex(random_bytes(4));
        env_set('UPLOADS_DIR', $this->dir);
        $cliente = clientes_create(['nombre' => 'Acme'])['id'];
        $this->cobro = cobros_create(['cliente_id' => $cliente, 'monto' => '10', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-01-01']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
        env_set('UPLOADS_DIR', null);
        parent::tearDown();
    }

    private function temporal(string $contenido): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'vz');
        file_put_contents($ruta, $contenido);
        return $ruta;
    }

    private function archivos(): array
    {
        return glob($this->dir . '/*') ?: [];
    }

    public function test_guardar_png_valido(): void
    {
        $tmp = $this->temporal(base64_decode(self::PNG_1X1));
        $c = comprobante_guardar($this->cobro['id'], $tmp, filesize($tmp));
        $this->assertTrue($c['tiene_archivo']);
        $this->assertCount(1, $this->archivos());
        [$ruta, $mime, $ext] = comprobante_archivo($this->cobro['id']);
        $this->assertSame('image/png', $mime);
        $this->assertSame('png', $ext);
        $this->assertFileExists($ruta);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', basename($ruta));
    }

    public function test_rechaza_tipo_no_permitido_aunque_la_extension_mienta(): void
    {
        $tmp = $this->temporal('<?php echo "hola";');
        $this->assertArrayHasKey('archivo', $this->errores422(fn() => comprobante_guardar($this->cobro['id'], $tmp, filesize($tmp))));
        $this->assertCount(0, $this->archivos());
    }

    public function test_rechaza_mas_de_5_mb(): void
    {
        $tmp = $this->temporal(base64_decode(self::PNG_1X1));
        $this->assertArrayHasKey('archivo', $this->errores422(fn() => comprobante_guardar($this->cobro['id'], $tmp, 5 * 1024 * 1024 + 1)));
    }

    public function test_reemplazar_borra_el_anterior_y_quitar_lo_elimina(): void
    {
        $tmp1 = $this->temporal(base64_decode(self::PNG_1X1));
        comprobante_guardar($this->cobro['id'], $tmp1, filesize($tmp1));
        $tmp2 = $this->temporal(base64_decode(self::PNG_1X1));
        comprobante_guardar($this->cobro['id'], $tmp2, filesize($tmp2));
        $this->assertCount(1, $this->archivos());
        comprobante_quitar($this->cobro['id']);
        $this->assertCount(0, $this->archivos());
        $this->assertFalse(cobros_get($this->cobro['id'])['tiene_archivo']);
        $this->assertHttp(404, fn() => comprobante_archivo($this->cobro['id']));
    }

    public function test_borrar_cobro_borra_el_archivo(): void
    {
        $tmp = $this->temporal(base64_decode(self::PNG_1X1));
        comprobante_guardar($this->cobro['id'], $tmp, filesize($tmp));
        cobros_delete($this->cobro['id']);
        $this->assertCount(0, $this->archivos());
    }

    public function test_cobro_inexistente_da_404(): void
    {
        $tmp = $this->temporal(base64_decode(self::PNG_1X1));
        $this->assertHttp(404, fn() => comprobante_guardar(999999, $tmp, filesize($tmp)));
    }
}
```

- [ ] **Step 2: Correr los tests y confirmar que fallan**

Run: `php vendor/bin/phpunit tests/CobrosTest.php tests/ComprobantesTest.php`
Resultado esperado: error `Call to undefined function cobros_create()`.

- [ ] **Step 3: Implementar**

`admin/includes/repos/cobros.php`:

```php
<?php
declare(strict_types=1);

const COBRO_ESTADOS = ['pendiente', 'pagado'];

function cobros_schema(): array
{
    return [
        'cliente_id' => ['type' => 'fk', 'table' => 'clientes', 'required' => true],
        'proceso_id' => ['type' => 'fk', 'table' => 'procesos'],
        'concepto' => ['type' => 'string'],
        'monto' => ['type' => 'decimal', 'required' => true],
        'moneda' => ['type' => 'currency', 'required' => true],
        'fecha_vencimiento' => ['type' => 'date', 'required' => true],
        'fecha_pago' => ['type' => 'date'],
        'estado' => ['type' => 'enum', 'values' => COBRO_ESTADOS, 'notnull' => true],
        'metodo_pago' => ['type' => 'string', 'max' => 100],
        'comprobante_url' => ['type' => 'url', 'max' => 500],
    ];
}

/** El primer placeholder es la fecha de hoy (para calcular "vencido"). */
function cobros_select(): string
{
    return "SELECT c.*, cl.nombre AS cliente_nombre, p.titulo AS proceso_titulo,
            CASE WHEN c.estado = 'pagado' THEN 'pagado'
                 WHEN c.fecha_vencimiento < ? THEN 'vencido'
                 ELSE 'pendiente' END AS estado_efectivo
        FROM cobros c
        JOIN clientes cl ON cl.id = c.cliente_id
        LEFT JOIN procesos p ON p.id = c.proceso_id";
}

function cobro_publico(array $c): array
{
    $c['tiene_archivo'] = $c['comprobante_archivo'] !== null;
    unset($c['comprobante_archivo']);
    return $c;
}

function cobros_list(array $get): array
{
    $f = filtros($get, [
        'cliente_id' => ['type' => 'int'],
        'proceso_id' => ['type' => 'int'],
        'estado' => ['type' => 'enum', 'values' => ['pendiente', 'pagado', 'vencido']],
        'desde' => ['type' => 'date'],
        'hasta' => ['type' => 'date'],
    ]);
    [$partes, $params] = where_eq($f, ['cliente_id' => 'c.cliente_id', 'proceso_id' => 'c.proceso_id']);
    $estado = $f['estado'] ?? null;
    if ($estado === 'pagado') {
        $partes[] = "c.estado = 'pagado'";
    } elseif ($estado === 'pendiente') {
        $partes[] = "c.estado = 'pendiente' AND c.fecha_vencimiento >= ?";
        $params[] = hoy();
    } elseif ($estado === 'vencido') {
        $partes[] = "c.estado = 'pendiente' AND c.fecha_vencimiento < ?";
        $params[] = hoy();
    }
    if (isset($f['desde'])) {
        $partes[] = 'COALESCE(c.fecha_pago, c.fecha_vencimiento) >= ?';
        $params[] = $f['desde'];
    }
    if (isset($f['hasta'])) {
        $partes[] = 'COALESCE(c.fecha_pago, c.fecha_vencimiento) <= ?';
        $params[] = $f['hasta'];
    }
    $sql = cobros_select() . sql_where($partes)
        . " ORDER BY c.estado = 'pagado',
                   CASE WHEN c.estado = 'pagado' THEN NULL ELSE c.fecha_vencimiento END,
                   c.fecha_pago DESC, c.id DESC";
    return array_map('cobro_publico', q_all($sql, [hoy(), ...$params]));
}

function cobros_get(int $id): array
{
    $c = q_one(cobros_select() . ' WHERE c.id = ?', [hoy(), $id]);
    if ($c === null) {
        throw new HttpError(404, 'No encontrado');
    }
    return cobro_publico($c);
}

function cobros_normalizar(array $datos, ?array $actual): array
{
    $clienteId = $datos['cliente_id'] ?? ($actual['cliente_id'] ?? null);
    $procesoId = array_key_exists('proceso_id', $datos) ? $datos['proceso_id'] : ($actual['proceso_id'] ?? null);
    validar_proceso_de_cliente($procesoId === null ? null : (int)$procesoId, $clienteId === null ? null : (int)$clienteId);

    $estado = $datos['estado'] ?? ($actual['estado'] ?? 'pendiente');
    if ($estado === 'pagado') {
        $fechaPago = array_key_exists('fecha_pago', $datos) ? $datos['fecha_pago'] : ($actual['fecha_pago'] ?? null);
        if ($fechaPago === null) {
            $datos['fecha_pago'] = hoy();
        }
    } elseif (($datos['estado'] ?? null) === 'pendiente') {
        $datos['fecha_pago'] = null;
    }
    return $datos;
}

function cobros_create(array $input): array
{
    $datos = cobros_normalizar(validate($input, cobros_schema()), null);
    return cobros_get(crud_insert('cobros', $datos));
}

function cobros_update(int $id, array $input): array
{
    $actual = crud_find('cobros', $id);
    $datos = cobros_normalizar(validate($input, cobros_schema(), true), $actual);
    crud_update('cobros', $id, $datos);
    return cobros_get($id);
}

function cobros_delete(int $id): void
{
    $actual = crud_find('cobros', $id);
    crud_delete('cobros', $id);
    comprobante_borrar_archivo($actual['comprobante_archivo']);
}
```

`admin/includes/comprobantes.php`:

```php
<?php
declare(strict_types=1);

const COMPROBANTE_MAX_BYTES = 5 * 1024 * 1024;
const COMPROBANTE_TIPOS = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

function uploads_dir(): string
{
    $dir = env('UPLOADS_DIR') ?? dirname(__DIR__, 3) . '/vezza_uploads/comprobantes';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException("No se pudo crear la carpeta de comprobantes: $dir");
    }
    return rtrim($dir, '/\\');
}

function comprobante_borrar_archivo(?string $nombre): void
{
    if ($nombre === null || !preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|webp)$/', $nombre)) {
        return;
    }
    $ruta = uploads_dir() . '/' . $nombre;
    if (is_file($ruta)) {
        unlink($ruta);
    }
}

function comprobante_guardar(int $cobroId, string $rutaTemporal, int $bytes): array
{
    $cobro = crud_find('cobros', $cobroId);
    if ($bytes <= 0) {
        throw new HttpError(422, 'El archivo está vacío', ['archivo' => 'Está vacío']);
    }
    if ($bytes > COMPROBANTE_MAX_BYTES) {
        throw new HttpError(422, 'El archivo supera los 5 MB', ['archivo' => 'Máximo 5 MB']);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($rutaTemporal);
    $ext = COMPROBANTE_TIPOS[$mime] ?? null;
    if ($ext === null) {
        throw new HttpError(422, 'Formato no permitido. Usá PDF, JPG, PNG o WEBP.', ['archivo' => 'Formato no permitido']);
    }
    $nombre = bin2hex(random_bytes(16)) . '.' . $ext;
    $destino = uploads_dir() . '/' . $nombre;
    $ok = is_uploaded_file($rutaTemporal) ? move_uploaded_file($rutaTemporal, $destino) : copy($rutaTemporal, $destino);
    if (!$ok) {
        throw new RuntimeException('No se pudo guardar el comprobante');
    }
    db()->prepare('UPDATE cobros SET comprobante_archivo = ? WHERE id = ?')->execute([$nombre, $cobroId]);
    comprobante_borrar_archivo($cobro['comprobante_archivo']);
    return cobros_get($cobroId);
}

function comprobante_quitar(int $cobroId): void
{
    $cobro = crud_find('cobros', $cobroId);
    db()->prepare('UPDATE cobros SET comprobante_archivo = NULL WHERE id = ?')->execute([$cobroId]);
    comprobante_borrar_archivo($cobro['comprobante_archivo']);
}

/** @return array{0:string,1:string,2:string} ruta, mime, extensión */
function comprobante_archivo(int $cobroId): array
{
    $cobro = crud_find('cobros', $cobroId);
    $nombre = $cobro['comprobante_archivo'];
    if ($nombre === null || !preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|webp)$/', $nombre, $m)) {
        throw new HttpError(404, 'Este cobro no tiene archivo');
    }
    $ruta = uploads_dir() . '/' . $nombre;
    if (!is_file($ruta)) {
        throw new HttpError(404, 'No se encontró el archivo');
    }
    return [$ruta, (string)array_search($m[1], COMPROBANTE_TIPOS, true), $m[1]];
}
```

`api/cobros.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource([
    'list' => 'cobros_list',
    'get' => 'cobros_get',
    'create' => 'cobros_create',
    'update' => 'cobros_update',
    'delete' => 'cobros_delete',
]);
```

`api/comprobante.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_run(function (): void {
    $id = route_id() ?? throw new HttpError(404, 'No encontrado');
    switch ($_SERVER['REQUEST_METHOD'] ?? 'GET') {
        case 'GET':
            [$ruta, $mime, $ext] = comprobante_archivo($id);
            header_remove('Content-Security-Policy');
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($ruta));
            header('Content-Disposition: inline; filename="comprobante-' . $id . '.' . $ext . '"');
            readfile($ruta);
            return;
        case 'POST':
            $f = $_FILES['archivo'] ?? null;
            $error = is_array($f) ? ($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                throw new HttpError(422, 'El archivo supera el máximo permitido', ['archivo' => 'Muy pesado']);
            }
            if ($error !== UPLOAD_ERR_OK || !is_string($f['tmp_name'] ?? null) || !is_uploaded_file($f['tmp_name'])) {
                throw new HttpError(422, 'No llegó ningún archivo', ['archivo' => 'Elegí un archivo']);
            }
            json_out(200, ['data' => comprobante_guardar($id, $f['tmp_name'], (int)$f['size'])]);
            return;
        case 'DELETE':
            comprobante_quitar($id);
            json_out(204);
            return;
    }
    throw new HttpError(405, 'Método no permitido');
});
```

- [ ] **Step 4: Correr los tests y confirmar que pasan**

Run: `php vendor/bin/phpunit`
Resultado esperado: `OK`. Si aparece `Class "finfo" not found`, habilitá `extension=fileinfo` en `C:\xampp\php\php.ini`.

- [ ] **Step 5: Commit**

```bash
git add admin/includes/repos/cobros.php admin/includes/comprobantes.php api/cobros.php api/comprobante.php tests/CobrosTest.php tests/ComprobantesTest.php
git commit -m "$(cat <<'EOF'
Agregar API de cobros con vencidos calculados y comprobantes privados

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 12: Cobros (interfaz)

**Files:**
- Create: `admin/assets/mod-cobros.js`, `admin/assets/cobros.js`, `admin/cobros.php`

**Interfaces:**
- Consumes: `Panel.*`, `/api/cobros`, `/api/comprobante/{id}` y `/api/procesos`.
- Produces: objeto global `Cobros` con:
  - `campos(clientes, procesos)`
  - `nuevo(valores)`, `editar(c)`
  - `subirComprobante(c, alTerminar)`
  - `item(c, recargar)`

- [ ] **Step 1: Crear el módulo**

`admin/assets/mod-cobros.js`:

```js
'use strict';

const Cobros = {
  campos(clientes, procesos) {
    return [
      { name: 'cliente_id', label: 'Cliente', type: 'select', required: true, vacio: 'Elegí un cliente', options: Panel.opcionesClientes(clientes) },
      { name: 'proceso_id', label: 'Proceso (opcional)', type: 'select', vacio: 'Sin proceso', filtro: 'cliente_id', options: procesos.map((p) => [String(p.id), p.titulo, p.cliente_id]) },
      { name: 'concepto', label: 'Concepto', placeholder: 'Anticipo 50%' },
      { name: 'monto', label: 'Monto', type: 'money', required: true, placeholder: '150000' },
      { name: 'moneda', label: 'Moneda', required: true, default: 'ARS', maxlength: 3, list: ['ARS', 'USD'] },
      { name: 'fecha_vencimiento', label: 'Vence', type: 'date', required: true, default: Panel.hoy() },
      { name: 'estado', label: 'Estado', type: 'select', options: [['pendiente', 'Pendiente'], ['pagado', 'Pagado']] },
      { name: 'fecha_pago', label: 'Fecha de pago', type: 'date', ayuda: 'Si lo marcás pagado y la dejás vacía, se usa la fecha de hoy.' },
      { name: 'metodo_pago', label: 'Método de pago', list: ['Transferencia', 'Efectivo', 'Mercado Pago', 'PayPal', 'Tarjeta'] },
      { name: 'comprobante_url', label: 'Link al comprobante', type: 'url', placeholder: 'https://…' },
    ];
  },

  async datos() {
    const [clientes, procesos] = await Promise.all([Panel.clientes(), Panel.get('/api/procesos')]);
    return { clientes, procesos };
  },

  async nuevo(valores = {}) {
    const { clientes, procesos } = await this.datos();
    return Panel.modalForm({ titulo: 'Nuevo cobro', campos: this.campos(clientes, procesos), valores, enviar: (d) => Panel.post('/api/cobros', d) });
  },

  async editar(c) {
    const { clientes, procesos } = await this.datos();
    return Panel.modalForm({
      titulo: 'Editar cobro', campos: this.campos(clientes, procesos), valores: c,
      enviar: (d) => Panel.put(`/api/cobros/${c.id}`, d),
      eliminar: () => Panel.del(`/api/cobros/${c.id}`),
    });
  },

  subirComprobante(c, alTerminar) {
    const input = Panel.el('input', { type: 'file', accept: 'application/pdf,image/jpeg,image/png,image/webp' });
    input.addEventListener('change', async () => {
      const archivo = input.files[0];
      if (!archivo) return;
      if (archivo.size > 5 * 1024 * 1024) {
        Panel.toast('El archivo supera los 5 MB', 'error');
        return;
      }
      const fd = new FormData();
      fd.append('archivo', archivo);
      try {
        await Panel.api('POST', `/api/comprobante/${c.id}`, fd);
        Panel.toast('Comprobante subido');
        alTerminar();
      } catch (err) {
        Panel.manejarError(err);
      }
    });
    input.click();
  },

  async quitarComprobante(c, alTerminar) {
    if (!(await Panel.confirmar('¿Quitar el archivo del comprobante?', 'Quitar'))) return;
    try {
      await Panel.del(`/api/comprobante/${c.id}`);
      alTerminar();
    } catch (err) {
      Panel.manejarError(err);
    }
  },

  item(c, recargar) {
    const { el } = Panel;
    const acciones = [];
    if (c.estado === 'pendiente') {
      acciones.push(Panel.boton('Marcar pagado', async () => {
        try {
          await Panel.put(`/api/cobros/${c.id}`, { estado: 'pagado' });
          Panel.toast('Cobro marcado como pagado');
          recargar();
        } catch (err) {
          Panel.manejarError(err);
        }
      }, 'chico'));
    }
    acciones.push(Panel.boton('Editar', async () => { if (await Cobros.editar(c)) recargar(); }, 'chico'));
    acciones.push(Panel.boton(c.tiene_archivo ? 'Reemplazar archivo' : 'Subir archivo', () => Cobros.subirComprobante(c, recargar), 'chico'));
    if (c.tiene_archivo) {
      acciones.push(el('a', { class: 'btn btn-chico', href: `/api/comprobante/${c.id}`, target: '_blank', rel: 'noopener', text: 'Ver archivo' }));
      acciones.push(Panel.boton('Quitar archivo', () => Cobros.quitarComprobante(c, recargar), 'chico'));
    }
    if (c.comprobante_url) {
      acciones.push(el('a', { class: 'btn btn-chico', href: c.comprobante_url, target: '_blank', rel: 'noopener noreferrer', text: 'Ver link' }));
    }
    const fecha = c.estado === 'pagado' ? `Pagado el ${Panel.fmtFecha(c.fecha_pago)}` : `Vence ${Panel.fmtFecha(c.fecha_vencimiento)}`;
    return el('div', { class: 'item' },
      el('div', { class: 'item-top' },
        el('span', { class: 'item-titulo', text: Panel.fmtMonto(c.monto, c.moneda) }),
        Panel.badgeEstado('estadoCobro', c.estado_efectivo)),
      el('div', { class: 'item-sub', text: [c.cliente_nombre, c.proceso_titulo, c.concepto].filter(Boolean).join(' · ') }),
      el('div', { class: 'item-sub', text: [fecha, c.metodo_pago].filter(Boolean).join(' · ') }),
      el('div', { class: 'item-acciones' }, acciones));
  },

  totales(cobros) {
    const suma = {};
    for (const c of cobros) suma[c.moneda] = (suma[c.moneda] || 0) + Number(c.monto);
    return Object.entries(suma).map(([m, t]) => Panel.fmtMonto(t, m)).join(' + ');
  },
};
```

- [ ] **Step 2: Crear la página**

`admin/cobros.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();
layout_start('Cobros', 'cobros', ['mod-cobros.js', 'cobros.js']);
?>
<div class="toolbar">
  <select class="filtro" id="filtro-cliente" aria-label="Filtrar por cliente">
    <option value="">Todos los clientes</option>
  </select>
</div>
<div id="cobros"></div>
<?php
layout_end();
```

`admin/assets/cobros.js`:

```js
'use strict';

(() => {
  const { el } = Panel;
  const fCliente = document.getElementById('filtro-cliente');
  const params = new URLSearchParams(location.search);
  let tabs;

  const render = (estado, textoVacio) => async function pintar(panel) {
    const cobros = await Panel.get('/api/cobros', { estado, cliente_id: fCliente.value });
    const recargar = () => pintar(panel).catch(Panel.manejarError);
    Panel.llenar(panel,
      cobros.length ? el('p', { class: 'item-sub', text: `Total: ${Cobros.totales(cobros)}` }) : null,
      cobros.length ? el('div', { class: 'lista' }, cobros.map((c) => Cobros.item(c, recargar))) : Panel.vacio(textoVacio));
  };

  const TABS = [
    { id: 'pendientes', label: 'Pendientes', render: render('pendiente', 'No hay cobros pendientes por vencer.') },
    { id: 'vencidos', label: 'Vencidos', render: render('vencido', 'No hay cobros vencidos.') },
    { id: 'pagados', label: 'Pagados', render: render('pagado', 'Todavía no hay cobros pagados.') },
    { id: 'todos', label: 'Todos', render: render('', 'Todavía no cargaste cobros.') },
  ];

  Panel.acciones(Panel.boton('+ Nuevo cobro', async () => {
    if (await Cobros.nuevo({ cliente_id: fCliente.value || undefined })) tabs.activar(tabs.actual());
  }, 'primario'));

  Panel.clientes().then((clientes) => {
    fCliente.append(...clientes.map((c) => el('option', { value: String(c.id), text: c.nombre })));
    fCliente.value = params.get('cliente_id') || '';
    tabs = Panel.tabs(document.getElementById('cobros'), TABS);
    fCliente.addEventListener('change', () => {
      history.replaceState(null, '', location.pathname + Panel.qs({ cliente_id: fCliente.value }) + location.hash);
      tabs.activar(tabs.actual());
    });
  }).catch(Panel.manejarError);
})();
```

- [ ] **Step 3: Verificar en el navegador**

Checklist en `/admin/cobros`:
- [ ] Nuevo cobro: al elegir un cliente, "Proceso" solo lista procesos de ese cliente y al cambiar de cliente se resetea. Escribir el monto `1500,5` guarda `$ 1.500,50`.
- [ ] Un cobro con vencimiento de ayer aparece en "Vencidos" con badge rojo, y uno de hoy en "Pendientes".
- [ ] "Marcar pagado" lo pasa a "Pagados" con "Pagado el <hoy>".
- [ ] "Subir archivo" con un PDF o JPG: aparecen "Ver archivo" (abre en otra pestaña) y "Quitar archivo". Un `.txt` renombrado a `.pdf` da el error "Formato no permitido…".
- [ ] Abrir `/api/comprobante/<id>` en una ventana de incógnito responde JSON 401.
- [ ] El archivo quedó en `UPLOADS_DIR` y **no** dentro del repo.
- [ ] Un link `https://…` muestra "Ver link".
- [ ] El filtro por cliente se mantiene al cambiar de pestaña y al recargar (`?cliente_id=…#vencidos`).

- [ ] **Step 4: Commit**

```bash
git add admin/cobros.php admin/assets/mod-cobros.js admin/assets/cobros.js
git commit -m "$(cat <<'EOF'
Agregar pantalla de cobros con pestañas por estado y comprobantes

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 13: Suscripciones y gastos (backend)

**Files:**
- Create: `admin/includes/repos/suscripciones.php`, `admin/includes/repos/gastos.php`
- Create: `api/suscripciones.php`, `api/gastos.php`
- Test: `tests/SuscripcionesTest.php`, `tests/GastosTest.php`

**Interfaces:**
- Consumes: `hoy()`, `dias_entre()`, helpers de CRUD.
- Produces:
  - **Suscripciones:**
    - `sumar_periodo(string $fecha, string $frecuencia): string`
    - `suscripcion_publica(array): array`: agrega `dias_restantes` (int, negativo si ya pasó la fecha).
    - `suscripciones_list(array $get)`: filtro `activa`.
    - `suscripciones_get`, `suscripciones_create`, `suscripciones_update`, `suscripciones_delete`
    - `suscripciones_pagar(int $id, array $input)`:
      - acepta `fecha` y `monto` opcionales;
      - devuelve `['gasto' => fila, 'suscripcion' => fila]`;
      - 409 si la suscripción está pausada.
  - **Gastos:**
    - `gastos_list(array $get)`: filtros `desde`, `hasta`, `categoria` y `suscripcion_id`. Cada fila trae `suscripcion_servicio`.
    - `gastos_get`, `gastos_create`, `gastos_update`, `gastos_delete`

- [ ] **Step 1: Escribir los tests que fallan**

`tests/SuscripcionesTest.php`:

```php
<?php
declare(strict_types=1);

final class SuscripcionesTest extends DbTestCase
{
    private function suscripcion(array $extra = []): array
    {
        return suscripciones_create($extra + [
            'servicio' => 'Claude', 'categoria' => 'IA', 'monto' => '20', 'moneda' => 'USD',
            'frecuencia' => 'mensual', 'fecha_proximo_cobro' => '2026-01-31',
        ]);
    }

    public function test_sumar_periodo_ajusta_fin_de_mes_y_bisiestos(): void
    {
        $this->assertSame('2026-02-28', sumar_periodo('2026-01-31', 'mensual'));
        $this->assertSame('2028-02-29', sumar_periodo('2028-01-31', 'mensual'));
        $this->assertSame('2027-01-15', sumar_periodo('2026-12-15', 'mensual'));
        $this->assertSame('2026-05-30', sumar_periodo('2026-04-30', 'mensual'));
        $this->assertSame('2029-02-28', sumar_periodo('2028-02-29', 'anual'));
        $this->assertSame('2027-03-10', sumar_periodo('2026-03-10', 'anual'));
    }

    public function test_crear_con_dias_restantes(): void
    {
        clock_set('2026-01-25');
        $s = $this->suscripcion();
        $this->assertSame(1, $s['activa']);
        $this->assertSame(6, $s['dias_restantes']);
        $this->assertSame('20.00', $s['monto']);
    }

    public function test_obligatorios(): void
    {
        $campos = $this->errores422(fn() => suscripciones_create(['servicio' => 'X']));
        foreach (['monto', 'moneda', 'frecuencia', 'fecha_proximo_cobro'] as $campo) {
            $this->assertArrayHasKey($campo, $campos);
        }
    }

    public function test_pagar_crea_gasto_y_avanza_la_fecha(): void
    {
        $s = $this->suscripcion();
        $r = suscripciones_pagar($s['id'], []);
        $this->assertSame('Claude', $r['gasto']['concepto']);
        $this->assertSame('IA', $r['gasto']['categoria']);
        $this->assertSame('20.00', $r['gasto']['monto']);
        $this->assertSame('USD', $r['gasto']['moneda']);
        $this->assertSame('2026-01-31', $r['gasto']['fecha']);
        $this->assertSame($s['id'], $r['gasto']['suscripcion_id']);
        $this->assertSame('2026-02-28', $r['suscripcion']['fecha_proximo_cobro']);
    }

    public function test_pagar_con_fecha_y_monto_distintos(): void
    {
        $s = $this->suscripcion();
        $r = suscripciones_pagar($s['id'], ['fecha' => '2026-02-02', 'monto' => '22,5']);
        $this->assertSame('2026-02-02', $r['gasto']['fecha']);
        $this->assertSame('22.50', $r['gasto']['monto']);
        $this->assertSame('2026-02-28', $r['suscripcion']['fecha_proximo_cobro']);
    }

    public function test_pagar_pausada_da_409(): void
    {
        $s = $this->suscripcion(['activa' => false]);
        $this->assertHttp(409, fn() => suscripciones_pagar($s['id'], []));
        $this->assertSame(0, (int)q_val('SELECT COUNT(*) FROM gastos'));
    }

    public function test_list_y_borrar_conserva_los_gastos(): void
    {
        $a = $this->suscripcion();
        $this->suscripcion(['servicio' => 'Viejo', 'activa' => false]);
        $this->assertSame(['Claude', 'Viejo'], array_column(suscripciones_list([]), 'servicio'));
        $this->assertSame(['Claude'], array_column(suscripciones_list(['activa' => '1']), 'servicio'));
        $r = suscripciones_pagar($a['id'], []);
        suscripciones_delete($a['id']);
        $this->assertNull(gastos_get($r['gasto']['id'])['suscripcion_id']);
    }
}
```

`tests/GastosTest.php`:

```php
<?php
declare(strict_types=1);

final class GastosTest extends DbTestCase
{
    public function test_crear_y_validar(): void
    {
        $g = gastos_create(['concepto' => 'Dominio .com', 'monto' => '15000', 'moneda' => 'ars', 'fecha' => '2026-03-01', 'categoria' => 'Dominios']);
        $this->assertSame('15000.00', $g['monto']);
        $this->assertSame('ARS', $g['moneda']);
        $this->assertNull($g['suscripcion_id']);
        $campos = $this->errores422(fn() => gastos_create(['suscripcion_id' => 999999]));
        foreach (['concepto', 'monto', 'moneda', 'fecha', 'suscripcion_id'] as $campo) {
            $this->assertArrayHasKey($campo, $campos);
        }
    }

    public function test_list_filtra_por_rango_y_categoria_y_ordena_por_fecha_desc(): void
    {
        gastos_create(['concepto' => 'A', 'monto' => '1', 'moneda' => 'ARS', 'fecha' => '2026-02-28', 'categoria' => 'Hosting']);
        gastos_create(['concepto' => 'B', 'monto' => '1', 'moneda' => 'ARS', 'fecha' => '2026-03-01', 'categoria' => 'IA']);
        gastos_create(['concepto' => 'C', 'monto' => '1', 'moneda' => 'ARS', 'fecha' => '2026-03-31', 'categoria' => 'Hosting']);
        $this->assertSame(['C', 'B'], array_column(gastos_list(['desde' => '2026-03-01', 'hasta' => '2026-03-31']), 'concepto'));
        $this->assertSame(['C', 'A'], array_column(gastos_list(['categoria' => 'Hosting']), 'concepto'));
    }

    public function test_actualizar_y_borrar(): void
    {
        $g = gastos_create(['concepto' => 'A', 'monto' => '1', 'moneda' => 'ARS', 'fecha' => '2026-03-01']);
        $this->assertSame('Hosting', gastos_update($g['id'], ['categoria' => 'Hosting'])['categoria']);
        gastos_delete($g['id']);
        $this->assertFalse(crud_exists('gastos', $g['id']));
    }
}
```

- [ ] **Step 2: Correr los tests y confirmar que fallan**

Run: `php vendor/bin/phpunit tests/SuscripcionesTest.php tests/GastosTest.php`
Resultado esperado: error `Call to undefined function sumar_periodo()`.

- [ ] **Step 3: Implementar**

`admin/includes/repos/suscripciones.php`:

```php
<?php
declare(strict_types=1);

function suscripciones_schema(): array
{
    return [
        'servicio' => ['type' => 'string', 'required' => true],
        'categoria' => ['type' => 'string', 'max' => 100],
        'monto' => ['type' => 'decimal', 'required' => true],
        'moneda' => ['type' => 'currency', 'required' => true],
        'frecuencia' => ['type' => 'enum', 'values' => ['mensual', 'anual'], 'required' => true],
        'fecha_proximo_cobro' => ['type' => 'date', 'required' => true],
        'activa' => ['type' => 'bool', 'notnull' => true],
    ];
}

/** Suma 1 mes o 1 año; si el día no existe en el mes destino, usa el último día. */
function sumar_periodo(string $fecha, string $frecuencia): string
{
    [$anio, $mes, $dia] = array_map('intval', explode('-', $fecha));
    if ($frecuencia === 'anual') {
        $anio++;
    } else {
        $mes++;
        if ($mes > 12) {
            $mes = 1;
            $anio++;
        }
    }
    $ultimo = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes)))->format('t');
    return sprintf('%04d-%02d-%02d', $anio, $mes, min($dia, $ultimo));
}

function suscripcion_publica(array $s): array
{
    $s['dias_restantes'] = dias_entre(hoy(), $s['fecha_proximo_cobro']);
    return $s;
}

function suscripciones_list(array $get): array
{
    $f = filtros($get, ['activa' => ['type' => 'bool']]);
    [$partes, $params] = where_eq($f, ['activa' => 'activa']);
    $sql = 'SELECT * FROM suscripciones' . sql_where($partes) . ' ORDER BY activa DESC, fecha_proximo_cobro, servicio';
    return array_map('suscripcion_publica', q_all($sql, $params));
}

function suscripciones_get(int $id): array
{
    return suscripcion_publica(crud_find('suscripciones', $id));
}

function suscripciones_create(array $input): array
{
    return suscripciones_get(crud_insert('suscripciones', validate($input, suscripciones_schema())));
}

function suscripciones_update(int $id, array $input): array
{
    crud_update('suscripciones', $id, validate($input, suscripciones_schema(), true));
    return suscripciones_get($id);
}

function suscripciones_delete(int $id): void
{
    crud_delete('suscripciones', $id);
}

function suscripciones_pagar(int $id, array $input): array
{
    $s = crud_find('suscripciones', $id);
    if (!(int)$s['activa']) {
        throw new HttpError(409, 'La suscripción está pausada. Activala para registrar pagos.');
    }
    $datos = validate($input, ['fecha' => ['type' => 'date'], 'monto' => ['type' => 'decimal']], true);
    return tx(function () use ($s, $datos, $id): array {
        $gastoId = crud_insert('gastos', [
            'suscripcion_id' => $id,
            'concepto' => $s['servicio'],
            'categoria' => $s['categoria'],
            'monto' => $datos['monto'] ?? $s['monto'],
            'moneda' => $s['moneda'],
            'fecha' => $datos['fecha'] ?? $s['fecha_proximo_cobro'],
        ]);
        crud_update('suscripciones', $id, ['fecha_proximo_cobro' => sumar_periodo($s['fecha_proximo_cobro'], $s['frecuencia'])]);
        return ['gasto' => gastos_get($gastoId), 'suscripcion' => suscripciones_get($id)];
    });
}
```

`admin/includes/repos/gastos.php`:

```php
<?php
declare(strict_types=1);

function gastos_schema(): array
{
    return [
        'suscripcion_id' => ['type' => 'fk', 'table' => 'suscripciones'],
        'concepto' => ['type' => 'string', 'required' => true],
        'categoria' => ['type' => 'string', 'max' => 100],
        'monto' => ['type' => 'decimal', 'required' => true],
        'moneda' => ['type' => 'currency', 'required' => true],
        'fecha' => ['type' => 'date', 'required' => true],
    ];
}

function gastos_list(array $get): array
{
    $f = filtros($get, [
        'desde' => ['type' => 'date'],
        'hasta' => ['type' => 'date'],
        'categoria' => ['type' => 'string', 'max' => 100],
        'suscripcion_id' => ['type' => 'int'],
    ]);
    [$partes, $params] = where_eq($f, ['categoria' => 'g.categoria', 'suscripcion_id' => 'g.suscripcion_id']);
    if (isset($f['desde'])) {
        $partes[] = 'g.fecha >= ?';
        $params[] = $f['desde'];
    }
    if (isset($f['hasta'])) {
        $partes[] = 'g.fecha <= ?';
        $params[] = $f['hasta'];
    }
    $sql = 'SELECT g.*, s.servicio AS suscripcion_servicio
            FROM gastos g LEFT JOIN suscripciones s ON s.id = g.suscripcion_id'
        . sql_where($partes) . ' ORDER BY g.fecha DESC, g.id DESC';
    return q_all($sql, $params);
}

function gastos_get(int $id): array
{
    return crud_find('gastos', $id);
}

function gastos_create(array $input): array
{
    return gastos_get(crud_insert('gastos', validate($input, gastos_schema())));
}

function gastos_update(int $id, array $input): array
{
    crud_update('gastos', $id, validate($input, gastos_schema(), true));
    return gastos_get($id);
}

function gastos_delete(int $id): void
{
    crud_delete('gastos', $id);
}
```

`api/suscripciones.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

if (($_GET['accion'] ?? '') === 'pagar') {
    api_run(function (): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new HttpError(405, 'Método no permitido');
        }
        $id = route_id() ?? throw new HttpError(404, 'No encontrado');
        json_out(201, ['data' => suscripciones_pagar($id, request_json())]);
    });
    return;
}

api_resource([
    'list' => 'suscripciones_list',
    'get' => 'suscripciones_get',
    'create' => 'suscripciones_create',
    'update' => 'suscripciones_update',
    'delete' => 'suscripciones_delete',
]);
```

`api/gastos.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource([
    'list' => 'gastos_list',
    'get' => 'gastos_get',
    'create' => 'gastos_create',
    'update' => 'gastos_update',
    'delete' => 'gastos_delete',
]);
```

- [ ] **Step 4: Correr los tests y confirmar que pasan**

Run: `php vendor/bin/phpunit`
Resultado esperado: `OK`.

- [ ] **Step 5: Commit**

```bash
git add admin/includes/repos/suscripciones.php admin/includes/repos/gastos.php api/suscripciones.php api/gastos.php tests/SuscripcionesTest.php tests/GastosTest.php
git commit -m "$(cat <<'EOF'
Agregar API de suscripciones con registro de pago y gastos

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 14: Gastos y suscripciones (interfaz)

**Files:**
- Create: `admin/assets/mod-gastos.js`, `admin/assets/gastos.js`, `admin/gastos.php`

**Interfaces:**
- Consumes: `Panel.*`, `/api/suscripciones`, `/api/suscripciones/{id}/pagar` y `/api/gastos`.
- Produces:
  - Globales `CATEGORIAS_BASE`, `Suscripciones` y `Gastos`.
  - `Suscripciones` tiene `campos`, `nuevo`, `editar` y `pagar`.
  - `Gastos` tiene `campos`, `nuevo` y `editar`.
  - `nuevo` y `editar` reciben la lista de categorías sugeridas.

- [ ] **Step 1: Crear el módulo**

`admin/assets/mod-gastos.js`:

```js
'use strict';

const CATEGORIAS_BASE = ['Automatización', 'Diseño', 'Dominios', 'Hardware', 'Hosting', 'IA', 'Impuestos', 'Otros', 'Software'];

const Suscripciones = {
  campos(categorias) {
    return [
      { name: 'servicio', label: 'Servicio', required: true, placeholder: 'Claude, n8n, Hostinger…' },
      { name: 'categoria', label: 'Categoría', list: categorias },
      { name: 'monto', label: 'Monto', type: 'money', required: true },
      { name: 'moneda', label: 'Moneda', required: true, default: 'USD', maxlength: 3, list: ['ARS', 'USD'] },
      { name: 'frecuencia', label: 'Frecuencia', type: 'select', options: Panel.opciones(Panel.ETQ.frecuencia) },
      { name: 'fecha_proximo_cobro', label: 'Próximo cobro', type: 'date', required: true },
      { name: 'activa', label: 'Activa', type: 'checkbox', default: true },
    ];
  },
  nuevo(categorias) {
    return Panel.modalForm({ titulo: 'Nueva suscripción', campos: this.campos(categorias), enviar: (d) => Panel.post('/api/suscripciones', d) });
  },
  editar(s, categorias) {
    return Panel.modalForm({
      titulo: 'Editar suscripción', campos: this.campos(categorias), valores: s,
      enviar: (d) => Panel.put(`/api/suscripciones/${s.id}`, d),
      eliminar: () => Panel.del(`/api/suscripciones/${s.id}`),
    });
  },
  pagar(s) {
    return Panel.modalForm({
      titulo: `Registrar pago de ${s.servicio}`,
      textoBoton: 'Registrar pago',
      campos: [
        { name: 'fecha', label: 'Fecha del pago', type: 'date', required: true },
        { name: 'monto', label: `Monto (${s.moneda})`, type: 'money', required: true },
      ],
      valores: { fecha: s.fecha_proximo_cobro, monto: s.monto },
      enviar: (d) => Panel.post(`/api/suscripciones/${s.id}/pagar`, d),
    });
  },
};

const Gastos = {
  campos(categorias) {
    return [
      { name: 'concepto', label: 'Concepto', required: true, placeholder: 'Renovación dominio' },
      { name: 'categoria', label: 'Categoría', list: categorias },
      { name: 'monto', label: 'Monto', type: 'money', required: true },
      { name: 'moneda', label: 'Moneda', required: true, default: 'ARS', maxlength: 3, list: ['ARS', 'USD'] },
      { name: 'fecha', label: 'Fecha', type: 'date', required: true, default: Panel.hoy() },
    ];
  },
  nuevo(categorias) {
    return Panel.modalForm({ titulo: 'Nuevo gasto', campos: this.campos(categorias), enviar: (d) => Panel.post('/api/gastos', d) });
  },
  editar(g, categorias) {
    return Panel.modalForm({
      titulo: 'Editar gasto', campos: this.campos(categorias), valores: g,
      enviar: (d) => Panel.put(`/api/gastos/${g.id}`, d),
      eliminar: () => Panel.del(`/api/gastos/${g.id}`),
    });
  },
};
```

- [ ] **Step 2: Crear la página**

`admin/gastos.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();
layout_start('Gastos', 'gastos', ['mod-gastos.js', 'gastos.js']);
?>
<section>
  <h2>Suscripciones</h2>
  <div id="suscripciones" class="lista seccion"></div>
</section>
<section class="seccion">
  <h2>Gastos</h2>
  <form id="filtros-gastos" class="toolbar seccion">
    <label class="campo">Desde<input type="date" id="filtro-desde" class="filtro"></label>
    <label class="campo">Hasta<input type="date" id="filtro-hasta" class="filtro"></label>
    <label class="campo">Categoría<input id="filtro-categoria" class="filtro" list="categorias" placeholder="Todas"></label>
    <datalist id="categorias"></datalist>
  </form>
  <div id="gastos"></div>
</section>
<?php
layout_end();
```

`admin/assets/gastos.js`:

```js
'use strict';

(() => {
  const { el } = Panel;
  const contSusc = document.getElementById('suscripciones');
  const contGastos = document.getElementById('gastos');
  const fDesde = document.getElementById('filtro-desde');
  const fHasta = document.getElementById('filtro-hasta');
  const fCat = document.getElementById('filtro-categoria');
  const base = Panel.aFecha(Panel.hoy());
  fDesde.value = Panel.iso(new Date(base.getFullYear(), base.getMonth(), 1));
  fHasta.value = Panel.iso(new Date(base.getFullYear(), base.getMonth() + 1, 0));

  let subs = [];
  let gastos = [];
  let categorias = [...CATEGORIAS_BASE];

  function actualizarCategorias() {
    const set = new Set(CATEGORIAS_BASE);
    for (const x of [...subs, ...gastos]) if (x.categoria) set.add(x.categoria);
    categorias = [...set].sort((a, b) => a.localeCompare(b, 'es'));
    Panel.llenar(document.getElementById('categorias'), categorias.map((c) => el('option', { value: c })));
  }

  const recargarTodo = () => Promise.all([cargarSuscripciones(), cargarGastos()]).catch(Panel.manejarError);

  function alerta(s) {
    if (!s.activa) return Panel.badge('Pausada');
    const d = s.dias_restantes;
    if (d < 0) return Panel.badge(`Venció hace ${-d} d`, 'danger');
    if (d === 0) return Panel.badge('Vence hoy', 'warn');
    return Panel.badge(`En ${d} d`, d <= 7 ? 'warn' : '');
  }

  function itemSuscripcion(s) {
    const detalle = [
      Panel.fmtMonto(s.monto, s.moneda),
      Panel.ETQ.frecuencia[s.frecuencia],
      `próximo ${Panel.fmtFecha(s.fecha_proximo_cobro)}`,
      s.categoria,
    ].filter(Boolean).join(' · ');
    return el('div', { class: 'item' },
      el('div', { class: 'item-top' }, el('span', { class: 'item-titulo', text: s.servicio }), alerta(s)),
      el('div', { class: 'item-sub', text: detalle }),
      el('div', { class: 'item-acciones' },
        s.activa ? Panel.boton('Registrar pago', async () => {
          if (await Suscripciones.pagar(s)) {
            Panel.toast('Pago registrado');
            recargarTodo();
          }
        }, 'chico') : null,
        Panel.boton('Editar', async () => { if (await Suscripciones.editar(s, categorias)) recargarTodo(); }, 'chico')));
  }

  async function cargarSuscripciones() {
    subs = await Panel.get('/api/suscripciones');
    actualizarCategorias();
    Panel.llenar(contSusc, subs.length ? subs.map(itemSuscripcion) : Panel.vacio('Todavía no cargaste suscripciones.'));
  }

  function itemGasto(g) {
    return el('button', {
      type: 'button', class: 'item',
      onclick: async () => { if (await Gastos.editar(g, categorias)) cargarGastos().catch(Panel.manejarError); },
    },
      el('div', { class: 'item-top' },
        el('span', { class: 'item-titulo', text: g.concepto }),
        el('span', { class: 'item-titulo', text: Panel.fmtMonto(g.monto, g.moneda) })),
      el('div', { class: 'item-sub', text: [Panel.fmtFecha(g.fecha), g.categoria, g.suscripcion_id ? 'Suscripción' : null].filter(Boolean).join(' · ') }));
  }

  async function cargarGastos() {
    gastos = await Panel.get('/api/gastos', { desde: fDesde.value, hasta: fHasta.value, categoria: fCat.value.trim() });
    actualizarCategorias();
    const suma = {};
    for (const g of gastos) suma[g.moneda] = (suma[g.moneda] || 0) + Number(g.monto);
    const total = Object.entries(suma).map(([m, t]) => Panel.fmtMonto(t, m)).join(' + ');
    Panel.llenar(contGastos,
      gastos.length ? el('p', { class: 'item-sub', text: `Total del período: ${total}` }) : null,
      gastos.length ? el('div', { class: 'lista' }, gastos.map(itemGasto)) : Panel.vacio('No hay gastos en ese período.'));
  }

  Panel.acciones(
    Panel.boton('+ Suscripción', async () => { if (await Suscripciones.nuevo(categorias)) recargarTodo(); }),
    Panel.boton('+ Gasto', async () => { if (await Gastos.nuevo(categorias)) cargarGastos().catch(Panel.manejarError); }, 'primario'));

  for (const input of [fDesde, fHasta, fCat]) input.addEventListener('change', () => cargarGastos().catch(Panel.manejarError));
  document.getElementById('filtros-gastos').addEventListener('submit', (e) => e.preventDefault());
  recargarTodo();
})();
```

- [ ] **Step 3: Verificar en el navegador**

Checklist en `/admin/gastos`:
- [ ] "+ Suscripción" Claude, 20 USD, mensual, con próximo cobro dentro de 3 días: aparece con el badge amarillo "En 3 d".
- [ ] "Registrar pago" propone la fecha del próximo cobro y el monto. Al confirmar aparece "Pago registrado", la próxima fecha avanza un mes y en la lista de gastos aparece "Claude" con "Suscripción", siempre que la fecha caiga en el mes filtrado.
- [ ] Al desactivar "Activa" se ve el badge "Pausada" y desaparece el botón "Registrar pago".
- [ ] "+ Gasto" puntual en ARS: el total del período se muestra separado por moneda (`$ 15.000,00 + US$ 20,00`).
- [ ] Los filtros de fechas y categoría funcionan, y el datalist sugiere las categorías usadas.

- [ ] **Step 4: Commit**

```bash
git add admin/gastos.php admin/assets/mod-gastos.js admin/assets/gastos.js
git commit -m "$(cat <<'EOF'
Agregar pantalla de suscripciones y gastos

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 15: Tareas personales (backend + interfaz)

**Files:**
- Create: `admin/includes/repos/tareas.php`, `api/tareas.php`, `admin/tareas.php`, `admin/assets/tareas.js`
- Test: `tests/TareasTest.php`

**Interfaces:**
- Produces:
  - `TAREA_ESTADOS`
  - `tareas_list(array $get)`:
    - filtros `estado` y `abiertas` (bool: excluye las hechas);
    - orden: las hechas al final; después por fecha de vencimiento (sin fecha al final), después por prioridad (alta primero).
  - `tareas_get`, `tareas_create`, `tareas_update`, `tareas_delete`

- [ ] **Step 1: Escribir el test que falla**

`tests/TareasTest.php`:

```php
<?php
declare(strict_types=1);

final class TareasTest extends DbTestCase
{
    public function test_crear_con_valores_por_defecto_y_validar(): void
    {
        $t = tareas_create(['titulo' => 'Renovar dominio']);
        $this->assertSame('pendiente', $t['estado']);
        $this->assertSame('media', $t['prioridad']);
        $this->assertArrayHasKey('titulo', $this->errores422(fn() => tareas_create(['titulo' => ''])));
        $this->assertArrayHasKey('estado', $this->errores422(fn() => tareas_create(['titulo' => 'x', 'estado' => 'lista'])));
    }

    public function test_orden_y_filtro_de_abiertas(): void
    {
        tareas_create(['titulo' => 'Sin fecha alta', 'prioridad' => 'alta']);
        tareas_create(['titulo' => 'Vence tarde', 'fecha_vencimiento' => '2026-05-20']);
        tareas_create(['titulo' => 'Vence pronto', 'fecha_vencimiento' => '2026-05-01']);
        tareas_create(['titulo' => 'Hecha', 'estado' => 'hecha', 'fecha_vencimiento' => '2026-01-01']);
        tareas_create(['titulo' => 'Sin fecha baja', 'prioridad' => 'baja']);
        $this->assertSame(
            ['Vence pronto', 'Vence tarde', 'Sin fecha alta', 'Sin fecha baja', 'Hecha'],
            array_column(tareas_list([]), 'titulo'));
        $this->assertNotContains('Hecha', array_column(tareas_list(['abiertas' => '1']), 'titulo'));
        $this->assertSame(['Hecha'], array_column(tareas_list(['estado' => 'hecha']), 'titulo'));
    }

    public function test_actualizar_y_borrar(): void
    {
        $t = tareas_create(['titulo' => 'x']);
        $this->assertSame('hecha', tareas_update($t['id'], ['estado' => 'hecha'])['estado']);
        tareas_delete($t['id']);
        $this->assertFalse(crud_exists('tareas_personales', $t['id']));
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `php vendor/bin/phpunit tests/TareasTest.php`
Resultado esperado: error `Call to undefined function tareas_create()`.

- [ ] **Step 3: Implementar el backend**

`admin/includes/repos/tareas.php`:

```php
<?php
declare(strict_types=1);

const TAREA_ESTADOS = ['pendiente', 'en_curso', 'hecha'];

function tareas_schema(): array
{
    return [
        'titulo' => ['type' => 'string', 'required' => true],
        'descripcion' => ['type' => 'text'],
        'estado' => ['type' => 'enum', 'values' => TAREA_ESTADOS, 'notnull' => true],
        'prioridad' => ['type' => 'enum', 'values' => PRIORIDADES, 'notnull' => true],
        'fecha_vencimiento' => ['type' => 'date'],
    ];
}

function tareas_list(array $get): array
{
    $f = filtros($get, [
        'estado' => ['type' => 'enum', 'values' => TAREA_ESTADOS],
        'abiertas' => ['type' => 'bool'],
    ]);
    [$partes, $params] = where_eq($f, ['estado' => 'estado']);
    if (($f['abiertas'] ?? 0) === 1) {
        $partes[] = "estado <> 'hecha'";
    }
    $sql = 'SELECT * FROM tareas_personales' . sql_where($partes)
        . " ORDER BY estado = 'hecha', fecha_vencimiento IS NULL, fecha_vencimiento,
                   FIELD(prioridad, 'alta', 'media', 'baja'), id";
    return q_all($sql, $params);
}

function tareas_get(int $id): array
{
    return crud_find('tareas_personales', $id);
}

function tareas_create(array $input): array
{
    return tareas_get(crud_insert('tareas_personales', validate($input, tareas_schema())));
}

function tareas_update(int $id, array $input): array
{
    crud_update('tareas_personales', $id, validate($input, tareas_schema(), true));
    return tareas_get($id);
}

function tareas_delete(int $id): void
{
    crud_delete('tareas_personales', $id);
}
```

`api/tareas.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource([
    'list' => 'tareas_list',
    'get' => 'tareas_get',
    'create' => 'tareas_create',
    'update' => 'tareas_update',
    'delete' => 'tareas_delete',
]);
```

- [ ] **Step 4: Correr el test y confirmar que pasa**

Run: `php vendor/bin/phpunit`
Resultado esperado: `OK`.

- [ ] **Step 5: Crear la interfaz**

`admin/tareas.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();
layout_start('Tareas', 'tareas', ['tareas.js']);
?>
<div id="tareas"></div>
<?php
layout_end();
```

`admin/assets/tareas.js`:

```js
'use strict';

(() => {
  const { el } = Panel;
  let tabs;
  const recargar = () => tabs.activar(tabs.actual());

  const campos = () => [
    { name: 'titulo', label: 'Título', required: true },
    { name: 'descripcion', label: 'Descripción', type: 'textarea' },
    { name: 'estado', label: 'Estado', type: 'select', options: Panel.opciones(Panel.ETQ.estadoTarea) },
    { name: 'prioridad', label: 'Prioridad', type: 'select', options: Panel.opciones(Panel.ETQ.prioridad), default: 'media' },
    { name: 'fecha_vencimiento', label: 'Vence', type: 'date' },
  ];

  async function editar(t) {
    const r = await Panel.modalForm({
      titulo: 'Editar tarea', campos: campos(), valores: t,
      enviar: (d) => Panel.put(`/api/tareas/${t.id}`, d),
      eliminar: () => Panel.del(`/api/tareas/${t.id}`),
    });
    if (r) recargar();
  }

  function item(t) {
    const hecha = t.estado === 'hecha';
    const check = el('input', { type: 'checkbox', checked: hecha, 'aria-label': `Marcar "${t.titulo}" como hecha` });
    check.addEventListener('change', async () => {
      try {
        await Panel.put(`/api/tareas/${t.id}`, { estado: check.checked ? 'hecha' : 'pendiente' });
        recargar();
      } catch (err) {
        check.checked = !check.checked;
        Panel.manejarError(err);
      }
    });
    const dias = t.fecha_vencimiento ? Panel.diasHasta(t.fecha_vencimiento) : null;
    let variante = '';
    if (!hecha && dias !== null && dias < 0) variante = 'danger';
    else if (!hecha && dias !== null && dias <= 2) variante = 'warn';
    return el('div', { class: `check-item${hecha ? ' hecha' : ''}` },
      check,
      el('button', { type: 'button', class: 'texto boton-texto', onclick: () => editar(t) },
        el('span', { class: 'item-titulo', text: t.titulo }),
        el('span', { class: 'meta' },
          t.estado === 'en_curso' ? Panel.badgeEstado('estadoTarea', 'en_curso') : null,
          t.prioridad !== 'media' ? Panel.badgeEstado('prioridad', t.prioridad) : null,
          t.fecha_vencimiento ? Panel.badge(`Vence ${Panel.fmtFecha(t.fecha_vencimiento)}`, variante) : null)));
  }

  const render = (filtro, textoVacio) => async (panel) => {
    const tareas = await Panel.get('/api/tareas', filtro);
    Panel.llenar(panel, tareas.length ? el('div', { class: 'lista' }, tareas.map(item)) : Panel.vacio(textoVacio));
  };

  Panel.acciones(Panel.boton('+ Nueva tarea', async () => {
    const r = await Panel.modalForm({ titulo: 'Nueva tarea', campos: campos(), enviar: (d) => Panel.post('/api/tareas', d) });
    if (r) recargar();
  }, 'primario'));

  tabs = Panel.tabs(document.getElementById('tareas'), [
    { id: 'abiertas', label: 'Abiertas', render: render({ abiertas: 1 }, 'No tenés tareas abiertas.') },
    { id: 'hechas', label: 'Hechas', render: render({ estado: 'hecha' }, 'Todavía no completaste tareas.') },
    { id: 'todas', label: 'Todas', render: render({}, 'Todavía no cargaste tareas.') },
  ]);
})();
```

- [ ] **Step 6: Verificar en el navegador**

Checklist en `/admin/tareas`:
- [ ] Al crear 3 tareas con distintas fechas y prioridades, en "Abiertas" aparecen ordenadas por vencimiento.
- [ ] Una tarea vencida ayer muestra el badge rojo.
- [ ] Al tildarla desaparece de "Abiertas" y aparece tachada en "Hechas". Al destildarla vuelve.
- [ ] Tocar el texto abre el modal de edición; Eliminar pide confirmación.

- [ ] **Step 7: Commit**

```bash
git add admin/includes/repos/tareas.php api/tareas.php admin/tareas.php admin/assets/tareas.js tests/TareasTest.php
git commit -m "$(cat <<'EOF'
Agregar lista de tareas personales

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 16: Fixs (backend)

**Files:**
- Create: `admin/includes/repos/fixs.php`, `api/fixs.php`
- Test: `tests/FixsTest.php`

**Interfaces:**
- Consumes: `validar_proceso_de_cliente()`, `reordenar()`, `siguiente_orden()`, `hoy()`.
- Produces:
  - `FIX_ESTADOS`
  - `fixs_list(array $get)`: filtros `cliente_id` y `estado`. Cada fila trae `cliente_nombre` y `proceso_titulo`.
  - `fixs_get`, `fixs_create`, `fixs_update` (acepta `antes_de`), `fixs_delete`.
- Reglas:
  - Si `fecha_reportado` falta al crear, se usa hoy.
  - Al quedar en 'resuelto' con `fecha_resuelto` vacía, se completa con hoy.
  - En cualquier otro estado, `fecha_resuelto` queda `NULL`.

- [ ] **Step 1: Escribir el test que falla**

`tests/FixsTest.php`:

```php
<?php
declare(strict_types=1);

final class FixsTest extends DbTestCase
{
    private int $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        clock_set('2026-06-15');
        $this->cliente = clientes_create(['nombre' => 'Acme'])['id'];
    }

    private function fix(string $titulo, array $extra = []): array
    {
        return fixs_create($extra + ['cliente_id' => $this->cliente, 'titulo' => $titulo]);
    }

    private function titulos(string $estado): array
    {
        return array_column(fixs_list(['estado' => $estado]), 'titulo');
    }

    public function test_crear_con_valores_por_defecto(): void
    {
        $f = $this->fix('Botón roto en mobile');
        $this->assertSame('reportado', $f['estado']);
        $this->assertSame('2026-06-15', $f['fecha_reportado']);
        $this->assertNull($f['fecha_resuelto']);
        $this->assertSame('Acme', $f['cliente_nombre']);
        $this->assertSame(0, $f['orden']);
    }

    public function test_resolver_y_reabrir(): void
    {
        $f = $this->fix('X');
        $this->assertSame('2026-06-15', fixs_update($f['id'], ['estado' => 'resuelto'])['fecha_resuelto']);
        clock_set('2026-06-20');
        $this->assertSame('2026-06-15', fixs_update($f['id'], ['titulo' => 'X2'])['fecha_resuelto']);
        $this->assertNull(fixs_update($f['id'], ['estado' => 'en_progreso'])['fecha_resuelto']);
        $this->assertSame('2026-06-15', $this->fix('Y', ['estado' => 'resuelto', 'fecha_resuelto' => '2026-06-15'])['fecha_resuelto']);
        $this->assertSame('2026-06-20', $this->fix('Z', ['estado' => 'resuelto'])['fecha_resuelto']);
    }

    public function test_proceso_de_otro_cliente_falla(): void
    {
        $otro = clientes_create(['nombre' => 'Otro'])['id'];
        $ajeno = procesos_create(['cliente_id' => $otro, 'titulo' => 'Ajeno']);
        $this->assertArrayHasKey('proceso_id', $this->errores422(fn() => $this->fix('X', ['proceso_id' => $ajeno['id']])));
        $propio = procesos_create(['cliente_id' => $this->cliente, 'titulo' => 'Landing']);
        $f = $this->fix('X', ['proceso_id' => $propio['id']]);
        $this->assertSame('Landing', $f['proceso_titulo']);
        $this->assertArrayHasKey('proceso_id', $this->errores422(fn() => fixs_update($f['id'], ['cliente_id' => $otro])));
    }

    public function test_mover_con_antes_de_y_cambiar_columna(): void
    {
        $otro = clientes_create(['nombre' => 'Otro'])['id'];
        $a = $this->fix('A');
        $this->fix('B', ['cliente_id' => $otro]);
        $c = $this->fix('C');
        fixs_update($c['id'], ['antes_de' => $a['id']]);
        $this->assertSame(['C', 'A', 'B'], $this->titulos('reportado'));
        fixs_update($a['id'], ['estado' => 'en_progreso']);
        $this->assertSame(['C', 'B'], $this->titulos('reportado'));
        $this->assertSame(['A'], $this->titulos('en_progreso'));
        $this->assertSame(['C', 'A'], array_column(fixs_list(['cliente_id' => (string)$this->cliente]), 'titulo'));
    }

    public function test_validaciones_y_borrado(): void
    {
        $campos = $this->errores422(fn() => fixs_create(['titulo' => '']));
        $this->assertArrayHasKey('cliente_id', $campos);
        $this->assertArrayHasKey('titulo', $campos);
        $f = $this->fix('X');
        fixs_delete($f['id']);
        $this->assertFalse(crud_exists('fixs', $f['id']));
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `php vendor/bin/phpunit tests/FixsTest.php`
Resultado esperado: error `Call to undefined function fixs_create()`.

- [ ] **Step 3: Implementar**

`admin/includes/repos/fixs.php`:

```php
<?php
declare(strict_types=1);

const FIX_ESTADOS = ['reportado', 'en_progreso', 'resuelto'];

const FIXS_SELECT = "SELECT f.*, c.nombre AS cliente_nombre, p.titulo AS proceso_titulo
    FROM fixs f
    JOIN clientes c ON c.id = f.cliente_id
    LEFT JOIN procesos p ON p.id = f.proceso_id";

function fixs_schema(bool $alta): array
{
    $schema = [
        'cliente_id' => ['type' => 'fk', 'table' => 'clientes', 'required' => true],
        'proceso_id' => ['type' => 'fk', 'table' => 'procesos'],
        'titulo' => ['type' => 'string', 'required' => true],
        'descripcion' => ['type' => 'text'],
        'estado' => ['type' => 'enum', 'values' => FIX_ESTADOS, 'notnull' => true],
        'prioridad' => ['type' => 'enum', 'values' => PRIORIDADES, 'notnull' => true],
        'fecha_reportado' => ['type' => 'date', 'notnull' => true],
        'fecha_resuelto' => ['type' => 'date'],
    ];
    if (!$alta) {
        $schema['antes_de'] = ['type' => 'int', 'min' => 1];
    }
    return $schema;
}

function fixs_list(array $get): array
{
    $f = filtros($get, [
        'cliente_id' => ['type' => 'int'],
        'estado' => ['type' => 'enum', 'values' => FIX_ESTADOS],
    ]);
    [$partes, $params] = where_eq($f, ['cliente_id' => 'f.cliente_id', 'estado' => 'f.estado']);
    $sql = FIXS_SELECT . sql_where($partes)
        . " ORDER BY FIELD(f.estado, 'reportado', 'en_progreso', 'resuelto'), f.orden, f.id";
    return q_all($sql, $params);
}

function fixs_get(int $id): array
{
    $f = q_one(FIXS_SELECT . ' WHERE f.id = ?', [$id]);
    if ($f === null) {
        throw new HttpError(404, 'No encontrado');
    }
    return $f;
}

function fixs_normalizar(array $datos, ?array $actual): array
{
    $clienteId = $datos['cliente_id'] ?? ($actual['cliente_id'] ?? null);
    $procesoId = array_key_exists('proceso_id', $datos) ? $datos['proceso_id'] : ($actual['proceso_id'] ?? null);
    validar_proceso_de_cliente($procesoId === null ? null : (int)$procesoId, $clienteId === null ? null : (int)$clienteId);

    $estado = $datos['estado'] ?? ($actual['estado'] ?? 'reportado');
    if ($estado === 'resuelto') {
        $resuelto = array_key_exists('fecha_resuelto', $datos) ? $datos['fecha_resuelto'] : ($actual['fecha_resuelto'] ?? null);
        $datos['fecha_resuelto'] = $resuelto ?? hoy();
    } else {
        $datos['fecha_resuelto'] = null;
    }
    return $datos;
}

function fixs_create(array $input): array
{
    $datos = fixs_normalizar(validate($input, fixs_schema(true)), null);
    $datos['fecha_reportado'] ??= hoy();
    $datos['orden'] = siguiente_orden('fixs', 'estado', $datos['estado'] ?? 'reportado');
    return fixs_get(crud_insert('fixs', $datos));
}

function fixs_update(int $id, array $input): array
{
    $actual = crud_find('fixs', $id);
    $datos = validate($input, fixs_schema(false), true);
    $mover = array_key_exists('antes_de', $datos);
    $antesDe = $datos['antes_de'] ?? null;
    unset($datos['antes_de']);
    $datos = fixs_normalizar($datos, $actual);
    $estado = $datos['estado'] ?? $actual['estado'];
    tx(function () use ($id, $datos, $mover, $antesDe, $estado, $actual): void {
        crud_update('fixs', $id, $datos);
        if ($mover || $estado !== $actual['estado']) {
            reordenar('fixs', $id, 'estado', $estado, $antesDe);
        }
    });
    return fixs_get($id);
}

function fixs_delete(int $id): void
{
    crud_delete('fixs', $id);
}
```

`api/fixs.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource([
    'list' => 'fixs_list',
    'get' => 'fixs_get',
    'create' => 'fixs_create',
    'update' => 'fixs_update',
    'delete' => 'fixs_delete',
]);
```

- [ ] **Step 4: Correr el test y confirmar que pasa**

Run: `php vendor/bin/phpunit`
Resultado esperado: `OK`.

- [ ] **Step 5: Commit**

```bash
git add admin/includes/repos/fixs.php api/fixs.php tests/FixsTest.php
git commit -m "$(cat <<'EOF'
Agregar API de fixs post-entrega con fechas automáticas

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 17: Fixs (interfaz: tablero tipo Trello)

**Files:**
- Create: `admin/assets/mod-fixs.js`, `admin/assets/fixs.js`, `admin/fixs.php`

**Interfaces:**
- Consumes: `kanbanBoard` (Task 10), `Panel.*`, `/api/fixs` y `/api/procesos`.
- Produces: objeto global `Fixs` con `COLUMNAS`, `campos`, `nuevo(valores)`, `editar(f)`, `selectorEstado(f, alCambiar)`, `resumen(f)`, `tarjeta(f, recargar)` e `item(f, recargar)`.

- [ ] **Step 1: Crear el módulo**

`admin/assets/mod-fixs.js`:

```js
'use strict';

const Fixs = {
  COLUMNAS: Object.entries(Panel.ETQ.estadoFix),

  campos(clientes, procesos) {
    return [
      { name: 'cliente_id', label: 'Cliente', type: 'select', required: true, vacio: 'Elegí un cliente', options: Panel.opcionesClientes(clientes) },
      { name: 'proceso_id', label: 'Proceso entregado (opcional)', type: 'select', vacio: 'Sin proceso', filtro: 'cliente_id', options: procesos.map((p) => [String(p.id), p.titulo, p.cliente_id]) },
      { name: 'titulo', label: 'Qué falla', required: true, placeholder: 'El formulario no envía en Safari' },
      { name: 'descripcion', label: 'Detalle', type: 'textarea' },
      { name: 'estado', label: 'Estado', type: 'select', options: Panel.opciones(Panel.ETQ.estadoFix) },
      { name: 'prioridad', label: 'Prioridad', type: 'select', options: Panel.opciones(Panel.ETQ.prioridad), default: 'media' },
      { name: 'fecha_reportado', label: 'Reportado el', type: 'date', default: Panel.hoy() },
    ];
  },

  async datos() {
    const [clientes, procesos] = await Promise.all([Panel.clientes(), Panel.get('/api/procesos')]);
    return { clientes, procesos };
  },

  async nuevo(valores = {}) {
    const { clientes, procesos } = await this.datos();
    return Panel.modalForm({ titulo: 'Nuevo fix', campos: this.campos(clientes, procesos), valores, enviar: (d) => Panel.post('/api/fixs', d) });
  },

  async editar(f) {
    const { clientes, procesos } = await this.datos();
    return Panel.modalForm({
      titulo: 'Editar fix', campos: this.campos(clientes, procesos), valores: f,
      enviar: (d) => Panel.put(`/api/fixs/${f.id}`, d),
      eliminar: () => Panel.del(`/api/fixs/${f.id}`),
    });
  },

  selectorEstado(f, alCambiar) {
    const s = Panel.el('select', { class: 'filtro', 'aria-label': `Estado de ${f.titulo}` },
      this.COLUMNAS.map(([v, l]) => Panel.el('option', { value: v, text: l })));
    s.value = f.estado;
    s.addEventListener('change', async () => {
      try {
        await Panel.put(`/api/fixs/${f.id}`, { estado: s.value });
        alCambiar();
      } catch (err) {
        s.value = f.estado;
        Panel.manejarError(err);
      }
    });
    return s;
  },

  resumen(f) {
    return Panel.el('div', { class: 'meta' },
      f.prioridad !== 'media' ? Panel.badgeEstado('prioridad', f.prioridad) : null,
      Panel.badge(`Reportado ${Panel.fmtFecha(f.fecha_reportado)}`),
      f.fecha_resuelto ? Panel.badge(`Resuelto ${Panel.fmtFecha(f.fecha_resuelto)}`, 'ok') : null);
  },

  tarjeta(f, recargar) {
    const { el } = Panel;
    return [
      el('button', { type: 'button', class: 'boton-texto item-titulo', text: f.titulo, onclick: async () => { if (await Fixs.editar(f)) recargar(); } }),
      el('div', { class: 'item-sub', text: [f.cliente_nombre, f.proceso_titulo].filter(Boolean).join(' · ') }),
      this.resumen(f),
      this.selectorEstado(f, recargar),
    ];
  },

  item(f, recargar) {
    return Panel.el('div', { class: 'item' }, this.tarjeta(f, recargar));
  },
};
```

- [ ] **Step 2: Crear la página**

`admin/fixs.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();
layout_start('Fixs', 'fixs', ['vendor/sortable.min.js', 'kanban.js', 'mod-fixs.js', 'fixs.js']);
?>
<div class="toolbar">
  <select class="filtro" id="filtro-cliente" aria-label="Filtrar por cliente">
    <option value="">Todos los clientes</option>
  </select>
</div>
<div id="tablero" aria-live="polite"></div>
<?php
layout_end();
```

`admin/assets/fixs.js`:

```js
'use strict';

(() => {
  const { el } = Panel;
  const tablero = document.getElementById('tablero');
  const fCliente = document.getElementById('filtro-cliente');
  const params = new URLSearchParams(location.search);

  Panel.acciones(Panel.boton('+ Nuevo fix', async () => {
    if (await Fixs.nuevo({ cliente_id: fCliente.value || undefined })) cargar();
  }, 'primario'));

  async function mover(id, estado, antesDe) {
    try {
      await Panel.put(`/api/fixs/${id}`, { estado, antes_de: antesDe });
    } catch (err) {
      Panel.manejarError(err);
    }
    cargar();
  }

  async function cargar() {
    history.replaceState(null, '', location.pathname + Panel.qs({ cliente_id: fCliente.value }));
    try {
      const fixs = await Panel.get('/api/fixs', { cliente_id: fCliente.value });
      kanbanBoard(tablero, { columnas: Fixs.COLUMNAS, items: fixs, tarjeta: (f) => Fixs.tarjeta(f, cargar), alMover: mover });
    } catch (err) {
      Panel.manejarError(err);
    }
  }

  Panel.clientes().then((clientes) => {
    fCliente.append(...clientes.map((c) => el('option', { value: String(c.id), text: c.nombre })));
    fCliente.value = params.get('cliente_id') || '';
    fCliente.addEventListener('change', cargar);
    return cargar();
  }).catch(Panel.manejarError);
})();
```

- [ ] **Step 3: Verificar en el navegador**

Checklist en `/admin/fixs`:
- [ ] Nuevo fix: al elegir el cliente, el selector de proceso se filtra. Aparece en "Reportado" con la fecha de hoy.
- [ ] Al arrastrarlo a "Resuelto" aparece el badge verde "Resuelto <hoy>". Al volverlo a "En progreso", el badge desaparece.
- [ ] El filtro por cliente funciona y el orden arrastrado se mantiene al recargar.
- [ ] Tocar el título abre la edición.

- [ ] **Step 4: Commit**

```bash
git add admin/fixs.php admin/assets/mod-fixs.js admin/assets/fixs.js
git commit -m "$(cat <<'EOF'
Agregar tablero de fixs post-entrega

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 18: Eventos y agenda (backend)

**Files:**
- Create: `admin/includes/repos/eventos.php`, `admin/includes/repos/agenda.php`
- Create: `api/eventos.php`, `api/agenda.php`
- Test: `tests/EventosTest.php`, `tests/AgendaTest.php`

**Interfaces:**
- Produces:
  - `EVENTO_TIPOS`
  - `eventos_list(array $get)`: filtros `desde` y `hasta` (el día `hasta` entra completo) y `cliente_id`. Cada fila trae `cliente_nombre`.
  - `eventos_get`, `eventos_create`, `eventos_update`, `eventos_delete`
  - `agenda_items(array $get)`:
    - `desde` y `hasta` son obligatorios; el rango máximo es de 93 días.
    - Devuelve una lista de `{ tipo, fecha, hora, titulo, ref, ... }`, con `tipo` ∈ `evento`, `cobro`, `suscripcion`, `entrega`, `tarea`.
    - Campos extra: `subtipo` y `cliente_nombre` (evento); `monto`, `moneda`, `cliente_id` y `vencido` (cobro); `monto` y `moneda` (suscripción); `cliente_nombre` (entrega).
    - Orden: fecha, después los ítems sin hora, después por hora, después evento < entrega < cobro < suscripcion < tarea.

- [ ] **Step 1: Escribir los tests que fallan**

`tests/EventosTest.php`:

```php
<?php
declare(strict_types=1);

final class EventosTest extends DbTestCase
{
    public function test_crear_con_formato_de_input_y_sin_cliente(): void
    {
        $e = eventos_create(['titulo' => 'Llamada con Acme', 'fecha_hora' => '2026-05-10T15:30', 'tipo' => 'llamada']);
        $this->assertSame('2026-05-10 15:30:00', $e['fecha_hora']);
        $this->assertNull($e['cliente_id']);
        $this->assertNull($e['cliente_nombre']);
        $this->assertSame('otro', eventos_create(['titulo' => 'x', 'fecha_hora' => '2026-05-10 09:00'])['tipo']);
    }

    public function test_validaciones(): void
    {
        $campos = $this->errores422(fn() => eventos_create(['duracion_min' => '0', 'cliente_id' => 999999]));
        foreach (['titulo', 'fecha_hora', 'duracion_min', 'cliente_id'] as $campo) {
            $this->assertArrayHasKey($campo, $campos);
        }
    }

    public function test_list_incluye_el_dia_hasta_completo(): void
    {
        $c = clientes_create(['nombre' => 'Acme'])['id'];
        eventos_create(['titulo' => 'A', 'fecha_hora' => '2026-05-09 23:59']);
        eventos_create(['titulo' => 'B', 'fecha_hora' => '2026-05-10 00:00', 'cliente_id' => $c]);
        eventos_create(['titulo' => 'C', 'fecha_hora' => '2026-05-11 23:59']);
        eventos_create(['titulo' => 'D', 'fecha_hora' => '2026-05-12 00:00']);
        $this->assertSame(['B', 'C'], array_column(eventos_list(['desde' => '2026-05-10', 'hasta' => '2026-05-11']), 'titulo'));
        $this->assertSame(['B'], array_column(eventos_list(['cliente_id' => (string)$c]), 'titulo'));
    }

    public function test_actualizar_y_borrar(): void
    {
        $e = eventos_create(['titulo' => 'x', 'fecha_hora' => '2026-05-10 09:00']);
        $this->assertSame(45, eventos_update($e['id'], ['duracion_min' => '45'])['duracion_min']);
        eventos_delete($e['id']);
        $this->assertFalse(crud_exists('eventos', $e['id']));
    }
}
```

`tests/AgendaTest.php`:

```php
<?php
declare(strict_types=1);

final class AgendaTest extends DbTestCase
{
    public function test_valida_el_rango(): void
    {
        $campos = $this->errores422(fn() => agenda_items([]));
        $this->assertArrayHasKey('desde', $campos);
        $this->assertArrayHasKey('hasta', $campos);
        $this->assertArrayHasKey('hasta', $this->errores422(fn() => agenda_items(['desde' => '2026-05-10', 'hasta' => '2026-05-01'])));
        $this->assertArrayHasKey('hasta', $this->errores422(fn() => agenda_items(['desde' => '2026-01-01', 'hasta' => '2026-04-04'])));
        $this->assertSame([], agenda_items(['desde' => '2026-01-01', 'hasta' => '2026-04-03']));
    }

    public function test_combina_todas_las_fuentes_en_orden(): void
    {
        clock_set('2026-05-11');
        $c = clientes_create(['nombre' => 'Acme'])['id'];
        eventos_create(['titulo' => 'Reunión', 'fecha_hora' => '2026-05-10 15:00', 'cliente_id' => $c, 'tipo' => 'reunion']);
        cobros_create(['cliente_id' => $c, 'monto' => '100', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-05-10', 'concepto' => 'Saldo']);
        cobros_create(['cliente_id' => $c, 'monto' => '100', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-05-10', 'estado' => 'pagado']);
        suscripciones_create(['servicio' => 'n8n', 'monto' => '24', 'moneda' => 'USD', 'frecuencia' => 'mensual', 'fecha_proximo_cobro' => '2026-05-12']);
        suscripciones_create(['servicio' => 'Pausada', 'monto' => '1', 'moneda' => 'USD', 'frecuencia' => 'mensual', 'fecha_proximo_cobro' => '2026-05-12', 'activa' => false]);
        procesos_create(['cliente_id' => $c, 'titulo' => 'Landing', 'fecha_entrega_estimada' => '2026-05-11']);
        procesos_create(['cliente_id' => $c, 'titulo' => 'Entregado', 'estado' => 'entregado', 'fecha_entrega_estimada' => '2026-05-11']);
        tareas_create(['titulo' => 'Renovar dominio', 'fecha_vencimiento' => '2026-05-12']);
        tareas_create(['titulo' => 'Hecha', 'estado' => 'hecha', 'fecha_vencimiento' => '2026-05-12']);
        eventos_create(['titulo' => 'Fuera de rango', 'fecha_hora' => '2026-06-01 10:00']);

        $items = agenda_items(['desde' => '2026-05-01', 'hasta' => '2026-05-31']);

        $this->assertSame(['cobro', 'evento', 'entrega', 'suscripcion', 'tarea'], array_column($items, 'tipo'));
        $this->assertSame(['Acme · Saldo', 'Reunión', 'Landing', 'n8n', 'Renovar dominio'], array_column($items, 'titulo'));
        $this->assertTrue($items[0]['vencido']);
        $this->assertSame('100.00', $items[0]['monto']);
        $this->assertSame('15:00', $items[1]['hora']);
        $this->assertNull($items[0]['hora']);
        $this->assertSame('Acme', $items[2]['cliente_nombre']);
        $this->assertIsInt($items[1]['ref']);
    }
}
```

- [ ] **Step 2: Correr los tests y confirmar que fallan**

Run: `php vendor/bin/phpunit tests/EventosTest.php tests/AgendaTest.php`
Resultado esperado: error `Call to undefined function eventos_create()`.

- [ ] **Step 3: Implementar**

`admin/includes/repos/eventos.php`:

```php
<?php
declare(strict_types=1);

const EVENTO_TIPOS = ['reunion', 'llamada', 'recordatorio', 'otro'];

const EVENTOS_SELECT = 'SELECT e.*, c.nombre AS cliente_nombre FROM eventos e LEFT JOIN clientes c ON c.id = e.cliente_id';

function eventos_schema(): array
{
    return [
        'cliente_id' => ['type' => 'fk', 'table' => 'clientes'],
        'titulo' => ['type' => 'string', 'required' => true],
        'descripcion' => ['type' => 'text'],
        'fecha_hora' => ['type' => 'datetime', 'required' => true],
        'duracion_min' => ['type' => 'int', 'min' => 1, 'max' => 1440],
        'tipo' => ['type' => 'enum', 'values' => EVENTO_TIPOS, 'notnull' => true],
    ];
}

function dia_siguiente(string $fecha): string
{
    return (new DateTimeImmutable($fecha))->modify('+1 day')->format('Y-m-d');
}

function eventos_list(array $get): array
{
    $f = filtros($get, [
        'desde' => ['type' => 'date'],
        'hasta' => ['type' => 'date'],
        'cliente_id' => ['type' => 'int'],
    ]);
    [$partes, $params] = where_eq($f, ['cliente_id' => 'e.cliente_id']);
    if (isset($f['desde'])) {
        $partes[] = 'e.fecha_hora >= ?';
        $params[] = $f['desde'] . ' 00:00:00';
    }
    if (isset($f['hasta'])) {
        $partes[] = 'e.fecha_hora < ?';
        $params[] = dia_siguiente($f['hasta']) . ' 00:00:00';
    }
    return q_all(EVENTOS_SELECT . sql_where($partes) . ' ORDER BY e.fecha_hora, e.id', $params);
}

function eventos_get(int $id): array
{
    $e = q_one(EVENTOS_SELECT . ' WHERE e.id = ?', [$id]);
    if ($e === null) {
        throw new HttpError(404, 'No encontrado');
    }
    return $e;
}

function eventos_create(array $input): array
{
    return eventos_get(crud_insert('eventos', validate($input, eventos_schema())));
}

function eventos_update(int $id, array $input): array
{
    crud_update('eventos', $id, validate($input, eventos_schema(), true));
    return eventos_get($id);
}

function eventos_delete(int $id): void
{
    crud_delete('eventos', $id);
}
```

`admin/includes/repos/agenda.php`:

```php
<?php
declare(strict_types=1);

const AGENDA_ORDEN_TIPO = ['evento' => 0, 'entrega' => 1, 'cobro' => 2, 'suscripcion' => 3, 'tarea' => 4];

function agenda_items(array $get): array
{
    $f = filtros($get, [
        'desde' => ['type' => 'date', 'required' => true],
        'hasta' => ['type' => 'date', 'required' => true],
    ]);
    $desde = $f['desde'];
    $hasta = $f['hasta'];
    if ($hasta < $desde) {
        throw new HttpError(422, 'Revisá las fechas', ['hasta' => 'Tiene que ser igual o posterior a "desde"']);
    }
    if (dias_entre($desde, $hasta) > 92) {
        throw new HttpError(422, 'El rango máximo es de 93 días', ['hasta' => 'Rango demasiado largo']);
    }
    $hoy = hoy();
    $items = [];

    $eventos = q_all(
        'SELECT e.id, e.titulo, e.fecha_hora, e.tipo, c.nombre AS cliente_nombre
         FROM eventos e LEFT JOIN clientes c ON c.id = e.cliente_id
         WHERE e.fecha_hora >= ? AND e.fecha_hora < ?',
        [$desde . ' 00:00:00', dia_siguiente($hasta) . ' 00:00:00']
    );
    foreach ($eventos as $e) {
        $items[] = [
            'tipo' => 'evento', 'fecha' => substr($e['fecha_hora'], 0, 10), 'hora' => substr($e['fecha_hora'], 11, 5),
            'titulo' => $e['titulo'], 'subtipo' => $e['tipo'], 'cliente_nombre' => $e['cliente_nombre'], 'ref' => (int)$e['id'],
        ];
    }

    $cobros = q_all(
        "SELECT c.id, c.fecha_vencimiento, c.monto, c.moneda, c.concepto, c.cliente_id, cl.nombre AS cliente_nombre
         FROM cobros c JOIN clientes cl ON cl.id = c.cliente_id
         WHERE c.estado = 'pendiente' AND c.fecha_vencimiento BETWEEN ? AND ?",
        [$desde, $hasta]
    );
    foreach ($cobros as $c) {
        $items[] = [
            'tipo' => 'cobro', 'fecha' => $c['fecha_vencimiento'], 'hora' => null,
            'titulo' => $c['cliente_nombre'] . ($c['concepto'] ? ' · ' . $c['concepto'] : ''),
            'monto' => $c['monto'], 'moneda' => $c['moneda'], 'cliente_id' => (int)$c['cliente_id'],
            'vencido' => $c['fecha_vencimiento'] < $hoy, 'ref' => (int)$c['id'],
        ];
    }

    $suscripciones = q_all(
        'SELECT id, servicio, monto, moneda, fecha_proximo_cobro FROM suscripciones
         WHERE activa = 1 AND fecha_proximo_cobro BETWEEN ? AND ?',
        [$desde, $hasta]
    );
    foreach ($suscripciones as $s) {
        $items[] = [
            'tipo' => 'suscripcion', 'fecha' => $s['fecha_proximo_cobro'], 'hora' => null,
            'titulo' => $s['servicio'], 'monto' => $s['monto'], 'moneda' => $s['moneda'], 'ref' => (int)$s['id'],
        ];
    }

    $entregas = q_all(
        "SELECT p.id, p.titulo, p.fecha_entrega_estimada, c.nombre AS cliente_nombre
         FROM procesos p JOIN clientes c ON c.id = p.cliente_id
         WHERE p.estado <> 'entregado' AND p.fecha_entrega_estimada BETWEEN ? AND ?",
        [$desde, $hasta]
    );
    foreach ($entregas as $p) {
        $items[] = [
            'tipo' => 'entrega', 'fecha' => $p['fecha_entrega_estimada'], 'hora' => null,
            'titulo' => $p['titulo'], 'cliente_nombre' => $p['cliente_nombre'], 'ref' => (int)$p['id'],
        ];
    }

    $tareas = q_all(
        "SELECT id, titulo, fecha_vencimiento FROM tareas_personales
         WHERE estado <> 'hecha' AND fecha_vencimiento BETWEEN ? AND ?",
        [$desde, $hasta]
    );
    foreach ($tareas as $t) {
        $items[] = ['tipo' => 'tarea', 'fecha' => $t['fecha_vencimiento'], 'hora' => null, 'titulo' => $t['titulo'], 'ref' => (int)$t['id']];
    }

    usort($items, fn(array $a, array $b) =>
        [$a['fecha'], $a['hora'] ?? '', AGENDA_ORDEN_TIPO[$a['tipo']]]
        <=> [$b['fecha'], $b['hora'] ?? '', AGENDA_ORDEN_TIPO[$b['tipo']]]);
    return $items;
}
```

`api/eventos.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource([
    'list' => 'eventos_list',
    'get' => 'eventos_get',
    'create' => 'eventos_create',
    'update' => 'eventos_update',
    'delete' => 'eventos_delete',
]);
```

`api/agenda.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource(['list' => 'agenda_items']);
```

- [ ] **Step 4: Correr los tests y confirmar que pasan**

Run: `php vendor/bin/phpunit`
Resultado esperado: `OK`.

- [ ] **Step 5: Commit**

```bash
git add admin/includes/repos/eventos.php admin/includes/repos/agenda.php api/eventos.php api/agenda.php tests/EventosTest.php tests/AgendaTest.php
git commit -m "$(cat <<'EOF'
Agregar eventos y feed unificado de agenda con vencimientos

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 19: Agenda (interfaz: calendario mensual y lista móvil)

**Files:**
- Create: `admin/assets/mod-eventos.js`, `admin/assets/agenda.js`, `admin/agenda.php`

**Interfaces:**
- Consumes: `/api/agenda`, `/api/eventos` y `Panel.*`.
- Produces: objeto global `Eventos` con `campos(clientes)`, `nuevo(valores)`, `editar(e)` e `item(e, recargar)`.

- [ ] **Step 1: Crear el módulo de eventos**

`admin/assets/mod-eventos.js`:

```js
'use strict';

const Eventos = {
  campos(clientes) {
    return [
      { name: 'titulo', label: 'Título', required: true, placeholder: 'Llamada con…' },
      { name: 'tipo', label: 'Tipo', type: 'select', options: Panel.opciones(Panel.ETQ.tipoEvento) },
      { name: 'fecha_hora', label: 'Fecha y hora', type: 'datetime-local', required: true },
      { name: 'duracion_min', label: 'Duración (minutos)', type: 'number', min: 1, max: 1440 },
      { name: 'cliente_id', label: 'Cliente', type: 'select', vacio: 'Sin cliente', options: Panel.opcionesClientes(clientes) },
      { name: 'descripcion', label: 'Notas', type: 'textarea' },
    ];
  },
  async nuevo(valores = {}) {
    const clientes = await Panel.clientes();
    return Panel.modalForm({ titulo: 'Nuevo evento', campos: this.campos(clientes), valores, enviar: (d) => Panel.post('/api/eventos', d) });
  },
  async editar(e) {
    const clientes = await Panel.clientes();
    return Panel.modalForm({
      titulo: 'Editar evento', campos: this.campos(clientes), valores: e,
      enviar: (d) => Panel.put(`/api/eventos/${e.id}`, d),
      eliminar: () => Panel.del(`/api/eventos/${e.id}`),
    });
  },
  item(e, recargar) {
    const { el } = Panel;
    const detalle = [Panel.fmtFechaHora(e.fecha_hora), e.duracion_min ? `${e.duracion_min} min` : null, e.cliente_nombre].filter(Boolean).join(' · ');
    return el('button', { type: 'button', class: 'item', onclick: async () => { if (await Eventos.editar(e)) recargar(); } },
      el('div', { class: 'item-top' }, el('span', { class: 'item-titulo', text: e.titulo }), Panel.badge(Panel.ETQ.tipoEvento[e.tipo])),
      el('div', { class: 'item-sub', text: detalle }));
  },
};
```

- [ ] **Step 2: Crear la página**

`admin/agenda.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();
layout_start('Agenda', 'agenda', ['mod-eventos.js', 'agenda.js']);
?>
<div class="cal-nav">
  <button type="button" class="btn" id="mes-anterior" aria-label="Mes anterior">‹</button>
  <h2 id="mes-titulo" aria-live="polite"></h2>
  <button type="button" class="btn" id="mes-siguiente" aria-label="Mes siguiente">›</button>
  <button type="button" class="btn" id="mes-hoy">Hoy</button>
</div>
<div id="cal-grid" class="cal-grid"></div>
<div id="cal-lista" class="cal-lista"></div>
<div class="leyenda" aria-hidden="true">
  <span class="t-evento">Evento</span>
  <span class="t-entrega">Entrega</span>
  <span class="t-cobro">Cobro</span>
  <span class="t-cobro vencido">Cobro vencido</span>
  <span class="t-suscripcion">Suscripción</span>
  <span class="t-tarea">Tarea</span>
</div>
<?php
layout_end();
```

`admin/assets/agenda.js`:

```js
'use strict';

(() => {
  const { el } = Panel;
  const grid = document.getElementById('cal-grid');
  const lista = document.getElementById('cal-lista');
  const titulo = document.getElementById('mes-titulo');
  const hoyIso = Panel.hoy();
  const base = Panel.aFecha(hoyIso);
  let anio = base.getFullYear();
  let mes = base.getMonth();
  const PREFIJO = { evento: '', cobro: 'Cobro: ', suscripcion: 'Pago: ', entrega: 'Entrega: ', tarea: 'Tarea: ' };

  Panel.acciones(Panel.boton('+ Evento', () => nuevoEvento(hoyIso), 'primario'));

  async function nuevoEvento(fecha) {
    if (await Eventos.nuevo({ fecha_hora: `${fecha} 10:00` })) cargar();
  }

  function rango() {
    const primero = new Date(anio, mes, 1);
    const inicio = new Date(anio, mes, 1 - ((primero.getDay() + 6) % 7));
    const ultimo = new Date(anio, mes + 1, 0);
    const fin = new Date(anio, mes, ultimo.getDate() + (6 - ((ultimo.getDay() + 6) % 7)));
    return { inicio, fin };
  }

  function textoItem(i) {
    let t = PREFIJO[i.tipo] + i.titulo;
    if (i.monto) t += ` · ${Panel.fmtMonto(i.monto, i.moneda)}`;
    if (i.tipo === 'entrega' && i.cliente_nombre) t += ` (${i.cliente_nombre})`;
    return t;
  }

  async function abrir(i) {
    if (i.tipo === 'evento') {
      const ev = await Panel.get(`/api/eventos/${i.ref}`);
      if (await Eventos.editar(ev)) cargar();
    } else if (i.tipo === 'cobro') {
      location.href = `/admin/cobros?cliente_id=${i.cliente_id}#${i.vencido ? 'vencidos' : 'pendientes'}`;
    } else if (i.tipo === 'suscripcion') {
      location.href = '/admin/gastos';
    } else if (i.tipo === 'entrega') {
      location.href = `/admin/procesos/${i.ref}`;
    } else if (i.tipo === 'tarea') {
      location.href = '/admin/tareas';
    }
  }

  function botonItem(i) {
    const texto = textoItem(i);
    return el('button', {
      type: 'button', class: `cal-item t-${i.tipo}${i.vencido ? ' vencido' : ''}`, title: texto,
      onclick: () => abrir(i).catch(Panel.manejarError),
    },
      i.hora ? el('span', { class: 'hora', text: i.hora }) : null,
      el('span', { class: 'titulo', text: texto }));
  }

  function renderGrid(inicio, fin, porDia) {
    const celdas = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'].map((d) => el('div', { class: 'cab', text: d }));
    for (let d = new Date(inicio); d <= fin; d.setDate(d.getDate() + 1)) {
      const f = Panel.iso(d);
      const clases = ['cal-celda'];
      if (d.getMonth() !== mes) clases.push('fuera');
      if (f === hoyIso) clases.push('hoy');
      celdas.push(el('div', { class: clases.join(' ') },
        el('button', { type: 'button', class: 'num-dia', text: String(d.getDate()), 'aria-label': `Nuevo evento el ${Panel.fmtFecha(f, true)}`, onclick: () => nuevoEvento(f) }),
        (porDia[f] || []).map(botonItem)));
    }
    Panel.llenar(grid, celdas);
  }

  function renderLista(porDia) {
    const prefijoMes = `${anio}-${String(mes + 1).padStart(2, '0')}`;
    const dias = Object.keys(porDia).filter((f) => f.startsWith(prefijoMes)).sort();
    if (!dias.length) {
      Panel.llenar(lista, Panel.vacio('No hay nada agendado este mes.'));
      return;
    }
    Panel.llenar(lista, dias.map((f) => el('section', { class: `cal-dia${f === hoyIso ? ' hoy' : ''}` },
      el('h3', { text: Panel.aFecha(f).toLocaleDateString('es-AR', { weekday: 'long', day: 'numeric', month: 'long' }) }),
      porDia[f].map(botonItem))));
  }

  async function cargar() {
    const { inicio, fin } = rango();
    titulo.textContent = new Date(anio, mes, 1).toLocaleDateString('es-AR', { month: 'long', year: 'numeric' });
    try {
      const items = await Panel.get('/api/agenda', { desde: Panel.iso(inicio), hasta: Panel.iso(fin) });
      const porDia = {};
      for (const i of items) (porDia[i.fecha] ||= []).push(i);
      renderGrid(inicio, fin, porDia);
      renderLista(porDia);
    } catch (err) {
      Panel.manejarError(err);
    }
  }

  document.getElementById('mes-anterior').addEventListener('click', () => {
    mes -= 1;
    if (mes < 0) { mes = 11; anio -= 1; }
    cargar();
  });
  document.getElementById('mes-siguiente').addEventListener('click', () => {
    mes += 1;
    if (mes > 11) { mes = 0; anio += 1; }
    cargar();
  });
  document.getElementById('mes-hoy').addEventListener('click', () => {
    anio = base.getFullYear();
    mes = base.getMonth();
    cargar();
  });
  cargar();
})();
```

- [ ] **Step 3: Verificar en el navegador**

Checklist en `/admin/agenda`:
- [ ] En desktop se ve la grilla de lunes a domingo. El día de hoy está resaltado y los días de otros meses aparecen en gris.
- [ ] Tocar el número de un día abre "Nuevo evento" con esa fecha a las 10:00. Al guardarlo aparece en la celda con la hora.
- [ ] En el celular (390px) se ve la lista por día, solo con los días que tienen ítems y el nombre del día en español.
- [ ] Un cobro pendiente, una suscripción, una entrega estimada y una tarea con vencimiento aparecen con el color correcto según la leyenda. Un cobro vencido aparece en rojo.
- [ ] Tocar un ítem lleva a la sección correspondiente; un evento abre su edición.
- [ ] Las flechas ‹ › cambian de mes y "Hoy" vuelve al mes actual.

- [ ] **Step 4: Commit**

```bash
git add admin/agenda.php admin/assets/mod-eventos.js admin/assets/agenda.js
git commit -m "$(cat <<'EOF'
Agregar agenda con calendario mensual y vista de lista móvil

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 20: Dashboard (backend + interfaz)

**Files:**
- Create: `admin/includes/repos/dashboard.php`, `api/dashboard.php`, `admin/assets/dashboard.js`
- Modify: `admin/index.php` (se reemplaza completo)
- Test: `tests/DashboardTest.php`

**Interfaces:**
- Consumes: `cobros_select()`, `cobro_publico()`, `suscripcion_publica()`, `hoy()`.
- Produces:
  - `balance_por_moneda(string $desde, string $hasta, ?int $clienteId = null, bool $porMes = false): array`
    - Devuelve filas `{ [mes], moneda, ingresos, gastos, balance }` como strings decimales.
    - Los ingresos son los cobros pagados, según `fecha_pago`.
    - Con `clienteId`, los gastos quedan en `"0.00"` porque no se asignan a clientes.
    - La Task 21 reutiliza esta función.
  - `dashboard_resumen(): array` con las claves:
    - `mes`
    - `balance_mes`
    - `cobros_pendientes` (hasta 10)
    - `cobros_pendientes_totales` (`[{ moneda, vencido, por_vencer }]`)
    - `suscripciones_proximas` (activas con fecha ≤ hoy + 14 días, incluidas las atrasadas)
    - `procesos_por_cliente` (`[{ cliente_id, cliente_nombre, procesos: [...] }]`, solo los no entregados)
    - `tareas_abiertas` (int), `fixs_abiertos` (int)
    - `proximos_eventos` (hasta 5, desde hoy a las 00:00)

- [ ] **Step 1: Escribir el test que falla**

`tests/DashboardTest.php`:

```php
<?php
declare(strict_types=1);

final class DashboardTest extends DbTestCase
{
    public function test_balance_por_moneda_y_por_mes(): void
    {
        $c = clientes_create(['nombre' => 'A'])['id'];
        $otro = clientes_create(['nombre' => 'B'])['id'];
        cobros_create(['cliente_id' => $c, 'monto' => '1000', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-05-01', 'estado' => 'pagado', 'fecha_pago' => '2026-05-03']);
        cobros_create(['cliente_id' => $otro, 'monto' => '50', 'moneda' => 'USD', 'fecha_vencimiento' => '2026-05-01', 'estado' => 'pagado', 'fecha_pago' => '2026-06-02']);
        cobros_create(['cliente_id' => $c, 'monto' => '999', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-05-01']);
        gastos_create(['concepto' => 'Hosting', 'monto' => '300', 'moneda' => 'ARS', 'fecha' => '2026-05-10']);
        gastos_create(['concepto' => 'Claude', 'monto' => '20', 'moneda' => 'USD', 'fecha' => '2026-05-10']);

        $this->assertSame([
            ['moneda' => 'ARS', 'ingresos' => '1000.00', 'gastos' => '300.00', 'balance' => '700.00'],
            ['moneda' => 'USD', 'ingresos' => '0.00', 'gastos' => '20.00', 'balance' => '-20.00'],
        ], balance_por_moneda('2026-05-01', '2026-05-31'));

        $this->assertSame([
            ['mes' => '2026-05', 'moneda' => 'ARS', 'ingresos' => '1000.00', 'gastos' => '300.00', 'balance' => '700.00'],
            ['mes' => '2026-05', 'moneda' => 'USD', 'ingresos' => '0.00', 'gastos' => '20.00', 'balance' => '-20.00'],
            ['mes' => '2026-06', 'moneda' => 'USD', 'ingresos' => '50.00', 'gastos' => '0.00', 'balance' => '50.00'],
        ], balance_por_moneda('2026-05-01', '2026-06-30', null, true));

        $this->assertSame([
            ['moneda' => 'ARS', 'ingresos' => '1000.00', 'gastos' => '0.00', 'balance' => '1000.00'],
        ], balance_por_moneda('2026-05-01', '2026-06-30', $c));
    }

    public function test_resumen(): void
    {
        clock_set('2026-05-15');
        $c = clientes_create(['nombre' => 'Acme'])['id'];
        cobros_create(['cliente_id' => $c, 'monto' => '200', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-05-01']);
        cobros_create(['cliente_id' => $c, 'monto' => '500', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-06-01']);
        cobros_create(['cliente_id' => $c, 'monto' => '800', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-05-01', 'estado' => 'pagado', 'fecha_pago' => '2026-05-02']);
        suscripciones_create(['servicio' => 'Atrasada', 'monto' => '1', 'moneda' => 'USD', 'frecuencia' => 'mensual', 'fecha_proximo_cobro' => '2026-05-01']);
        suscripciones_create(['servicio' => 'Pronto', 'monto' => '1', 'moneda' => 'USD', 'frecuencia' => 'mensual', 'fecha_proximo_cobro' => '2026-05-29']);
        suscripciones_create(['servicio' => 'Lejos', 'monto' => '1', 'moneda' => 'USD', 'frecuencia' => 'mensual', 'fecha_proximo_cobro' => '2026-05-30']);
        suscripciones_create(['servicio' => 'Pausada', 'monto' => '1', 'moneda' => 'USD', 'frecuencia' => 'mensual', 'fecha_proximo_cobro' => '2026-05-20', 'activa' => false]);
        procesos_create(['cliente_id' => $c, 'titulo' => 'Landing', 'estado' => 'en_curso']);
        procesos_create(['cliente_id' => $c, 'titulo' => 'Viejo', 'estado' => 'entregado']);
        tareas_create(['titulo' => 'a']);
        tareas_create(['titulo' => 'b', 'estado' => 'hecha']);
        fixs_create(['cliente_id' => $c, 'titulo' => 'bug']);
        eventos_create(['titulo' => 'Ayer', 'fecha_hora' => '2026-05-14 10:00']);
        eventos_create(['titulo' => 'Hoy temprano', 'fecha_hora' => '2026-05-15 08:00']);

        $r = dashboard_resumen();

        $this->assertSame('2026-05', $r['mes']);
        $this->assertSame([['moneda' => 'ARS', 'ingresos' => '800.00', 'gastos' => '0.00', 'balance' => '800.00']], $r['balance_mes']);
        $this->assertCount(2, $r['cobros_pendientes']);
        $this->assertSame('vencido', $r['cobros_pendientes'][0]['estado_efectivo']);
        $this->assertSame([['moneda' => 'ARS', 'vencido' => '200.00', 'por_vencer' => '500.00']], $r['cobros_pendientes_totales']);
        $this->assertSame(['Atrasada', 'Pronto'], array_column($r['suscripciones_proximas'], 'servicio'));
        $this->assertSame(-14, $r['suscripciones_proximas'][0]['dias_restantes']);
        $this->assertCount(1, $r['procesos_por_cliente']);
        $this->assertSame('Acme', $r['procesos_por_cliente'][0]['cliente_nombre']);
        $this->assertSame(['Landing'], array_column($r['procesos_por_cliente'][0]['procesos'], 'titulo'));
        $this->assertSame(1, $r['tareas_abiertas']);
        $this->assertSame(1, $r['fixs_abiertos']);
        $this->assertSame(['Hoy temprano'], array_column($r['proximos_eventos'], 'titulo'));
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `php vendor/bin/phpunit tests/DashboardTest.php`
Resultado esperado: error `Call to undefined function balance_por_moneda()`.

- [ ] **Step 3: Implementar el backend**

`admin/includes/repos/dashboard.php`:

```php
<?php
declare(strict_types=1);

function balance_por_moneda(string $desde, string $hasta, ?int $clienteId = null, bool $porMes = false): array
{
    $mesIngreso = $porMes ? "DATE_FORMAT(fecha_pago, '%Y-%m') AS mes, " : '';
    $mesGasto = $porMes ? "DATE_FORMAT(fecha, '%Y-%m') AS mes, " : '';
    $cero = 'CAST(0 AS DECIMAL(12,2))';

    $union = "SELECT {$mesIngreso}moneda, monto AS ingresos, $cero AS gastos
              FROM cobros WHERE estado = 'pagado' AND fecha_pago BETWEEN ? AND ?";
    $params = [$desde, $hasta];
    if ($clienteId !== null) {
        $union .= ' AND cliente_id = ?';
        $params[] = $clienteId;
    } else {
        $union .= " UNION ALL SELECT {$mesGasto}moneda, $cero, monto FROM gastos WHERE fecha BETWEEN ? AND ?";
        array_push($params, $desde, $hasta);
    }
    $grupo = $porMes ? 'mes, moneda' : 'moneda';
    $sql = 'SELECT ' . ($porMes ? 'mes, ' : '') . "moneda,
                   SUM(ingresos) AS ingresos, SUM(gastos) AS gastos, SUM(ingresos) - SUM(gastos) AS balance
            FROM ($union) t GROUP BY $grupo ORDER BY $grupo";
    return q_all($sql, $params);
}

function dashboard_resumen(): array
{
    $hoy = hoy();
    $inicioMes = substr($hoy, 0, 8) . '01';
    $finMes = (new DateTimeImmutable($inicioMes))->format('Y-m-t');
    $en14 = (new DateTimeImmutable($hoy))->modify('+14 days')->format('Y-m-d');

    $procesos = q_all(
        "SELECT p.id, p.titulo, p.estado, p.prioridad, p.fecha_entrega_estimada, c.id AS cliente_id, c.nombre AS cliente_nombre
         FROM procesos p JOIN clientes c ON c.id = p.cliente_id
         WHERE p.estado <> 'entregado'
         ORDER BY c.nombre, c.id, FIELD(p.estado, 'por_hacer', 'en_curso', 'en_revision'), p.orden, p.id"
    );
    $porCliente = [];
    foreach ($procesos as $p) {
        $cid = (int)$p['cliente_id'];
        $porCliente[$cid] ??= ['cliente_id' => $cid, 'cliente_nombre' => $p['cliente_nombre'], 'procesos' => []];
        unset($p['cliente_id'], $p['cliente_nombre']);
        $porCliente[$cid]['procesos'][] = $p;
    }

    return [
        'mes' => substr($hoy, 0, 7),
        'balance_mes' => balance_por_moneda($inicioMes, $finMes),
        'cobros_pendientes' => array_map('cobro_publico', q_all(
            cobros_select() . " WHERE c.estado = 'pendiente' ORDER BY c.fecha_vencimiento, c.id LIMIT 10",
            [$hoy]
        )),
        'cobros_pendientes_totales' => q_all(
            "SELECT moneda,
                    SUM(CASE WHEN fecha_vencimiento < ? THEN monto ELSE 0 END) AS vencido,
                    SUM(CASE WHEN fecha_vencimiento >= ? THEN monto ELSE 0 END) AS por_vencer
             FROM cobros WHERE estado = 'pendiente' GROUP BY moneda ORDER BY moneda",
            [$hoy, $hoy]
        ),
        'suscripciones_proximas' => array_map('suscripcion_publica', q_all(
            'SELECT * FROM suscripciones WHERE activa = 1 AND fecha_proximo_cobro <= ? ORDER BY fecha_proximo_cobro, servicio',
            [$en14]
        )),
        'procesos_por_cliente' => array_values($porCliente),
        'tareas_abiertas' => (int)q_val("SELECT COUNT(*) FROM tareas_personales WHERE estado <> 'hecha'"),
        'fixs_abiertos' => (int)q_val("SELECT COUNT(*) FROM fixs WHERE estado <> 'resuelto'"),
        'proximos_eventos' => q_all(EVENTOS_SELECT . ' WHERE e.fecha_hora >= ? ORDER BY e.fecha_hora, e.id LIMIT 5', [$hoy . ' 00:00:00']),
    ];
}
```

`api/dashboard.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource(['list' => fn(array $get) => dashboard_resumen()]);
```

- [ ] **Step 4: Correr el test y confirmar que pasa**

Run: `php vendor/bin/phpunit`
Resultado esperado: `OK`. Si la comparación de `balance_por_moneda` falla solo por el formato (por ejemplo `"1000.0000"`), cambiá `SUM(...)` por `CAST(SUM(...) AS DECIMAL(14,2))` en las tres columnas y volvé a correr: MariaDB y MySQL pueden devolver distinta escala en `UNION` + `SUM`.

- [ ] **Step 5: Reemplazar la portada con el dashboard**

`admin/index.php` (reemplazo completo):

```php
<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();
layout_start('Inicio', 'inicio', ['dashboard.js']);
?>
<div id="dashboard" aria-live="polite"><p class="item-sub">Cargando…</p></div>
<?php
layout_end();
```

`admin/assets/dashboard.js`:

```js
'use strict';

(() => {
  const { el } = Panel;
  const cont = document.getElementById('dashboard');

  const seccion = (titulo, link, ...contenido) => el('section', { class: 'seccion' },
    el('div', { class: 'item-top' }, el('h2', { text: titulo }), link ? el('a', { href: link[0], text: link[1] }) : null),
    contenido);

  const stat = (label, valor, href) => el('a', { class: 'stat', href },
    el('div', { class: 'stat-label', text: label }),
    el('div', { class: 'stat-valor', text: String(valor) }));

  function tarjetaBalance(b) {
    return el('div', { class: 'stat' },
      el('div', { class: 'stat-label', text: `Balance del mes · ${b.moneda}` }),
      el('div', { class: `stat-valor${Number(b.balance) < 0 ? ' negativo' : ''}`, text: Panel.fmtMonto(b.balance, b.moneda) }),
      el('div', { class: 'item-sub', text: `Ingresos ${Panel.fmtMonto(b.ingresos, b.moneda)} · Gastos ${Panel.fmtMonto(b.gastos, b.moneda)}` }));
  }

  function cobros(d) {
    const totales = d.cobros_pendientes_totales.map((t) =>
      el('p', { class: 'item-sub', text: `${t.moneda}: vencido ${Panel.fmtMonto(t.vencido, t.moneda)} · por vencer ${Panel.fmtMonto(t.por_vencer, t.moneda)}` }));
    const items = d.cobros_pendientes.map((c) => el('a', {
      class: 'item', href: `/admin/cobros?cliente_id=${c.cliente_id}#${c.estado_efectivo === 'vencido' ? 'vencidos' : 'pendientes'}`,
    },
      el('div', { class: 'item-top' },
        el('span', { class: 'item-titulo', text: Panel.fmtMonto(c.monto, c.moneda) }),
        Panel.badgeEstado('estadoCobro', c.estado_efectivo)),
      el('div', { class: 'item-sub', text: `${c.cliente_nombre} · vence ${Panel.fmtFecha(c.fecha_vencimiento)}` })));
    return seccion('Cobros pendientes', ['/admin/cobros', 'Ver todos'],
      totales, el('div', { class: 'lista' }, items.length ? items : Panel.vacio('No hay cobros pendientes.')));
  }

  function vencimientos(d) {
    const items = d.suscripciones_proximas.map((s) => {
      const dias = s.dias_restantes;
      let cuando = `en ${dias} d`;
      if (dias < 0) cuando = `atrasada ${-dias} d`;
      else if (dias === 0) cuando = 'hoy';
      return el('a', { class: 'item', href: '/admin/gastos' },
        el('div', { class: 'item-top' },
          el('span', { class: 'item-titulo', text: s.servicio }),
          Panel.badge(cuando, dias < 0 ? 'danger' : (dias <= 7 ? 'warn' : ''))),
        el('div', { class: 'item-sub', text: `${Panel.fmtMonto(s.monto, s.moneda)} · ${Panel.fmtFecha(s.fecha_proximo_cobro)}` }));
    });
    return seccion('Próximos vencimientos', ['/admin/gastos', 'Ver gastos'],
      el('div', { class: 'lista' }, items.length ? items : Panel.vacio('Nada vence en los próximos 14 días.')));
  }

  function procesos(d) {
    const bloques = d.procesos_por_cliente.map((c) => el('div', { class: 'item' },
      el('a', { class: 'item-titulo', href: `/admin/clientes/${c.cliente_id}`, text: c.cliente_nombre }),
      c.procesos.map((p) => el('div', { class: 'item-top' },
        el('a', { href: `/admin/procesos/${p.id}`, text: p.titulo }),
        Panel.badgeEstado('estadoProceso', p.estado)))));
    return seccion('Procesos activos por cliente', ['/admin/procesos', 'Ver tablero'],
      el('div', { class: 'lista' }, bloques.length ? bloques : Panel.vacio('No hay procesos activos.')));
  }

  function eventos(d) {
    const items = d.proximos_eventos.map((e) => el('a', { class: 'item', href: '/admin/agenda' },
      el('span', { class: 'item-titulo', text: e.titulo }),
      el('span', { class: 'item-sub', text: [Panel.fmtFechaHora(e.fecha_hora), e.cliente_nombre].filter(Boolean).join(' · ') })));
    return seccion('Próximos eventos', ['/admin/agenda', 'Ver agenda'],
      el('div', { class: 'lista' }, items.length ? items : Panel.vacio('No hay eventos agendados.')));
  }

  async function cargar() {
    const d = await Panel.get('/api/dashboard');
    const mesTexto = Panel.aFecha(`${d.mes}-01`).toLocaleDateString('es-AR', { month: 'long', year: 'numeric' });
    const activos = d.procesos_por_cliente.reduce((n, c) => n + c.procesos.length, 0);
    Panel.llenar(cont,
      el('p', { class: 'item-sub', text: `Resumen de ${mesTexto}` }),
      el('div', { class: 'stats' },
        d.balance_mes.length ? d.balance_mes.map(tarjetaBalance) : el('div', { class: 'stat' }, el('div', { class: 'stat-label', text: 'Todavía no hay ingresos ni gastos este mes.' }))),
      el('div', { class: 'stats' },
        stat('Procesos activos', activos, '/admin/procesos'),
        stat('Tareas abiertas', d.tareas_abiertas, '/admin/tareas'),
        stat('Fixs abiertos', d.fixs_abiertos, '/admin/fixs')),
      el('div', { class: 'grid-2' }, cobros(d), vencimientos(d), procesos(d), eventos(d)));
  }

  cargar().catch(Panel.manejarError);
})();
```

- [ ] **Step 6: Verificar en el navegador**

Checklist en `/admin` con datos cargados de las tasks anteriores:
- [ ] Hay una tarjeta de balance por moneda (ARS y USD por separado) con ingresos y gastos. Un balance negativo se ve en rojo.
- [ ] Los contadores de procesos, tareas y fixs llevan a su sección.
- [ ] Los cobros pendientes muestran primero los vencidos, con los totales "vencido · por vencer".
- [ ] Una suscripción atrasada muestra el badge rojo.
- [ ] En el celular (390px) todo queda en una columna y sin scroll horizontal.

- [ ] **Step 7: Commit**

```bash
git add admin/includes/repos/dashboard.php api/dashboard.php admin/index.php admin/assets/dashboard.js tests/DashboardTest.php
git commit -m "$(cat <<'EOF'
Agregar dashboard con balance del mes, vencimientos y procesos activos

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 21: Reportes (backend + interfaz)

**Files:**
- Create: `admin/includes/repos/reportes.php`, `api/reportes.php`, `admin/reportes.php`, `admin/assets/reportes.js`
- Test: `tests/ReportesTest.php`

**Interfaces:**
- Consumes: `balance_por_moneda()` (Task 20).
- Produces: `reporte_balance(array $get): array`, que devuelve:
  - `desde`, `hasta`, `cliente_id`
  - `incluye_gastos` (bool)
  - `meses`: `[{ mes, monedas: [{ moneda, ingresos, [gastos], balance }] }]`, con **todos** los meses del rango, aunque estén vacíos.
  - `totales`: `[{ moneda, ingresos, [gastos], balance }]`

  Con `cliente_id`, las filas no traen la clave `gastos`. Se accede por `GET /api/reportes/balance`.

- [ ] **Step 1: Escribir el test que falla**

`tests/ReportesTest.php`:

```php
<?php
declare(strict_types=1);

final class ReportesTest extends DbTestCase
{
    public function test_valida_parametros(): void
    {
        $campos = $this->errores422(fn() => reporte_balance([]));
        $this->assertArrayHasKey('desde', $campos);
        $this->assertArrayHasKey('hasta', $campos);
        $this->assertArrayHasKey('hasta', $this->errores422(fn() => reporte_balance(['desde' => '2026-05-01', 'hasta' => '2026-04-01'])));
        $this->assertArrayHasKey('cliente_id', $this->errores422(fn() => reporte_balance(['desde' => '2026-01-01', 'hasta' => '2026-02-01', 'cliente_id' => '999999'])));
    }

    public function test_meses_completos_y_totales(): void
    {
        $c = clientes_create(['nombre' => 'A'])['id'];
        cobros_create(['cliente_id' => $c, 'monto' => '1000', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-01-01', 'estado' => 'pagado', 'fecha_pago' => '2026-01-10']);
        gastos_create(['concepto' => 'Hosting', 'monto' => '100', 'moneda' => 'ARS', 'fecha' => '2026-03-05']);

        $r = reporte_balance(['desde' => '2026-01-15', 'hasta' => '2026-03-31']);

        $this->assertTrue($r['incluye_gastos']);
        $this->assertSame(['2026-01', '2026-02', '2026-03'], array_column($r['meses'], 'mes'));
        $this->assertSame([], $r['meses'][0]['monedas']);
        $this->assertSame([], $r['meses'][1]['monedas']);
        $this->assertSame([['moneda' => 'ARS', 'ingresos' => '0.00', 'gastos' => '100.00', 'balance' => '-100.00']], $r['meses'][2]['monedas']);
        $this->assertSame([['moneda' => 'ARS', 'ingresos' => '0.00', 'gastos' => '100.00', 'balance' => '-100.00']], $r['totales']);
    }

    public function test_por_cliente_omite_gastos(): void
    {
        $a = clientes_create(['nombre' => 'A'])['id'];
        $b = clientes_create(['nombre' => 'B'])['id'];
        cobros_create(['cliente_id' => $a, 'monto' => '1000', 'moneda' => 'ARS', 'fecha_vencimiento' => '2026-01-01', 'estado' => 'pagado', 'fecha_pago' => '2026-01-10']);
        cobros_create(['cliente_id' => $b, 'monto' => '7', 'moneda' => 'USD', 'fecha_vencimiento' => '2026-01-01', 'estado' => 'pagado', 'fecha_pago' => '2026-01-11']);
        gastos_create(['concepto' => 'Hosting', 'monto' => '100', 'moneda' => 'ARS', 'fecha' => '2026-01-05']);

        $r = reporte_balance(['desde' => '2026-01-01', 'hasta' => '2026-01-31', 'cliente_id' => (string)$a]);

        $this->assertFalse($r['incluye_gastos']);
        $this->assertSame($a, $r['cliente_id']);
        $this->assertSame([['moneda' => 'ARS', 'ingresos' => '1000.00', 'balance' => '1000.00']], $r['meses'][0]['monedas']);
        $this->assertSame([['moneda' => 'ARS', 'ingresos' => '1000.00', 'balance' => '1000.00']], $r['totales']);
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `php vendor/bin/phpunit tests/ReportesTest.php`
Resultado esperado: error `Call to undefined function reporte_balance()`.

- [ ] **Step 3: Implementar el backend**

`admin/includes/repos/reportes.php`:

```php
<?php
declare(strict_types=1);

function reporte_balance(array $get): array
{
    $f = filtros($get, [
        'desde' => ['type' => 'date', 'required' => true],
        'hasta' => ['type' => 'date', 'required' => true],
        'cliente_id' => ['type' => 'fk', 'table' => 'clientes'],
    ]);
    if ($f['hasta'] < $f['desde']) {
        throw new HttpError(422, 'Revisá las fechas', ['hasta' => 'Tiene que ser igual o posterior a "desde"']);
    }
    if (dias_entre($f['desde'], $f['hasta']) > 366 * 5) {
        throw new HttpError(422, 'El rango máximo es de 5 años', ['hasta' => 'Rango demasiado largo']);
    }
    $cliente = $f['cliente_id'] ?? null;
    $sinGastos = fn(array $fila) => $cliente === null ? $fila : array_diff_key($fila, ['gastos' => true]);

    $meses = [];
    $cursor = new DateTimeImmutable(substr($f['desde'], 0, 7) . '-01');
    $ultimo = substr($f['hasta'], 0, 7);
    while ($cursor->format('Y-m') <= $ultimo) {
        $meses[$cursor->format('Y-m')] = ['mes' => $cursor->format('Y-m'), 'monedas' => []];
        $cursor = $cursor->modify('+1 month');
    }
    foreach (balance_por_moneda($f['desde'], $f['hasta'], $cliente, true) as $fila) {
        $mes = $fila['mes'];
        unset($fila['mes']);
        $meses[$mes]['monedas'][] = $sinGastos($fila);
    }

    return [
        'desde' => $f['desde'],
        'hasta' => $f['hasta'],
        'cliente_id' => $cliente,
        'incluye_gastos' => $cliente === null,
        'meses' => array_values($meses),
        'totales' => array_map($sinGastos, balance_por_moneda($f['desde'], $f['hasta'], $cliente)),
    ];
}
```

`api/reportes.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource(['list' => 'reporte_balance']);
```

- [ ] **Step 4: Correr el test y confirmar que pasa**

Run: `php vendor/bin/phpunit`
Resultado esperado: `OK`.

- [ ] **Step 5: Crear la interfaz**

`admin/reportes.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();
layout_start('Reportes', 'reportes', ['reportes.js']);
?>
<form id="filtros" class="toolbar">
  <label class="campo">Desde<input type="date" id="desde" class="filtro" required></label>
  <label class="campo">Hasta<input type="date" id="hasta" class="filtro" required></label>
  <label class="campo">Cliente
    <select id="cliente" class="filtro"><option value="">Todos los clientes</option></select>
  </label>
  <button type="submit" class="btn btn-primario">Ver reporte</button>
</form>
<div id="reporte" aria-live="polite"></div>
<?php
layout_end();
```

`admin/assets/reportes.js`:

```js
'use strict';

(() => {
  const { el } = Panel;
  const form = document.getElementById('filtros');
  const fDesde = document.getElementById('desde');
  const fHasta = document.getElementById('hasta');
  const fCliente = document.getElementById('cliente');
  const cont = document.getElementById('reporte');
  const params = new URLSearchParams(location.search);
  const base = Panel.aFecha(Panel.hoy());
  fDesde.value = params.get('desde') || Panel.iso(new Date(base.getFullYear(), base.getMonth() - 5, 1));
  fHasta.value = params.get('hasta') || Panel.iso(new Date(base.getFullYear(), base.getMonth() + 1, 0));

  function statMonto(label, valor, moneda) {
    return el('div', { class: 'stat' },
      el('div', { class: 'stat-label', text: label }),
      el('div', { class: `stat-valor${Number(valor) < 0 ? ' negativo' : ''}`, text: Panel.fmtMonto(valor, moneda) }));
  }

  function fila(f, moneda, max, conGastos) {
    const mesTexto = Panel.aFecha(`${f.mes}-01`).toLocaleDateString('es-AR', { month: 'short', year: 'numeric' });
    const barraIng = el('div', { class: 'barra barra-ing' });
    barraIng.style.width = `${(Number(f.ingresos) / max) * 100}%`;
    let barraGas = null;
    if (conGastos) {
      barraGas = el('div', { class: 'barra barra-gas' });
      barraGas.style.width = `${(Number(f.gastos) / max) * 100}%`;
    }
    return el('tr', {},
      el('td', { 'data-label': 'Mes', text: mesTexto }),
      el('td', { class: 'num', 'data-label': 'Ingresos', text: Panel.fmtMonto(f.ingresos, moneda) }),
      conGastos ? el('td', { class: 'num', 'data-label': 'Gastos', text: Panel.fmtMonto(f.gastos, moneda) }) : null,
      el('td', { class: `num${Number(f.balance) < 0 ? ' negativo' : ''}`, 'data-label': 'Balance', text: Panel.fmtMonto(f.balance, moneda) }),
      el('td', { 'data-label': 'Gráfico' }, el('div', { class: 'barras', 'aria-hidden': 'true' }, barraIng, barraGas)));
  }

  function bloqueMoneda(r, moneda) {
    const conGastos = r.incluye_gastos;
    const filas = r.meses.map((m) => ({
      mes: m.mes,
      ...(m.monedas.find((x) => x.moneda === moneda) || { ingresos: '0', gastos: '0', balance: '0' }),
    }));
    const max = Math.max(1, ...filas.map((f) => Math.max(Number(f.ingresos), Number(f.gastos || 0))));
    const total = r.totales.find((t) => t.moneda === moneda);
    const cabeza = ['Mes', 'Ingresos', conGastos ? 'Gastos' : null, 'Balance', ''].filter((h) => h !== null);
    return el('section', { class: 'seccion' },
      el('h2', { text: moneda }),
      el('div', { class: 'stats' },
        statMonto('Ingresos', total.ingresos, moneda),
        conGastos ? statMonto('Gastos', total.gastos, moneda) : null,
        statMonto('Balance', total.balance, moneda)),
      el('table', { class: 'tabla' },
        el('thead', {}, el('tr', {}, cabeza.map((h, i) => el('th', { class: i > 0 && h ? 'num' : null, scope: 'col', text: h })))),
        el('tbody', {}, filas.map((f) => fila(f, moneda, max, conGastos)))));
  }

  async function cargar() {
    history.replaceState(null, '', location.pathname + Panel.qs({ desde: fDesde.value, hasta: fHasta.value, cliente_id: fCliente.value }));
    try {
      const r = await Panel.get('/api/reportes/balance', { desde: fDesde.value, hasta: fHasta.value, cliente_id: fCliente.value });
      const monedas = r.totales.map((t) => t.moneda);
      Panel.llenar(cont,
        r.incluye_gastos ? null : el('p', { class: 'aviso', text: 'Filtrado por cliente: se muestran solo sus ingresos, porque los gastos no se asignan a clientes.' }),
        monedas.length ? monedas.map((m) => bloqueMoneda(r, m)) : Panel.vacio('No hay movimientos en ese período.'));
    } catch (err) {
      Panel.manejarError(err);
    }
  }

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    cargar();
  });

  Panel.clientes().then((clientes) => {
    fCliente.append(...clientes.map((c) => el('option', { value: String(c.id), text: c.nombre })));
    fCliente.value = params.get('cliente_id') || '';
    return cargar();
  }).catch(Panel.manejarError);
})();
```

- [ ] **Step 6: Verificar en el navegador**

Checklist en `/admin/reportes`:
- [ ] Por defecto muestra los últimos 6 meses, con un bloque por moneda y una fila por mes, incluidos los meses en cero.
- [ ] En desktop se ven las barras verdes y rojas; en el celular la tabla pasa a tarjetas con etiquetas ("Mes", "Ingresos"…).
- [ ] Al filtrar por cliente aparece el aviso azul y desaparece la columna de gastos.
- [ ] Con "Hasta" anterior a "Desde" aparece el toast de error "Revisá las fechas".

- [ ] **Step 7: Commit**

```bash
git add admin/includes/repos/reportes.php api/reportes.php admin/reportes.php admin/assets/reportes.js tests/ReportesTest.php
git commit -m "$(cat <<'EOF'
Agregar reporte de balance mensual por moneda y cliente

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 22: Ficha de cliente completa (procesos, cobros, fixs, bitácora, eventos)

**Files:**
- Modify: `admin/cliente.php` (lista de scripts)
- Modify: `admin/assets/cliente.js` (se reemplaza completo)

**Interfaces:**
- Consumes: los módulos `Procesos`, `Cobros`, `Fixs` y `Eventos`. Todos exponen `nuevo(valores)` e `item(x, recargar)`.

- [ ] **Step 1: Cargar los módulos en la ficha**

En `admin/cliente.php` reemplazá la línea de `layout_start` por:

```php
layout_start('Cliente', 'clientes', [
    'mod-clientes.js', 'mod-procesos.js', 'mod-cobros.js', 'mod-fixs.js', 'mod-eventos.js', 'cliente.js',
], ['cliente-id' => $id]);
```

- [ ] **Step 2: Reemplazar `admin/assets/cliente.js` completo**

```js
'use strict';

(() => {
  const { el } = Panel;
  const id = Number(document.body.dataset.clienteId);
  const ficha = document.getElementById('ficha');

  const dato = (label, valor, href) => el('div', {},
    el('div', { class: 'stat-label', text: label }),
    valor ? (href ? el('a', { href, text: valor }) : el('div', { text: valor })) : el('div', { class: 'item-sub', text: '—' }));

  async function cargarFicha() {
    const c = await Panel.get(`/api/clientes/${id}`);
    document.getElementById('titulo').textContent = c.nombre;
    document.title = `${c.nombre} · VEZZA Admin`;
    Panel.llenar(ficha,
      el('div', { class: 'item-top' },
        Panel.badgeEstado('estadoCliente', c.estado),
        Panel.boton('Editar', async () => {
          const r = await Clientes.editar(c);
          if (r?.eliminado) location.href = '/admin/clientes';
          else if (r) cargarFicha().catch(Panel.manejarError);
        }, 'chico')),
      el('div', { class: 'datos' },
        dato('Email', c.email, c.email ? `mailto:${c.email}` : null),
        dato('Teléfono', c.telefono, c.telefono ? `tel:${c.telefono.replace(/[^\d+]/g, '')}` : null),
        dato('Rubro', c.rubro),
        dato('Cliente desde', c.fecha_inicio ? Panel.fmtFecha(c.fecha_inicio, true) : null)));
  }

  function tabEntidad(modulo, url, textoNuevo, textoVacio, extra = () => null) {
    return async function render(panel) {
      const items = await Panel.get(url, { cliente_id: id });
      const recargar = () => render(panel).catch(Panel.manejarError);
      Panel.llenar(panel,
        el('div', { class: 'toolbar' },
          Panel.boton(textoNuevo, async () => { if (await modulo.nuevo({ cliente_id: String(id) })) recargar(); }, 'primario')),
        extra(items),
        items.length ? el('div', { class: 'lista' }, items.map((x) => modulo.item(x, recargar))) : Panel.vacio(textoVacio));
    };
  }

  async function renderBitacora(panel) {
    const notas = await Panel.get('/api/notas-cliente', { cliente_id: id });
    const texto = el('textarea', { class: 'filtro', rows: 3, placeholder: 'Escribí una nota…', 'aria-label': 'Nueva nota' });
    const agregar = el('button', { type: 'submit', class: 'btn btn-primario', text: 'Agregar nota' });
    const form = el('form', { class: 'card' }, texto, el('div', { class: 'item-acciones' }, agregar));
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!texto.value.trim()) return;
      agregar.disabled = true;
      try {
        await Panel.post('/api/notas-cliente', { cliente_id: id, contenido: texto.value });
        await renderBitacora(panel);
      } catch (err) {
        Panel.manejarError(err);
      } finally {
        agregar.disabled = false;
      }
    });
    const items = notas.map((n) => el('article', { class: 'item' },
      el('div', { class: 'item-top' },
        el('span', { class: 'item-sub', text: Panel.fmtFechaHora(n.created_at) }),
        Panel.boton('Editar', () => editarNota(n, panel), 'chico')),
      el('p', { class: 'nota-texto', text: n.contenido })));
    Panel.llenar(panel, form, el('div', { class: 'lista seccion' }, items.length ? items : Panel.vacio('Todavía no hay notas.')));
  }

  async function editarNota(n, panel) {
    const r = await Panel.modalForm({
      titulo: 'Editar nota',
      campos: [{ name: 'contenido', label: 'Nota', type: 'textarea', required: true }],
      valores: n,
      enviar: (d) => Panel.put(`/api/notas-cliente/${n.id}`, d),
      eliminar: () => Panel.del(`/api/notas-cliente/${n.id}`),
    });
    if (r) renderBitacora(panel).catch(Panel.manejarError);
  }

  const totalCobros = (cobros) => (cobros.length ? el('p', { class: 'item-sub', text: `Total: ${Cobros.totales(cobros)}` }) : null);

  const TABS = [
    { id: 'procesos', label: 'Procesos', render: tabEntidad(Procesos, '/api/procesos', '+ Nuevo proceso', 'Este cliente todavía no tiene procesos.') },
    { id: 'cobros', label: 'Cobros', render: tabEntidad(Cobros, '/api/cobros', '+ Nuevo cobro', 'Este cliente todavía no tiene cobros.', totalCobros) },
    { id: 'fixs', label: 'Fixs', render: tabEntidad(Fixs, '/api/fixs', '+ Nuevo fix', 'Sin fixs reportados.') },
    { id: 'bitacora', label: 'Bitácora', render: renderBitacora },
    { id: 'eventos', label: 'Eventos', render: tabEntidad(Eventos, '/api/eventos', '+ Nuevo evento', 'Sin eventos con este cliente.') },
  ];

  cargarFicha()
    .then(() => Panel.tabs(document.getElementById('pestanas'), TABS))
    .catch(Panel.manejarError);
})();
```

- [ ] **Step 3: Verificar en el navegador**

Checklist en `/admin/clientes/<id>`:
- [ ] Hay 5 pestañas. En el celular se scrollean horizontalmente.
- [ ] La URL guarda la pestaña (`#cobros`) y la conserva al recargar.
- [ ] "+ Nuevo proceso / cobro / fix / evento" desde la ficha viene con el cliente preseleccionado. Al guardar, el ítem aparece en la pestaña.
- [ ] En la pestaña Cobros, "Marcar pagado" y "Subir archivo" funcionan igual que en `/admin/cobros`.
- [ ] Borrar un cliente con cobros muestra en el modal: "Este cliente tiene cobros registrados. Marcalo como finalizado…".

- [ ] **Step 4: Commit**

```bash
git add admin/cliente.php admin/assets/cliente.js
git commit -m "$(cat <<'EOF'
Completar la ficha de cliente con procesos, cobros, fixs y eventos

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

### Task 23: Documentación de deploy, verificación final y publicación

**Files:**
- Modify: `DEPLOY.md` (se agrega una sección al final)

- [ ] **Step 1: Agregar la sección del panel a `DEPLOY.md`**

Agregá esto al final de `DEPLOY.md`:

````markdown

## Panel admin (`/admin`)

El panel vive en el mismo repo y el mismo `public_html` que la landing. Es PHP
plano más MySQL: Hostinger lo ejecuta solo y el `git push` sigue siendo el único
paso de deploy. La landing no depende del panel. Si el panel falla, la landing
sigue funcionando igual.

### Setup único en Hostinger (antes del primer push con el panel)

1. **PHP:** en hPanel → Avanzado → Configuración de PHP, confirmá que la
   versión sea 8.1 o superior y que la extensión `fileinfo` esté activa.
2. **Base de datos:** en hPanel → Bases de datos → MySQL, creá una base y un
   usuario con una contraseña larga. Anotá el host (suele ser `localhost`), el
   nombre, el usuario y la contraseña.
3. **Esquema:** abrí phpMyAdmin en esa base → Importar → subí
   `db/migrations/001_inicial.sql` desde tu compu.
4. **Hash de tu contraseña:** en tu compu corré
   `php scripts/hash-password.php` y copiá la línea `ADMIN_PASSWORD_HASH=...`.
5. **`.env` fuera de `public_html`:** en el Administrador de archivos subí un
   nivel desde `public_html` (a la carpeta que la contiene) y creá un archivo
   `.env`:

   ```dotenv
   APP_ENV=production
   ADMIN_USERNAME=tu-usuario
   ADMIN_PASSWORD_HASH=$2y$12$...
   DB_HOST=localhost
   DB_NAME=u123456_vezza
   DB_USER=u123456_vezza
   DB_PASS=la-contraseña-de-la-base
   UPLOADS_DIR=
   SESSIONS_DIR=
   ```

   `APP_ENV=production` es obligatorio: activa la cookie `Secure`. En
   `ADMIN_PASSWORD_HASH` pegá el hash que generaste. No agregues comentarios
   con `#` al final de una línea: pasarían a formar parte del valor.
6. **Carpeta de comprobantes:** en ese mismo nivel creá
   `vezza_uploads/comprobantes/`. El panel la crea solo si tiene permisos, pero
   conviene dejarla hecha.

### Deploys siguientes

- `git push` a `main`, igual que siempre.
- Si el push trae un archivo nuevo en `db/migrations/`, entrá a
  `https://vezzadev.com/admin/migraciones` y tocá **Aplicar migraciones**.
- Si cambiaste `admin/assets/*.css` o `*.js`, subí `PANEL_ASSET_V` en
  `admin/includes/layout.php` antes de hacer push.

### Qué nunca se sube

`.env`, `.env.testing` y `vendor/` están en `.gitignore`. `.htaccess` además
responde 404 en `/.env*`, `/db/`, `/scripts/`, `/tests/`, `/vendor/`, `/docs/`
y `/admin/includes/`.

### Verificación después de cada deploy

```bash
scripts/verificar-panel.sh https://vezzadev.com
scripts/verificar-landing.sh https://vezzadev.com despues.txt
diff antes.txt despues.txt   # no tiene que haber diferencias
```

### Si algo sale mal

- **Panel con error 500:** revisá que `.env` exista un nivel arriba de
  `public_html` y que los datos de la base sean correctos. El detalle queda en
  el log de errores de PHP (hPanel → Avanzado → Logs de errores).
- **Te olvidaste la contraseña:** generá un hash nuevo con
  `php scripts/hash-password.php` y reemplazá `ADMIN_PASSWORD_HASH` en `.env`.
- **Te bloqueaste por intentos:** esperá 15 minutos o, en phpMyAdmin, corré
  `DELETE FROM login_intentos;`.
- **Volver atrás:** hacé `git revert` del commit que rompió algo y después
  `git push`. La landing no se ve afectada.

### Correr el panel en tu compu

1. Instalá XAMPP (PHP 8.2), Composer y el virtual host del puerto 8080. Los
   pasos están en el plan `docs/superpowers/plans/2026-09-22-panel-admin.md`,
   Tasks 1 y 5.
2. Creá la base `vezza_admin` y copiá `.env.example` a `.env` con tu hash.
3. Corré `php db/migrate.php` y abrí http://localhost:8080/admin.
4. Tests: `composer install` y después `php vendor/bin/phpunit`. Usan la base
   `vezza_admin_test`, definida en `.env.testing`.
````

- [ ] **Step 2: Verificación completa en local**

```bash
php vendor/bin/phpunit
for f in admin/*.php admin/includes/*.php admin/includes/repos/*.php api/*.php db/migrate.php scripts/hash-password.php; do php -l "$f" >/dev/null || echo "ERROR DE SINTAXIS: $f"; done
scripts/verificar-panel.sh http://localhost:8080
scripts/verificar-landing.sh http://localhost:8080 "$TEMP/landing-final-local.txt"
diff "$TEMP/landing-antes-local.txt" "$TEMP/landing-final-local.txt" && echo "Landing sin cambios"
git status --short
```

Resultado esperado:
- PHPUnit da `OK`.
- No aparece ningún `ERROR DE SINTAXIS`.
- `verificar-panel.sh` termina con `Todo OK`, ya sin excepciones.
- `diff` no muestra diferencias.
- `git status` no lista `.env`, `.env.testing` ni `vendor/`.

Además hacé una pasada completa en el navegador, en vista de celular (390px), sin errores en la consola:
- [ ] login
- [ ] crear un cliente
- [ ] crear un proceso y arrastrarlo
- [ ] crear subtareas
- [ ] crear un cobro y subirle un comprobante
- [ ] crear una suscripción y registrarle un pago
- [ ] crear una tarea
- [ ] crear un fix
- [ ] crear un evento en la agenda
- [ ] revisar el dashboard
- [ ] ver los reportes
- [ ] salir

- [ ] **Step 3: Commit**

```bash
git add DEPLOY.md
git commit -m "$(cat <<'EOF'
Documentar setup y deploy del panel admin

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 4: Publicar (requiere OK explícito de Valentino)**

**No lo hagas sin confirmación.** Hacer push a `main` publica en producción.

1. Pedile a Valentino que complete primero el "Setup único en Hostinger" de `DEPLOY.md`: base de datos, importar `001_inicial.sql`, `.env` fuera de `public_html` y la carpeta de comprobantes.
2. Con su OK, corré:

```bash
git checkout main
git merge --no-ff panel-admin -m "Sumar panel admin privado"
git push origin main
```

3. Esperá a que termine el deploy automático de Hostinger (1 o 2 minutos) y verificá:

```bash
scripts/verificar-panel.sh https://vezzadev.com
scripts/verificar-landing.sh https://vezzadev.com "$TEMP/landing-despues-prod.txt"
diff "$TEMP/landing-antes-prod.txt" "$TEMP/landing-despues-prod.txt" && echo "Landing de producción sin cambios"
```

Resultado esperado:
- `Todo OK` en el panel, sin diferencias en la landing.
- Login correcto en `https://vezzadev.com/admin-login` desde el celular.
- En DevTools → Application → Cookies, la cookie `vezza_admin` tiene `Secure`, `HttpOnly` y `SameSite=Strict`.

4. Si algo de la landing difiere: `git revert -m 1 HEAD && git push origin main`, y avisá.

