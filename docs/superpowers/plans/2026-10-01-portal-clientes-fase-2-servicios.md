# Portal de clientes — Fase 2: Servicios y estado — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que cada cliente vea en `/clientes` el estado de sus servicios (Activo, Mantenimiento, Caído o Sin verificar) con su historial, y que el admin pueda cargar servicios, forzar un estado a mano y tener un chequeo HTTP automático cada 5 minutos.

**Architecture:** Las tablas `servicios` y `servicio_eventos` ya existen (migración 002), así que esta fase es solo código, sin migración. Una función pura calcula el estado efectivo (override manual vigente > chequeo automático) y una única función `servicio_sincronizar()` registra un evento solo cuando ese estado cambia. El chequeo corre por CLI desde Cron Jobs de hPanel, con un fetcher y un resolver de DNS inyectables para testearlo sin red y con una guarda anti-SSRF. El portal lee con un repo propio que siempre filtra por el `cliente_id` de la sesión, y tiene su propio runner de API (`portal_api_run`), separado de `api_run` del admin.

**Tech Stack:** PHP 8.1+ plano (sin frameworks), MySQL/MariaDB, JS puro, PHPUnit 10.5. Sin build. Deploy por `git push` a `main` (Hostinger).

**Spec:** `docs/superpowers/specs/2026-09-30-portal-clientes-design.md` (secciones 4.4, 5 "Servicios y estado", 6 "Estado", 7 "`/admin/servicios`", 8 "Chequeo automático de servicios", 9 y la fase 2 de la sección 11).

## Global Constraints

Valores copiados del spec y de la fase 1. Aplican a todas las tareas.

- Estado efectivo: si `estado_manual` no es nulo y (`estado_manual_hasta` es nulo o futuro), manda ese. Si no, manda `estado_auto`. **Una única función lo calcula** y la comparten portal y admin.
- `servicio_eventos`: se inserta **solo cuando cambia** el estado efectivo. Sin un registro por chequeo.
- Anti-flapping: un servicio pasa a `caido` recién con **2 fallos seguidos** (`fallos_consecutivos`).
- Seguridad de las URLs (SSRF): solo http o https, timeout de **8 s**, máximo **3 redirecciones**, y se rechazan IPs privadas o reservadas **resolviendo el host antes de pedirlo** (y en cada redirección).
- Un override manual con `estado_manual_hasta` vencido deja de aplicar solo.
- Aislamiento por cliente: el `cliente_id` sale de la sesión, nunca del request. Los repos del portal van en `admin/includes/repos/portal_*.php` y **no reutilizan** los `*_list` ni `*_get` del admin.
- API del portal en `/clientes/api/*`, separada de `/api/*`, con CSRF en métodos no GET y las mismas convenciones de JSON y errores que la API del admin. `/api/*` con cookie de cliente responde 401.
- El JS del portal renderiza datos con `textContent` (o el helper `el`), nunca con `innerHTML` para contenido del usuario.
- El estado visible al cliente se refresca cada **30 s** y se pausa con la pestaña oculta.
- Tema claro, sin modo oscuro: fondo `#F5F3EE`, tinta `#15131C`, índigo `#3D2FE0`; Manrope y Sora. Voz rioplatense con voseo. Todas las pantallas se usan en un celular de **360 px** de ancho.
- Compatibilidad: PHP 8.1 (`composer.json` fija `platform.php = 8.1.0`). No usar sintaxis ni funciones de 8.2 o posterior.
- Sin secretos en el repo. Esta fase **no agrega variables al `.env`**.
- El admin y la landing se comportan igual que antes: los tests existentes siguen pasando sin modificarse.
- Commits en español, en el estilo del repo ("Agregar ...", "Hacer que ..."), y cada uno termina con el trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` (se pasa con un segundo `-m`).

## Decisiones de esta fase (aclaraciones al spec)

1. **Cuarto estado visible: "Sin verificar".** El spec define tres estados, pero un servicio sin chequeo automático y sin override (o con chequeo que todavía no corrió) no tiene un estado conocido. Mostrarlo como "Activo" sería afirmar algo que nadie verificó. La función de estado efectivo devuelve `desconocido` en ese caso y el portal lo muestra como "Sin verificar", con insignia neutra. No se guarda ningún evento para `desconocido`. Para mostrar "Activo" sin chequeo, el admin fuerza `activo` como override sin vencimiento.
2. **`chequear = 0` ignora `estado_auto`.** Si el admin apaga el chequeo, el último resultado queda viejo; el estado efectivo no lo usa.
3. **Cambiar la URL reinicia el chequeo** (`estado_auto = 'desconocido'`, `fallos_consecutivos = 0`): el resultado anterior era de otra URL.
4. **El mensaje del servicio es del override.** Se muestra al cliente solo mientras el override esté vigente.
5. **Un servicio responde "activo" con código HTTP 200 a 399** tras seguir hasta 3 redirecciones. Cualquier otro código, un error de red, un timeout o una URL no segura cuentan como fallo. Si un sitio protegido devuelve 401 o 403, el admin lo fuerza con un override.
6. **Respaldo por n8n del cron: fuera de alcance.** El plan de hosting es Business, que incluye Cron Jobs. Si hPanel → Avanzado no mostrara "Cron Jobs", se agrega el endpoint con `CRON_TOKEN` del spec como tarea aparte.
7. **Dos zonas horarias iguales, a propósito.** `db()` fija `time_zone = '-03:00'` y PHP usa `America/Argentina/Buenos_Aires`: las horas que guarda PHP (`date('Y-m-d H:i:s')`) y las que genera la base (`CURRENT_TIMESTAMP`) son comparables.

## Review Focus

Entradas y condiciones que el spec implica pero que no salen de sus casos obvios. Cada línea tiene su test en la tarea dueña del código.

1. **Override con vencimiento que pasa sin que nadie escriba:** el estado efectivo cambia solo; el siguiente cron tiene que registrar el evento. → Task 1 (`servicio_sincronizar`) y Task 2 (el runner sincroniza también los servicios sin chequeo).
2. **URL que apunta a la red interna:** `localhost`, `10.x`, `192.168.x`, `169.254.169.254` (metadatos de nube), `::1`, un host que resuelve a una IP pública **y** una privada, esquemas `file://`, `ftp://`, `gopher://`, credenciales en la URL, y una redirección que termina en una IP privada. Ninguna se pide. → Task 2.
3. **Servicio que no resuelve, no responde o hace explotar al fetcher:** cuenta como un fallo, nunca como una excepción que corte el cron para los demás servicios. → Task 2.
4. **Un cliente pidiendo servicios de otro, o viendo campos internos:** el listado del portal solo trae los suyos y no expone `fallos_consecutivos`, `estado_manual_hasta`, `origen` ni `cliente_id`. → Task 3.
5. **Servicio sin chequeo ni override, o con el chequeo apagado después de haber caído:** se ve "Sin verificar", nunca "Activo" ni un "Caído" viejo. → Task 1 y Task 4.

## Estructura de archivos

**Crear**
- `admin/includes/repos/servicios.php`: estado efectivo, eventos, CRUD del admin.
- `admin/includes/repos/chequeo_servicios.php`: guarda anti-SSRF, fetcher HTTP, anti-flapping y runner.
- `admin/includes/repos/portal_servicios.php`: lectura del portal, filtrada por cliente.
- `admin/includes/portal_api.php`: `portal_api_run`.
- `clientes/api/servicios.php`: endpoint del portal.
- `api/servicios.php`: endpoint del admin.
- `admin/servicios.php`, `admin/assets/mod-servicios.js`, `admin/assets/servicios.js`: pantalla del admin.
- `scripts/chequear-servicios.php`: script de cron (solo CLI).
- Tests: `ServiciosTest`, `ChequeoServiciosTest`, `PortalServiciosTest`.

**Modificar**
- `clientes/index.php`, `clientes/assets/portal.js`, `clientes/assets/portal.css`: pantalla Estado.
- `admin/includes/portal_layout.php`: subir `PORTAL_ASSET_V` a `'3'`.
- `admin/includes/layout.php`: ítem de menú, ícono y `PANEL_ASSET_V` a `'5'`.
- `admin/cliente.php`, `admin/assets/cliente.js`: pestaña Servicios.
- `.htaccess`: rutas `/admin/servicios` y `/clientes/api/*`.
- `scripts/verificar-portal.sh`, `DEPLOY.md`.

## Prerrequisitos de entorno (una sola vez)

- MariaDB de XAMPP prendida: `cd /c/xampp/mysql/bin && ./mysqld.exe --defaults-file=my.ini --console &`. Sin eso, PHPUnit falla en el bootstrap con "conexión denegada".
- Línea base: `php vendor/bin/phpunit` tiene que dar `OK` antes de tocar nada. Anotar la cantidad de tests: sirve para comprobar que ninguno se rompe.
- Correr PHPUnit siempre desde la raíz del repo.
- Rama de trabajo: `git switch main && git pull && git switch -c portal-clientes-fase-2`.

---

### Task 1: Estado efectivo, eventos y CRUD de servicios (repo del admin)

**Files:**
- Create: `admin/includes/repos/servicios.php`
- Create: `tests/ServiciosTest.php`

`bootstrap.php` carga solo todos los archivos de `admin/includes/repos/`, no hay que registrar nada.

**Interfaces:**
- Consumes: `crud_find`, `crud_insert`, `crud_update`, `crud_delete`, `q_all`, `q_one`, `q_val`, `tx`, `where_eq`, `sql_where` (`crud.php`); `validate`, `filtros` (`validate.php`); `HttpError` (`http.php`).
- Produces (las tareas 2, 3 y 5 dependen de estos nombres exactos):
  - `const SERVICIO_ESTADOS = ['activo', 'mantenimiento', 'caido']`
  - `const SERVICIO_EVENTOS_ADMIN = 5`, `const SERVICIO_EVENTOS_PORTAL = 10`
  - `servicio_override_vigente(array $s, string $ahora): bool`
  - `servicio_estado_efectivo(array $s, ?string $ahora = null): string` → `'activo'|'mantenimiento'|'caido'|'desconocido'`
  - `servicio_sincronizar(int $id): void`
  - `servicio_eventos(int $servicioId, int $limite): array` → filas `{estado, origen, mensaje, creado_en}`, la más nueva primero
  - `servicios_list(array $get): array`, `servicios_get(int $id): array`, `servicios_create(array $input): array`, `servicios_update(int $id, array $input): array`, `servicios_delete(int $id): void`. Cada servicio devuelto trae `estado` (efectivo), `override_vigente` (bool), `cliente_nombre` y `eventos`.

- [ ] **Step 1: Crear la rama y confirmar la línea base**

```bash
git switch main && git pull && git switch -c portal-clientes-fase-2
php vendor/bin/phpunit
```

Expected: `OK (N tests, M assertions)`. Anotar N.

- [ ] **Step 2: Escribir los tests que fallan**

Crear `tests/ServiciosTest.php`:

```php
<?php
declare(strict_types=1);

final class ServiciosTest extends DbTestCase
{
    private int $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cliente = $this->crearCliente('Panadería Sol');
    }

    private function servicio(array $extra = []): array
    {
        return servicios_create($extra + ['cliente_id' => $this->cliente, 'nombre' => 'Sitio web', 'url' => 'https://sol.test']);
    }

    private function estados(int $id): array
    {
        return array_column(servicio_eventos($id, 50), 'estado');
    }

    // --- estado efectivo (función pura) ---

    public function test_sin_override_manda_el_chequeo_automatico(): void
    {
        $s = ['chequear' => 1, 'estado_auto' => 'caido', 'estado_manual' => null, 'estado_manual_hasta' => null];
        $this->assertSame('caido', servicio_estado_efectivo($s, '2026-10-01 10:00:00'));
    }

    public function test_sin_chequeo_y_sin_override_es_desconocido_aunque_haya_un_resultado_viejo(): void
    {
        $s = ['chequear' => 0, 'estado_auto' => 'caido', 'estado_manual' => null, 'estado_manual_hasta' => null];
        $this->assertSame('desconocido', servicio_estado_efectivo($s, '2026-10-01 10:00:00'));
    }

    public function test_override_sin_vencimiento_manda_sobre_el_chequeo(): void
    {
        $s = ['chequear' => 1, 'estado_auto' => 'caido', 'estado_manual' => 'mantenimiento', 'estado_manual_hasta' => null];
        $this->assertSame('mantenimiento', servicio_estado_efectivo($s, '2026-10-01 10:00:00'));
    }

    public function test_override_con_vencimiento_futuro_manda_y_vencido_deja_de_aplicar(): void
    {
        $s = ['chequear' => 1, 'estado_auto' => 'activo', 'estado_manual' => 'mantenimiento', 'estado_manual_hasta' => '2026-10-01 10:00:00'];
        $this->assertSame('mantenimiento', servicio_estado_efectivo($s, '2026-10-01 09:59:59'));
        // En el instante exacto del vencimiento ya no aplica.
        $this->assertSame('activo', servicio_estado_efectivo($s, '2026-10-01 10:00:00'));
        $this->assertSame('activo', servicio_estado_efectivo($s, '2026-10-01 10:00:01'));
    }

    public function test_override_vigente_puede_forzar_activo_sin_chequeo(): void
    {
        $s = ['chequear' => 0, 'estado_auto' => 'desconocido', 'estado_manual' => 'activo', 'estado_manual_hasta' => null];
        $this->assertSame('activo', servicio_estado_efectivo($s, '2026-10-01 10:00:00'));
    }

    // --- CRUD ---

    public function test_crear_con_valores_por_defecto(): void
    {
        $s = $this->servicio();
        $this->assertSame('Sitio web', $s['nombre']);
        $this->assertSame('Panadería Sol', $s['cliente_nombre']);
        $this->assertSame(0, (int)$s['chequear']);
        $this->assertSame('desconocido', $s['estado']);
        $this->assertFalse($s['override_vigente']);
        $this->assertSame([], $s['eventos']);
    }

    public function test_chequear_sin_url_da_422_en_la_url(): void
    {
        $e = $this->errores422(fn() => servicios_create(['cliente_id' => $this->cliente, 'nombre' => 'X', 'chequear' => true]));
        $this->assertArrayHasKey('url', $e);
        $s = $this->servicio(['chequear' => 0]);
        $e = $this->errores422(fn() => servicios_update($s['id'], ['chequear' => true, 'url' => null]));
        $this->assertArrayHasKey('url', $e);
    }

    public function test_cliente_inexistente_nombre_vacio_y_url_invalida_dan_422(): void
    {
        $this->assertArrayHasKey('cliente_id', $this->errores422(fn() => servicios_create(['cliente_id' => 999999, 'nombre' => 'X'])));
        $this->assertArrayHasKey('nombre', $this->errores422(fn() => servicios_create(['cliente_id' => $this->cliente, 'nombre' => ''])));
        foreach (['javascript:alert(1)', 'ftp://sol.test', 'no es una url'] as $url) {
            $this->assertArrayHasKey('url', $this->errores422(fn() => $this->servicio(['url' => $url])), $url);
        }
    }

    public function test_get_de_un_servicio_inexistente_da_404(): void
    {
        $this->assertHttp(404, fn() => servicios_get(999999));
        $this->assertHttp(404, fn() => servicios_update(999999, ['nombre' => 'X']));
        $this->assertHttp(404, fn() => servicios_delete(999999));
    }

    public function test_listar_filtra_por_cliente_y_trae_los_eventos(): void
    {
        $otro = $this->crearCliente('Otro');
        $mio = $this->servicio(['estado_manual' => 'mantenimiento']);
        servicios_create(['cliente_id' => $otro, 'nombre' => 'Ajeno']);
        $lista = servicios_list(['cliente_id' => (string)$this->cliente]);
        $this->assertCount(1, $lista);
        $this->assertSame($mio['id'], $lista[0]['id']);
        $this->assertSame('mantenimiento', $lista[0]['eventos'][0]['estado']);
        $this->assertCount(2, servicios_list([]));
    }

    public function test_borrar_un_servicio_borra_sus_eventos(): void
    {
        $s = $this->servicio(['estado_manual' => 'caido']);
        servicios_delete($s['id']);
        $this->assertSame(0, (int)q_val('SELECT COUNT(*) FROM servicio_eventos WHERE servicio_id = ?', [$s['id']]));
    }

    // --- eventos: solo cuando cambia el estado efectivo ---

    public function test_los_eventos_se_registran_solo_cuando_cambia_el_estado_efectivo(): void
    {
        $s = $this->servicio(['chequear' => true]);
        $id = $s['id'];
        $this->assertSame([], $this->estados($id), 'sin chequeo todavía: desconocido, no hay evento');

        crud_update('servicios', $id, ['estado_auto' => 'activo']);
        servicio_sincronizar($id);
        servicio_sincronizar($id); // idempotente: no duplica
        $this->assertSame(['activo'], $this->estados($id));
        $this->assertSame('auto', servicio_eventos($id, 1)[0]['origen']);

        servicios_update($id, ['estado_manual' => 'mantenimiento', 'mensaje' => 'Migramos el hosting']);
        $ultimo = servicio_eventos($id, 1)[0];
        $this->assertSame('mantenimiento', $ultimo['estado']);
        $this->assertSame('manual', $ultimo['origen']);
        $this->assertSame('Migramos el hosting', $ultimo['mensaje']);

        servicios_update($id, ['nombre' => 'Sitio web (nuevo)']); // el estado no cambió
        $this->assertSame(['mantenimiento', 'activo'], $this->estados($id));

        servicios_update($id, ['estado_manual' => null]);
        $this->assertSame(['activo', 'mantenimiento', 'activo'], $this->estados($id));
        $this->assertSame('auto', servicio_eventos($id, 1)[0]['origen']);
    }

    public function test_un_override_que_vence_solo_registra_el_evento_al_sincronizar(): void
    {
        $s = $this->servicio(['chequear' => true]);
        $id = $s['id'];
        crud_update('servicios', $id, ['estado_auto' => 'activo']);
        servicios_update($id, ['estado_manual' => 'mantenimiento', 'estado_manual_hasta' => '2099-01-01 00:00:00']);
        $this->assertSame(['mantenimiento', 'activo'], $this->estados($id));

        crud_update('servicios', $id, ['estado_manual_hasta' => '2000-01-01 00:00:00']); // el reloj pasó
        $this->assertSame('activo', servicios_get($id)['estado']);
        $this->assertSame(2, count($this->estados($id)), 'nadie escribió: todavía no hay evento');

        servicio_sincronizar($id);
        $this->assertSame(['activo', 'mantenimiento', 'activo'], $this->estados($id));
        $this->assertSame('auto', servicio_eventos($id, 1)[0]['origen']);
    }

    // --- normalización ---

    public function test_sin_estado_manual_se_limpian_el_vencimiento_y_el_mensaje(): void
    {
        $s = $this->servicio(['estado_manual_hasta' => '2099-01-01 00:00:00', 'mensaje' => 'Hola']);
        $this->assertNull($s['estado_manual_hasta']);
        $this->assertNull($s['mensaje']);
    }

    public function test_cambiar_la_url_reinicia_el_chequeo(): void
    {
        $s = $this->servicio(['chequear' => true]);
        crud_update('servicios', $s['id'], ['estado_auto' => 'caido', 'fallos_consecutivos' => 1]);
        $r = servicios_update($s['id'], ['url' => 'https://otra.test']);
        $this->assertSame('desconocido', $r['estado_auto']);
        $this->assertSame(0, (int)$r['fallos_consecutivos']);
    }

    public function test_mantener_la_misma_url_no_reinicia_el_chequeo(): void
    {
        $s = $this->servicio(['chequear' => true]);
        crud_update('servicios', $s['id'], ['estado_auto' => 'caido', 'fallos_consecutivos' => 2]);
        $r = servicios_update($s['id'], ['url' => 'https://sol.test', 'nombre' => 'Renombrado']);
        $this->assertSame('caido', $r['estado_auto']);
    }

    public function test_apagar_el_chequeo_deja_de_mostrar_el_resultado_viejo(): void
    {
        $s = $this->servicio(['chequear' => true]);
        crud_update('servicios', $s['id'], ['estado_auto' => 'caido', 'fallos_consecutivos' => 2]);
        $this->assertSame('caido', servicios_get($s['id'])['estado']);
        $this->assertSame('desconocido', servicios_update($s['id'], ['chequear' => false])['estado']);
    }
}
```

- [ ] **Step 3: Correr los tests y ver que fallan**

Run: `php vendor/bin/phpunit tests/ServiciosTest.php`
Expected: FAIL con `Call to undefined function servicio_estado_efectivo()` (o `servicios_create()`).

- [ ] **Step 4: Implementar `admin/includes/repos/servicios.php`**

```php
<?php
declare(strict_types=1);

const SERVICIO_ESTADOS = ['activo', 'mantenimiento', 'caido'];
const SERVICIO_EVENTOS_ADMIN = 5;
const SERVICIO_EVENTOS_PORTAL = 10;

const SERVICIOS_SELECT = 'SELECT s.*, c.nombre AS cliente_nombre
    FROM servicios s
    JOIN clientes c ON c.id = s.cliente_id';

/** El override manual manda mientras no tenga vencimiento o el vencimiento sea futuro. $ahora: 'Y-m-d H:i:s'. */
function servicio_override_vigente(array $s, string $ahora): bool
{
    if (($s['estado_manual'] ?? null) === null) {
        return false;
    }
    $hasta = $s['estado_manual_hasta'] ?? null;
    return $hasta === null || $hasta > $ahora;
}

/**
 * Estado que corresponde mostrar: override vigente, si no el chequeo automático. 'desconocido' cuando nadie
 * lo verificó (sin chequeo activo ni override): no se afirma "Activo" sin saberlo, y un resultado viejo de un
 * chequeo apagado no se muestra.
 */
function servicio_estado_efectivo(array $s, ?string $ahora = null): string
{
    $ahora ??= date('Y-m-d H:i:s');
    if (servicio_override_vigente($s, $ahora)) {
        return $s['estado_manual'];
    }
    return (int)($s['chequear'] ?? 0) === 1 ? ($s['estado_auto'] ?? 'desconocido') : 'desconocido';
}

/**
 * Registra un evento solo si el estado efectivo cambió respecto del último evento. Se llama después de cada
 * cambio y desde el cron: un override que vence cambia el estado sin que nadie escriba.
 */
function servicio_sincronizar(int $id): void
{
    $s = crud_find('servicios', $id);
    $ahora = date('Y-m-d H:i:s');
    $estado = servicio_estado_efectivo($s, $ahora);
    if ($estado === 'desconocido') {
        return;
    }
    $ultimo = q_val('SELECT estado FROM servicio_eventos WHERE servicio_id = ? ORDER BY id DESC LIMIT 1', [$id]);
    if ($ultimo === $estado) {
        return;
    }
    $manual = servicio_override_vigente($s, $ahora);
    crud_insert('servicio_eventos', [
        'servicio_id' => $id,
        'estado' => $estado,
        'origen' => $manual ? 'manual' : 'auto',
        'mensaje' => $manual ? $s['mensaje'] : null,
    ]);
}

function servicio_eventos(int $servicioId, int $limite): array
{
    return q_all(
        'SELECT estado, origen, mensaje, creado_en FROM servicio_eventos WHERE servicio_id = ? ORDER BY id DESC LIMIT ' . $limite,
        [$servicioId]
    );
}

function servicios_schema(): array
{
    return [
        'cliente_id' => ['type' => 'fk', 'table' => 'clientes', 'required' => true],
        'nombre' => ['type' => 'string', 'required' => true],
        'url' => ['type' => 'url'],
        'chequear' => ['type' => 'bool', 'notnull' => true],
        'estado_manual' => ['type' => 'enum', 'values' => SERVICIO_ESTADOS],
        'estado_manual_hasta' => ['type' => 'datetime'],
        'mensaje' => ['type' => 'string', 'max' => 500],
    ];
}

function servicio_con_estado(array $s): array
{
    $ahora = date('Y-m-d H:i:s');
    $s['estado'] = servicio_estado_efectivo($s, $ahora);
    $s['override_vigente'] = servicio_override_vigente($s, $ahora);
    return $s;
}

function servicios_list(array $get): array
{
    $f = filtros($get, ['cliente_id' => ['type' => 'int']]);
    [$partes, $params] = where_eq($f, ['cliente_id' => 's.cliente_id']);
    $filas = q_all(SERVICIOS_SELECT . sql_where($partes) . ' ORDER BY c.nombre, s.nombre, s.id', $params);
    return array_map(function (array $s): array {
        $s = servicio_con_estado($s);
        $s['eventos'] = servicio_eventos((int)$s['id'], SERVICIO_EVENTOS_ADMIN);
        return $s;
    }, $filas);
}

function servicios_get(int $id): array
{
    $s = q_one(SERVICIOS_SELECT . ' WHERE s.id = ?', [$id]);
    if ($s === null) {
        throw new HttpError(404, 'No encontrado');
    }
    $s = servicio_con_estado($s);
    $s['eventos'] = servicio_eventos($id, SERVICIO_EVENTOS_ADMIN);
    return $s;
}

function servicios_normalizar(array $datos, ?array $actual): array
{
    $url = array_key_exists('url', $datos) ? $datos['url'] : ($actual['url'] ?? null);
    $chequear = array_key_exists('chequear', $datos) ? $datos['chequear'] : ($actual['chequear'] ?? 0);
    if ((int)$chequear === 1 && $url === null) {
        throw new HttpError(422, 'Revisá los datos marcados', ['url' => 'Cargá la URL para poder chequearlo']);
    }
    $manual = array_key_exists('estado_manual', $datos) ? $datos['estado_manual'] : ($actual['estado_manual'] ?? null);
    if ($manual === null) {
        // Sin override, el vencimiento y el mensaje no tienen sentido.
        $datos['estado_manual_hasta'] = null;
        $datos['mensaje'] = null;
    }
    if ($actual !== null && array_key_exists('url', $datos) && $datos['url'] !== $actual['url']) {
        // El resultado del chequeo era de otra URL.
        $datos['estado_auto'] = 'desconocido';
        $datos['fallos_consecutivos'] = 0;
    }
    return $datos;
}

function servicios_create(array $input): array
{
    $datos = servicios_normalizar(validate($input, servicios_schema()), null);
    $id = tx(function () use ($datos): int {
        $id = crud_insert('servicios', $datos);
        servicio_sincronizar($id);
        return $id;
    });
    return servicios_get($id);
}

function servicios_update(int $id, array $input): array
{
    $actual = crud_find('servicios', $id);
    $datos = servicios_normalizar(validate($input, servicios_schema(), true), $actual);
    tx(function () use ($id, $datos): void {
        crud_update('servicios', $id, $datos);
        servicio_sincronizar($id);
    });
    return servicios_get($id);
}

function servicios_delete(int $id): void
{
    crud_delete('servicios', $id);
}
```

- [ ] **Step 5: Correr los tests y ver que pasan**

Run: `php vendor/bin/phpunit tests/ServiciosTest.php`
Expected: `OK`. Si `test_cliente_inexistente...` falla en la URL `no es una url`, revisar que `validate` devuelva el error en `url` (usa `filter_var`).

- [ ] **Step 6: Correr toda la suite y commitear**

```bash
php vendor/bin/phpunit
git add admin/includes/repos/servicios.php tests/ServiciosTest.php
git commit -m "Agregar el estado efectivo, los eventos y el CRUD de servicios" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

Expected: `OK (N + 16 tests, ...)`, sin fallos en los tests anteriores.

---

### Task 2: Chequeo automático (guarda anti-SSRF, anti-flapping, runner y script)

**Files:**
- Create: `admin/includes/repos/chequeo_servicios.php`
- Create: `scripts/chequear-servicios.php`
- Create: `tests/ChequeoServiciosTest.php`

**Interfaces:**
- Consumes (Task 1): `servicio_sincronizar(int $id): void`, `servicios_create(array): array`, `crud_find`, `crud_update`, `tx`.
- Produces:
  - `const CHEQUEO_TIMEOUT = 8`, `const CHEQUEO_MAX_REDIRECCIONES = 3`, `const CHEQUEO_FALLOS_PARA_CAIDO = 2`
  - `chequeo_resolver(string $host): array` → lista de IPs (resolver real).
  - `chequeo_ip_publica(string $ip): bool`
  - `chequeo_url_segura(string $url, callable $resolver): bool`
  - `chequeo_http_get(string $url): ?array` → `['status' => int, 'location' => ?string]` o `null` si no respondió (fetcher real).
  - `chequeo_estado(string $url, callable $fetcher, callable $resolver): string` → `'activo'|'caido'`
  - `servicio_registrar_chequeo(int $id, string $resultado): void`
  - `chequear_servicios(callable $fetcher, callable $resolver): array` → `['chequeados' => int, 'activos' => int, 'fallidos' => int]`

Contratos de los parámetros inyectables: un **resolver** es `fn(string $host): string[]` (IPs, vacío si no resuelve). Un **fetcher** es `fn(string $url): ?array` con `['status' => int, 'location' => ?string]`, o `null` si no hubo respuesta.

- [ ] **Step 1: Escribir los tests que fallan**

Crear `tests/ChequeoServiciosTest.php`:

```php
<?php
declare(strict_types=1);

final class ChequeoServiciosTest extends DbTestCase
{
    private int $cliente;
    /** @var string[] URLs que el fetcher falso llegó a pedir. */
    private array $pedidos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cliente = $this->crearCliente('Panadería Sol');
        $this->pedidos = [];
    }

    private function resolver(array $mapa): callable
    {
        return fn(string $host): array => $mapa[$host] ?? [];
    }

    private function resolverPublico(): callable
    {
        return fn(string $host): array => ['93.184.216.34'];
    }

    /** @param array<string, array|null|Throwable> $respuestas url => respuesta del fetcher */
    private function fetcher(array $respuestas): callable
    {
        return function (string $url) use ($respuestas): ?array {
            $this->pedidos[] = $url;
            $r = $respuestas[$url] ?? null;
            if ($r instanceof Throwable) {
                throw $r;
            }
            return $r;
        };
    }

    private function servicio(array $extra = []): int
    {
        return servicios_create($extra + ['cliente_id' => $this->cliente, 'nombre' => 'Sitio', 'url' => 'https://sol.test', 'chequear' => true])['id'];
    }

    // --- guarda anti-SSRF ---

    public static function urlsPrivadasOMalas(): array
    {
        return [
            'localhost' => ['http://localhost/', ['localhost' => ['127.0.0.1']]],
            'loopback v4' => ['http://127.0.0.1/', []],
            'red privada 10.x' => ['http://interno.test/', ['interno.test' => ['10.0.0.5']]],
            'red privada 192.168.x' => ['http://192.168.1.1/', []],
            'red privada 172.16.x' => ['http://172.16.0.9/', []],
            'metadatos de nube' => ['http://169.254.169.254/latest/meta-data/', []],
            'loopback v6' => ['http://[::1]/', []],
            'v4 mapeada a v6' => ['http://[::ffff:127.0.0.1]/', []],
            'host que resuelve a pública y privada' => ['http://mixto.test/', ['mixto.test' => ['93.184.216.34', '10.0.0.5']]],
            'host que no resuelve' => ['http://no-existe.test/', []],
            'esquema file' => ['file:///etc/passwd', []],
            'esquema ftp' => ['ftp://sol.test/', ['sol.test' => ['93.184.216.34']]],
            'esquema gopher' => ['gopher://sol.test/', ['sol.test' => ['93.184.216.34']]],
            'credenciales en la url' => ['http://user:pass@sol.test/', ['sol.test' => ['93.184.216.34']]],
            'sin host' => ['http:///ruta', []],
            'texto cualquiera' => ['esto no es una url', []],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('urlsPrivadasOMalas')]
    public function test_no_se_piden_urls_privadas_ni_con_esquemas_raros(string $url, array $dns): void
    {
        $this->assertFalse(chequeo_url_segura($url, $this->resolver($dns)));
    }

    public function test_se_piden_urls_http_y_https_que_resuelven_a_ips_publicas(): void
    {
        $r = $this->resolver(['sol.test' => ['93.184.216.34']]);
        $this->assertTrue(chequeo_url_segura('https://sol.test/', $r));
        $this->assertTrue(chequeo_url_segura('http://sol.test:8080/ruta?x=1', $r));
        $this->assertTrue(chequeo_url_segura('HTTPS://sol.test/', $r));
    }

    public function test_ip_publica(): void
    {
        $this->assertTrue(chequeo_ip_publica('93.184.216.34'));
        $this->assertTrue(chequeo_ip_publica('2606:2800:220:1:248:1893:25c8:1946'));
        foreach (['127.0.0.1', '10.1.2.3', '192.168.0.1', '169.254.169.254', '0.0.0.0', '::1', 'basura'] as $ip) {
            $this->assertFalse(chequeo_ip_publica($ip), $ip);
        }
    }

    // --- chequeo de una URL (con redirecciones) ---

    public function test_codigos_2xx_y_3xx_sin_destino_son_activo_y_el_resto_caido(): void
    {
        $u = 'https://sol.test';
        foreach ([200 => 'activo', 204 => 'activo', 301 => 'activo', 404 => 'caido', 500 => 'caido', 503 => 'caido'] as $status => $esperado) {
            $f = $this->fetcher([$u => ['status' => $status, 'location' => null]]);
            $this->assertSame($esperado, chequeo_estado($u, $f, $this->resolverPublico()), (string)$status);
        }
    }

    public function test_sin_respuesta_es_caido(): void
    {
        $this->assertSame('caido', chequeo_estado('https://sol.test', $this->fetcher([]), $this->resolverPublico()));
    }

    public function test_sigue_una_redireccion_absoluta_y_una_relativa(): void
    {
        $f = $this->fetcher([
            'https://sol.test' => ['status' => 301, 'location' => 'https://www.sol.test/inicio'],
            'https://www.sol.test/inicio' => ['status' => 302, 'location' => '/final'],
            'https://www.sol.test/final' => ['status' => 200, 'location' => null],
        ]);
        $this->assertSame('activo', chequeo_estado('https://sol.test', $f, $this->resolverPublico()));
        $this->assertSame(['https://sol.test', 'https://www.sol.test/inicio', 'https://www.sol.test/final'], $this->pedidos);
    }

    public function test_acepta_hasta_3_redirecciones_y_corta_en_la_cuarta(): void
    {
        $cadena = [
            'https://a.test' => ['status' => 301, 'location' => 'https://b.test'],
            'https://b.test' => ['status' => 301, 'location' => 'https://c.test'],
            'https://c.test' => ['status' => 301, 'location' => 'https://d.test'],
            'https://d.test' => ['status' => 200, 'location' => null],
        ];
        $this->assertSame('activo', chequeo_estado('https://a.test', $this->fetcher($cadena), $this->resolverPublico()));

        $this->pedidos = [];
        $cadena['https://d.test'] = ['status' => 301, 'location' => 'https://e.test'];
        $cadena['https://e.test'] = ['status' => 200, 'location' => null];
        $this->assertSame('caido', chequeo_estado('https://a.test', $this->fetcher($cadena), $this->resolverPublico()));
        $this->assertNotContains('https://e.test', $this->pedidos, 'la cuarta redirección no se sigue');
    }

    public function test_un_bucle_de_redirecciones_es_caido(): void
    {
        $f = $this->fetcher([
            'https://a.test' => ['status' => 302, 'location' => 'https://b.test'],
            'https://b.test' => ['status' => 302, 'location' => 'https://a.test'],
        ]);
        $this->assertSame('caido', chequeo_estado('https://a.test', $f, $this->resolverPublico()));
    }

    public function test_una_redireccion_hacia_una_ip_privada_no_se_pide(): void
    {
        $f = $this->fetcher([
            'https://sol.test' => ['status' => 302, 'location' => 'http://169.254.169.254/latest/meta-data/'],
            'http://169.254.169.254/latest/meta-data/' => ['status' => 200, 'location' => null],
        ]);
        $this->assertSame('caido', chequeo_estado('https://sol.test', $f, $this->resolver(['sol.test' => ['93.184.216.34']])));
        $this->assertSame(['https://sol.test'], $this->pedidos);
    }

    public function test_una_redireccion_con_destino_ilegible_es_caido(): void
    {
        $f = $this->fetcher(['https://sol.test' => ['status' => 301, 'location' => 'ftp://sol.test/']]);
        $this->assertSame('caido', chequeo_estado('https://sol.test', $f, $this->resolverPublico()));
        $f = $this->fetcher(['https://sol.test' => ['status' => 301, 'location' => 'otra-ruta-relativa']]);
        $this->assertSame('caido', chequeo_estado('https://sol.test', $f, $this->resolverPublico()));
    }

    public function test_una_url_privada_ni_siquiera_llega_al_fetcher(): void
    {
        $f = $this->fetcher(['http://10.0.0.1' => ['status' => 200, 'location' => null]]);
        $this->assertSame('caido', chequeo_estado('http://10.0.0.1', $f, $this->resolver([])));
        $this->assertSame([], $this->pedidos);
    }

    // --- anti-flapping y eventos ---

    private function estados(int $id): array
    {
        return array_column(servicio_eventos($id, 50), 'estado');
    }

    public function test_un_solo_fallo_no_marca_caido_y_el_segundo_si(): void
    {
        $id = $this->servicio();
        servicio_registrar_chequeo($id, 'caido');
        $s = crud_find('servicios', $id);
        $this->assertSame('desconocido', $s['estado_auto']);
        $this->assertSame(1, (int)$s['fallos_consecutivos']);
        $this->assertNotNull($s['ultimo_chequeo']);
        $this->assertSame([], $this->estados($id));

        servicio_registrar_chequeo($id, 'caido');
        $s = crud_find('servicios', $id);
        $this->assertSame('caido', $s['estado_auto']);
        $this->assertSame(2, (int)$s['fallos_consecutivos']);
        $this->assertSame(['caido'], $this->estados($id));
    }

    public function test_un_fallo_suelto_despues_de_activo_no_cambia_nada(): void
    {
        $id = $this->servicio();
        servicio_registrar_chequeo($id, 'activo');
        servicio_registrar_chequeo($id, 'caido');
        servicio_registrar_chequeo($id, 'activo');
        $this->assertSame('activo', crud_find('servicios', $id)['estado_auto']);
        $this->assertSame(0, (int)crud_find('servicios', $id)['fallos_consecutivos']);
        $this->assertSame(['activo'], $this->estados($id), 'un solo evento: el estado nunca cambió');
    }

    public function test_cae_se_recupera_y_registra_un_evento_por_cambio(): void
    {
        $id = $this->servicio();
        servicio_registrar_chequeo($id, 'activo');
        servicio_registrar_chequeo($id, 'caido');
        servicio_registrar_chequeo($id, 'caido');
        servicio_registrar_chequeo($id, 'caido'); // sigue caído: no repite
        servicio_registrar_chequeo($id, 'activo');
        servicio_registrar_chequeo($id, 'activo'); // sigue activo: no repite
        $this->assertSame(['activo', 'caido', 'activo'], array_reverse($this->estados($id)));
    }

    public function test_un_override_vigente_no_deja_que_el_chequeo_pise_el_estado_mostrado(): void
    {
        $id = $this->servicio(['estado_manual' => 'mantenimiento']);
        servicio_registrar_chequeo($id, 'caido');
        servicio_registrar_chequeo($id, 'caido');
        $this->assertSame('caido', crud_find('servicios', $id)['estado_auto']);
        $this->assertSame('mantenimiento', servicios_get($id)['estado']);
        $this->assertSame(['mantenimiento'], $this->estados($id), 'el chequeo no genera evento mientras manda el override');
    }

    // --- runner ---

    public function test_el_runner_chequea_solo_los_servicios_con_chequeo_y_url(): void
    {
        $a = $this->servicio(['url' => 'https://a.test']);
        $sinChequeo = servicios_create(['cliente_id' => $this->cliente, 'nombre' => 'Mail', 'url' => 'https://mail.test'])['id'];
        $f = $this->fetcher(['https://a.test' => ['status' => 200, 'location' => null]]);

        $r = chequear_servicios($f, $this->resolverPublico());

        $this->assertSame(['chequeados' => 1, 'activos' => 1, 'fallidos' => 0], $r);
        $this->assertSame(['https://a.test'], $this->pedidos);
        $this->assertNotNull(crud_find('servicios', $a)['ultimo_chequeo']);
        $this->assertNull(crud_find('servicios', $sinChequeo)['ultimo_chequeo']);
    }

    public function test_un_fetcher_que_explota_cuenta_como_fallo_y_no_corta_a_los_demas(): void
    {
        $roto = $this->servicio(['nombre' => 'Roto', 'url' => 'https://roto.test']);
        $sano = $this->servicio(['nombre' => 'Sano', 'url' => 'https://sano.test']);
        $f = $this->fetcher([
            'https://roto.test' => new RuntimeException('DNS caído'),
            'https://sano.test' => ['status' => 200, 'location' => null],
        ]);

        $r = chequear_servicios($f, $this->resolverPublico());

        $this->assertSame(['chequeados' => 2, 'activos' => 1, 'fallidos' => 1], $r);
        $this->assertSame(1, (int)crud_find('servicios', $roto)['fallos_consecutivos']);
        $this->assertSame('activo', crud_find('servicios', $sano)['estado_auto']);
    }

    public function test_el_runner_sincroniza_tambien_los_servicios_sin_chequeo(): void
    {
        // Insertado directo, sin pasar por servicios_create: nadie registró su evento todavía.
        $id = crud_insert('servicios', ['cliente_id' => $this->cliente, 'nombre' => 'Mail', 'estado_manual' => 'mantenimiento']);
        $this->assertSame([], $this->estados($id));

        chequear_servicios($this->fetcher([]), $this->resolverPublico());

        $this->assertSame(['mantenimiento'], $this->estados($id));
    }

    public function test_el_runner_registra_el_evento_de_un_override_vencido_en_un_servicio_con_chequeo(): void
    {
        $id = $this->servicio(['url' => 'https://a.test']);
        servicio_registrar_chequeo($id, 'activo');
        servicios_update($id, ['estado_manual' => 'mantenimiento', 'estado_manual_hasta' => '2099-01-01 00:00:00']);
        crud_update('servicios', $id, ['estado_manual_hasta' => '2000-01-01 00:00:00']);

        chequear_servicios($this->fetcher(['https://a.test' => ['status' => 200, 'location' => null]]), $this->resolverPublico());

        $this->assertSame(['activo', 'mantenimiento', 'activo'], $this->estados($id)); // el más nuevo primero
        $this->assertSame('activo', servicios_get($id)['estado']);
    }
}
```

- [ ] **Step 2: Correr los tests y ver que fallan**

Run: `php vendor/bin/phpunit tests/ChequeoServiciosTest.php`
Expected: FAIL con `Call to undefined function chequeo_url_segura()`.

- [ ] **Step 3: Implementar `admin/includes/repos/chequeo_servicios.php`**

```php
<?php
declare(strict_types=1);

const CHEQUEO_TIMEOUT = 8;
const CHEQUEO_MAX_REDIRECCIONES = 3;
const CHEQUEO_FALLOS_PARA_CAIDO = 2;

/** @return string[] IPs (v4 y v6) a las que resuelve el host. Vacío si no resuelve. */
function chequeo_resolver(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return [$host];
    }
    $ips = gethostbynamel($host) ?: [];
    foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $registro) {
        if (!empty($registro['ipv6'])) {
            $ips[] = $registro['ipv6'];
        }
    }
    return $ips;
}

function chequeo_ip_publica(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/**
 * Una URL se puede pedir solo si es http o https, no lleva credenciales y TODAS las IPs a las que resuelve
 * son públicas. Resolver antes de pedir evita que un nombre apunte a la red interna.
 */
function chequeo_url_segura(string $url, callable $resolver): bool
{
    $p = parse_url($url);
    if ($p === false || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) {
        return false;
    }
    if (isset($p['user']) || isset($p['pass'])) {
        return false;
    }
    $ips = $resolver(trim($p['host'], '[]'));
    if (!$ips) {
        return false;
    }
    foreach ($ips as $ip) {
        if (!chequeo_ip_publica($ip)) {
            return false;
        }
    }
    return true;
}

/** Resuelve el destino de una redirección contra la URL actual. Solo acepta absolutas http(s) o "/ruta". */
function chequeo_url_absoluta(string $base, string $location): ?string
{
    if (preg_match('#^https?://#i', $location)) {
        return $location;
    }
    if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
        $p = parse_url($base);
        if ($p === false || empty($p['scheme']) || empty($p['host'])) {
            return null;
        }
        return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . $location;
    }
    return null;
}

/**
 * Fetcher real. Pide la URL SIN seguir redirecciones (las sigue chequeo_estado, validando cada salto) y
 * devuelve el código y el Location, o null si no hubo respuesta. No lee el cuerpo.
 *
 * @return array{status: int, location: ?string}|null
 */
function chequeo_http_get(string $url): ?array
{
    $contexto = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => CHEQUEO_TIMEOUT,
            'ignore_errors' => true,
            'follow_location' => 0,
            'header' => "User-Agent: VEZZA-Estado/1.0\r\nAccept: */*\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $fp = @fopen($url, 'r', false, $contexto);
    if ($fp === false) {
        return null;
    }
    $cabeceras = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    fclose($fp);
    if (!preg_match('#^HTTP/\S+\s+(\d{3})#', (string)($cabeceras[0] ?? ''), $m)) {
        return null;
    }
    $location = null;
    foreach ($cabeceras as $linea) {
        if (stripos($linea, 'Location:') === 0) {
            $location = trim(substr($linea, 9));
        }
    }
    return ['status' => (int)$m[1], 'location' => $location];
}

/** 'activo' si la URL responde 200 a 399 (tras hasta 3 redirecciones seguras). Todo lo demás es 'caido'. */
function chequeo_estado(string $url, callable $fetcher, callable $resolver): string
{
    for ($salto = 0; $salto <= CHEQUEO_MAX_REDIRECCIONES; $salto++) {
        if (!chequeo_url_segura($url, $resolver)) {
            return 'caido';
        }
        $r = $fetcher($url);
        if ($r === null) {
            return 'caido';
        }
        $status = (int)$r['status'];
        if ($status >= 300 && $status < 400 && !empty($r['location'])) {
            $siguiente = chequeo_url_absoluta($url, (string)$r['location']);
            if ($siguiente === null) {
                return 'caido';
            }
            $url = $siguiente;
            continue;
        }
        return $status >= 200 && $status < 400 ? 'activo' : 'caido';
    }
    return 'caido';
}

/** Guarda el resultado de un chequeo. Un servicio pasa a caído recién con CHEQUEO_FALLOS_PARA_CAIDO fallos seguidos. */
function servicio_registrar_chequeo(int $id, string $resultado): void
{
    $s = crud_find('servicios', $id);
    $datos = ['ultimo_chequeo' => date('Y-m-d H:i:s')];
    if ($resultado === 'activo') {
        $datos['estado_auto'] = 'activo';
        $datos['fallos_consecutivos'] = 0;
    } else {
        $fallos = (int)$s['fallos_consecutivos'] + 1;
        $datos['fallos_consecutivos'] = $fallos;
        if ($fallos >= CHEQUEO_FALLOS_PARA_CAIDO) {
            $datos['estado_auto'] = 'caido';
        }
    }
    tx(function () use ($id, $datos): void {
        crud_update('servicios', $id, $datos);
        servicio_sincronizar($id);
    });
}

/**
 * Chequea los servicios con chequeo activo y sincroniza los eventos de todos los demás: un override que
 * vence cambia el estado sin que nadie escriba. Un fetcher que falla cuenta como fallo y no corta al resto.
 *
 * @return array{chequeados: int, activos: int, fallidos: int}
 */
function chequear_servicios(callable $fetcher, callable $resolver): array
{
    $resumen = ['chequeados' => 0, 'activos' => 0, 'fallidos' => 0];
    foreach (q_all('SELECT id, url FROM servicios WHERE chequear = 1 AND url IS NOT NULL ORDER BY id') as $s) {
        try {
            $resultado = chequeo_estado((string)$s['url'], $fetcher, $resolver);
        } catch (Throwable $e) {
            error_log('[servicios] No se pudo chequear el servicio ' . $s['id'] . ': ' . $e->getMessage());
            $resultado = 'caido';
        }
        servicio_registrar_chequeo((int)$s['id'], $resultado);
        $resumen['chequeados']++;
        $resumen[$resultado === 'activo' ? 'activos' : 'fallidos']++;
    }
    foreach (q_all('SELECT id FROM servicios WHERE chequear = 0 OR url IS NULL ORDER BY id') as $s) {
        servicio_sincronizar((int)$s['id']);
    }
    return $resumen;
}
```

- [ ] **Step 4: Correr los tests y ver que pasan**

Run: `php vendor/bin/phpunit tests/ChequeoServiciosTest.php`
Expected: `OK`. Dos puntos a revisar si algo falla:
- `169.254.169.254` y `::ffff:127.0.0.1` los tiene que rechazar `chequeo_ip_publica` con `FILTER_FLAG_NO_RES_RANGE`. Si PHP 8.1 acepta alguno, agregar una comprobación explícita antes del `filter_var`: `str_starts_with($ip, '169.254.') || str_starts_with(strtolower($ip), '::ffff:')` → `false`, y dejar el test como está.

- [ ] **Step 5: Crear el script de cron**

Crear `scripts/chequear-servicios.php`:

```php
<?php
declare(strict_types=1);

// Chequea el estado de los servicios de los clientes. Se corre por Cron Jobs de hPanel cada 5 minutos.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../admin/includes/bootstrap.php';

$r = chequear_servicios('chequeo_http_get', 'chequeo_resolver');
echo sprintf("Chequeados: %d · activos: %d · con fallo: %d\n", $r['chequeados'], $r['activos'], $r['fallidos']);
```

- [ ] **Step 6: Probar el fetcher real contra la red, a mano**

Con MariaDB prendida y la base local migrada:

```bash
php -r 'require "admin/includes/bootstrap.php"; var_dump(chequeo_http_get("https://vezzadev.com/"), chequeo_http_get("http://vezzadev.com/"), chequeo_http_get("https://no-existe-vezza.invalid/"));'
```

Expected: el primero `["status"=>200, "location"=>NULL]`, el segundo `["status"=>301, ...]` con `location` a `https://vezzadev.com/`, el tercero `NULL`. Después:

```bash
php scripts/chequear-servicios.php
```

Expected: `Chequeados: 0 · activos: 0 · con fallo: 0` si la base local no tiene servicios con chequeo.

- [ ] **Step 7: Suite completa y commit**

```bash
php vendor/bin/phpunit
git add admin/includes/repos/chequeo_servicios.php scripts/chequear-servicios.php tests/ChequeoServiciosTest.php
git commit -m "Agregar el chequeo automático de servicios con guarda anti-SSRF" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Lectura del portal y API del portal

**Files:**
- Create: `admin/includes/repos/portal_servicios.php`
- Create: `admin/includes/portal_api.php`
- Create: `clientes/api/servicios.php`
- Create: `tests/PortalServiciosTest.php`
- Modify: `.htaccess` (agregar la regla de `/clientes/api/*`)

**Interfaces:**
- Consumes (Task 1): `servicio_estado_efectivo`, `servicio_override_vigente`, `servicio_eventos`, `SERVICIO_EVENTOS_PORTAL`, `servicios_create`. Del portal (fase 1): `session_boot_cliente()`, `require_cliente_api(): int`, `send_panel_headers()`, `csrf_check()`, `json_out()`, `HttpError`.
- Produces:
  - `portal_servicios_list(int $clienteId): array` → lista de `{id, nombre, url, estado, mensaje, ultimo_chequeo, eventos: [{estado, mensaje, creado_en}]}`.
  - `portal_api_run(callable $fn): void`: `$fn` recibe el `cliente_id` de la sesión como `int`.
  - `GET /clientes/api/servicios` → `{"data": [...]}` (Task 4 lo consume).

- [ ] **Step 1: Escribir los tests que fallan**

Crear `tests/PortalServiciosTest.php`:

```php
<?php
declare(strict_types=1);

final class PortalServiciosTest extends DbTestCase
{
    private int $a;
    private int $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->crearCliente('Cliente A');
        $this->b = $this->crearCliente('Cliente B');
    }

    private function servicio(int $cliente, string $nombre, array $extra = []): array
    {
        return servicios_create($extra + ['cliente_id' => $cliente, 'nombre' => $nombre]);
    }

    public function test_cada_cliente_ve_solo_sus_servicios(): void
    {
        $this->servicio($this->a, 'Sitio de A');
        $this->servicio($this->b, 'Sitio de B');
        $this->assertSame(['Sitio de A'], array_column(portal_servicios_list($this->a), 'nombre'));
        $this->assertSame(['Sitio de B'], array_column(portal_servicios_list($this->b), 'nombre'));
    }

    public function test_un_cliente_sin_servicios_recibe_una_lista_vacia(): void
    {
        $this->assertSame([], portal_servicios_list($this->a));
        $this->assertSame([], portal_servicios_list(999999));
    }

    public function test_no_expone_campos_internos(): void
    {
        $s = $this->servicio($this->a, 'Sitio', ['url' => 'https://a.test', 'chequear' => true, 'estado_manual' => 'mantenimiento', 'estado_manual_hasta' => '2099-01-01 00:00:00', 'mensaje' => 'Migrando']);
        $fila = portal_servicios_list($this->a)[0];
        $claves = array_keys($fila);
        sort($claves);
        $this->assertSame(['estado', 'eventos', 'id', 'mensaje', 'nombre', 'ultimo_chequeo', 'url'], $claves);
        $this->assertSame($s['id'], $fila['id']);
        $claves = array_keys($fila['eventos'][0]);
        sort($claves);
        $this->assertSame(['creado_en', 'estado', 'mensaje'], $claves);
    }

    public function test_el_estado_es_el_efectivo_y_sin_chequeo_ni_override_es_desconocido(): void
    {
        $this->servicio($this->a, 'Sin nada');
        $this->servicio($this->a, 'Forzado', ['estado_manual' => 'activo']);
        $porNombre = array_column(portal_servicios_list($this->a), null, 'nombre');
        $this->assertSame('desconocido', $porNombre['Sin nada']['estado']);
        $this->assertSame('activo', $porNombre['Forzado']['estado']);
    }

    public function test_el_mensaje_solo_se_ve_con_el_override_vigente(): void
    {
        $this->servicio($this->a, 'Vigente', ['estado_manual' => 'mantenimiento', 'mensaje' => 'Volvemos a las 18']);
        $v = $this->servicio($this->a, 'Vencido', ['estado_manual' => 'mantenimiento', 'mensaje' => 'Ya pasó', 'chequear' => true, 'url' => 'https://a.test']);
        crud_update('servicios', $v['id'], ['estado_manual_hasta' => '2000-01-01 00:00:00']);
        $porNombre = array_column(portal_servicios_list($this->a), null, 'nombre');
        $this->assertSame('Volvemos a las 18', $porNombre['Vigente']['mensaje']);
        $this->assertNull($porNombre['Vencido']['mensaje']);
    }

    public function test_ultimo_chequeo_solo_si_el_chequeo_esta_activo(): void
    {
        $con = $this->servicio($this->a, 'Con chequeo', ['chequear' => true, 'url' => 'https://a.test']);
        $sin = $this->servicio($this->a, 'Sin chequeo');
        crud_update('servicios', $con['id'], ['ultimo_chequeo' => '2026-10-01 11:05:00']);
        crud_update('servicios', $sin['id'], ['ultimo_chequeo' => '2026-10-01 11:05:00']);
        $porNombre = array_column(portal_servicios_list($this->a), null, 'nombre');
        $this->assertSame('2026-10-01 11:05:00', $porNombre['Con chequeo']['ultimo_chequeo']);
        $this->assertNull($porNombre['Sin chequeo']['ultimo_chequeo']);
    }

    public function test_trae_como_mucho_10_eventos_el_mas_nuevo_primero(): void
    {
        $s = $this->servicio($this->a, 'Inestable');
        foreach (range(1, 12) as $i) {
            crud_insert('servicio_eventos', [
                'servicio_id' => $s['id'],
                'estado' => $i % 2 === 0 ? 'activo' : 'caido',
                'origen' => 'auto',
                'mensaje' => "Evento $i",
            ]);
        }
        $eventos = portal_servicios_list($this->a)[0]['eventos'];
        $this->assertCount(10, $eventos);
        $this->assertSame('Evento 12', $eventos[0]['mensaje']);
        $this->assertSame('Evento 3', $eventos[9]['mensaje']);
    }

    public function test_una_sesion_de_admin_no_pasa_el_guard_del_portal(): void
    {
        $_SESSION = ['admin' => true];
        $this->assertHttp(401, fn() => require_cliente_api());
    }
}
```

- [ ] **Step 2: Correr los tests y ver que fallan**

Run: `php vendor/bin/phpunit tests/PortalServiciosTest.php`
Expected: FAIL con `Call to undefined function portal_servicios_list()`.

- [ ] **Step 3: Implementar `admin/includes/repos/portal_servicios.php`**

```php
<?php
declare(strict_types=1);

/**
 * Servicios del cliente de la sesión con el estado que corresponde mostrar. $clienteId sale SIEMPRE de la
 * sesión, nunca del request. No reutiliza servicios_list: ese es del admin y no filtra por cliente.
 * Solo se devuelven los campos que el cliente puede ver.
 */
function portal_servicios_list(int $clienteId): array
{
    $ahora = date('Y-m-d H:i:s');
    $filas = q_all('SELECT * FROM servicios WHERE cliente_id = ? ORDER BY nombre, id', [$clienteId]);
    return array_map(function (array $s) use ($ahora): array {
        $eventos = array_map(
            fn(array $e): array => ['estado' => $e['estado'], 'mensaje' => $e['mensaje'], 'creado_en' => $e['creado_en']],
            servicio_eventos((int)$s['id'], SERVICIO_EVENTOS_PORTAL)
        );
        return [
            'id' => (int)$s['id'],
            'nombre' => $s['nombre'],
            'url' => $s['url'],
            'estado' => servicio_estado_efectivo($s, $ahora),
            'mensaje' => servicio_override_vigente($s, $ahora) ? $s['mensaje'] : null,
            'ultimo_chequeo' => (int)$s['chequear'] === 1 ? $s['ultimo_chequeo'] : null,
            'eventos' => $eventos,
        ];
    }, $filas);
}
```

- [ ] **Step 4: Implementar `admin/includes/portal_api.php`**

```php
<?php
declare(strict_types=1);

/**
 * Runner de la API del portal (/clientes/api/*). Equivale a api_run del admin pero con la sesión del cliente:
 * 401 sin sesión, CSRF en métodos no GET, y el cliente_id de la sesión (nunca del request) llega a $fn.
 * Una sesión de admin no sirve acá, y una de cliente no sirve en /api/*.
 */
function portal_api_run(callable $fn): void
{
    send_panel_headers();
    try {
        session_boot_cliente();
        $clienteId = require_cliente_api();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
            csrf_check(is_string($token) ? $token : null);
        }
        $fn($clienteId);
    } catch (HttpError $e) {
        $cuerpo = ['error' => $e->getMessage()];
        if ($e->campos) {
            $cuerpo['campos'] = $e->campos;
        }
        json_out($e->status, $cuerpo);
    } catch (Throwable $e) {
        error_log('[portal] ' . $e);
        json_out(500, ['error' => 'Error interno']);
    }
}
```

- [ ] **Step 5: Crear el endpoint `clientes/api/servicios.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../../admin/includes/bootstrap.php';

portal_api_run(function (int $clienteId): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        throw new HttpError(405, 'Método no permitido');
    }
    json_out(200, ['data' => portal_servicios_list($clienteId)]);
});
```

- [ ] **Step 6: Agregar la regla de `.htaccess`**

En `.htaccess`, dentro del bloque "Portal de clientes", después de la línea `RewriteRule ^clientes/(login|activar|recuperar|logout)$ clientes/$1.php [L,QSA]`, agregar:

```apache
  RewriteRule ^clientes/api/([a-z-]+)$ clientes/api/$1.php [L,QSA]
```

- [ ] **Step 7: Correr los tests y ver que pasan**

Run: `php vendor/bin/phpunit tests/PortalServiciosTest.php`
Expected: `OK`.

- [ ] **Step 8: Probar el endpoint a mano**

Con Apache y MariaDB prendidas (XAMPP, `http://localhost:8080`):

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8080/clientes/api/servicios
```

Expected: `401` (sin sesión de cliente).

- [ ] **Step 9: Suite completa y commit**

```bash
php vendor/bin/phpunit
git add admin/includes/repos/portal_servicios.php admin/includes/portal_api.php clientes/api/servicios.php tests/PortalServiciosTest.php .htaccess
git commit -m "Agregar la lectura de servicios del portal y su API" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Pantalla Estado del portal (`/clientes`)

**Files:**
- Modify: `clientes/index.php`
- Modify: `clientes/assets/portal.js`
- Modify: `clientes/assets/portal.css`
- Modify: `admin/includes/portal_layout.php` (`PORTAL_ASSET_V` de `'2'` a `'3'`)

**Interfaces:**
- Consumes (Task 3): `GET /clientes/api/servicios` → `{"data": [{id, nombre, url, estado, mensaje, ultimo_chequeo, eventos: [{estado, mensaje, creado_en}]}]}`.
- Produces: nada que otra tarea use.

Esta tarea es de interfaz y no tiene test automatizado (el proyecto no tiene infraestructura de tests de JS). Se verifica a mano en el paso 5, con un cliente de prueba.

- [ ] **Step 1: Reemplazar el contenido de `clientes/index.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

$clienteId = require_cliente();
$nombre = (string)q_val('SELECT nombre FROM clientes WHERE id = ?', [$clienteId]);

portal_inicio('Estado');
?>
<main id="main" class="portal-main" tabindex="-1">
  <header class="page-head">
    <h1>Hola, <?= e($nombre) ?></h1>
    <form method="post" action="/clientes/logout">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <button class="btn btn-chico" type="submit">Salir</button>
    </form>
  </header>
  <section aria-labelledby="estado-titulo">
    <h2 id="estado-titulo">Estado de tus servicios</h2>
    <div id="servicios" class="lista" data-estado-servicios aria-live="polite">
      <p class="item-sub">Cargando…</p>
    </div>
    <p id="estado-actualizado" class="item-sub" role="status"></p>
  </section>
</main>
<?php
portal_fin();
```

- [ ] **Step 2: Agregar la pantalla de Estado a `clientes/assets/portal.js`**

Al final del archivo (después del bloque de `[data-ver-clave]`), agregar:

```js

// ---------- Pantalla Estado ----------
// Todo lo que llega de la API se pinta con textContent (vía el helper el): nunca innerHTML.
const el = (tag, attrs = {}, ...hijos) => {
  const nodo = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'class') nodo.className = v;
    else if (k === 'text') nodo.textContent = v;
    else if (v === true) nodo.setAttribute(k, '');
    else nodo.setAttribute(k, String(v));
  }
  for (const h of hijos.flat()) {
    if (h === null || h === undefined || h === false) continue;
    nodo.append(h instanceof Node ? h : document.createTextNode(String(h)));
  }
  return nodo;
};

const contenedorEstado = document.querySelector('[data-estado-servicios]');
if (contenedorEstado) {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
  const actualizado = document.getElementById('estado-actualizado');
  const ESTADOS = {
    activo: ['Activo', 'badge-ok'],
    mantenimiento: ['Mantenimiento', 'badge-warn'],
    caido: ['Caído', 'badge-danger'],
    desconocido: ['Sin verificar', ''],
  };

  // 'YYYY-MM-DD HH:MM:SS' (hora argentina, la misma en servidor y cliente) -> '1/10/2026 11:05'
  const fechaHora = (dt) => {
    const [fecha, hora] = String(dt).split(' ');
    const [y, m, d] = fecha.split('-').map(Number);
    return `${d}/${m}/${y} ${(hora || '').slice(0, 5)}`;
  };

  async function pedirServicios() {
    const res = await fetch('/clientes/api/servicios', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-Token': csrf },
    });
    if (res.status === 401) {
      location.href = '/clientes/login';
      throw new Error('Tu sesión expiró');
    }
    if (!res.ok) throw new Error('No pudimos cargar el estado. Lo reintentamos en un rato.');
    return (await res.json()).data;
  }

  function tarjeta(s, abierto) {
    const [texto, clase] = ESTADOS[s.estado] ?? ESTADOS.desconocido;
    return el('article', { class: 'item servicio' },
      el('div', { class: 'item-top' },
        el('span', { class: 'item-titulo', text: s.nombre }),
        el('span', { class: `badge ${clase}`.trim(), text: texto })),
      s.url ? el('div', { class: 'item-sub', text: s.url }) : null,
      s.mensaje ? el('p', { class: 'servicio-mensaje', text: s.mensaje }) : null,
      s.ultimo_chequeo ? el('div', { class: 'item-sub', text: `Última verificación: ${fechaHora(s.ultimo_chequeo)}` }) : null,
      s.eventos.length
        ? el('details', { class: 'servicio-historial', 'data-id': s.id, open: abierto },
          el('summary', { text: 'Historial' }),
          el('ul', { class: 'servicio-eventos' }, s.eventos.map((e) => el('li', {},
            el('span', { class: 'item-sub', text: fechaHora(e.creado_en) }), ' ',
            el('strong', { text: ESTADOS[e.estado]?.[0] ?? e.estado }),
            e.mensaje ? ` · ${e.mensaje}` : null))))
        : null);
  }

  async function cargarEstado() {
    try {
      const servicios = await pedirServicios();
      // El refresco no tiene que cerrar los historiales que el cliente abrió.
      const abiertos = new Set([...contenedorEstado.querySelectorAll('details[open]')].map((d) => d.dataset.id));
      contenedorEstado.replaceChildren(...(servicios.length
        ? servicios.map((s) => tarjeta(s, abiertos.has(String(s.id))))
        : [el('div', { class: 'vacio', text: 'Todavía no tenemos servicios cargados para vos.' })]));
      actualizado.textContent = `Actualizado ${new Date().toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' })}`;
    } catch (err) {
      actualizado.textContent = err.message;
    }
  }

  // Cada 30 s; con la pestaña oculta no pide nada y al volver actualiza de una.
  let temporizador;
  const programar = () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(async () => {
      if (!document.hidden) await cargarEstado();
      programar();
    }, 30000);
  };
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) { cargarEstado(); programar(); }
  });
  cargarEstado().finally(programar);
}
```

- [ ] **Step 3: Agregar los estilos a `clientes/assets/portal.css`**

Al final del archivo:

```css

/* Estado de servicios */
.portal-main h2 { margin: 8px 0 12px; }
.servicio { display: grid; gap: 6px; }
.servicio-mensaje { margin: 0; padding: 8px 10px; border-radius: 8px; background: var(--warn-soft); color: var(--warn); font-size: .9rem; }
.servicio-historial summary { display: flex; align-items: center; min-height: 44px; color: var(--accent); font-weight: 600; cursor: pointer; }
.servicio-eventos { display: grid; gap: 6px; margin: 0; padding: 0; list-style: none; font-size: .9rem; }
#estado-actualizado { margin-top: 12px; }
```

- [ ] **Step 4: Subir la versión de los assets del portal**

En `admin/includes/portal_layout.php`, cambiar `const PORTAL_ASSET_V = '2';` por `const PORTAL_ASSET_V = '3';`.

- [ ] **Step 5: Verificar a mano en el navegador**

Con XAMPP (Apache + MariaDB) prendido y la base local migrada (`php db/migrate.php`):

1. Crear un cliente de prueba con acceso: `php -r 'require "admin/includes/bootstrap.php"; $c = crud_insert("clientes", ["nombre" => "Prueba Portal", "email" => "prueba@sol.test"]); $u = crud_insert("usuarios_cliente", ["cliente_id" => $c, "email" => "prueba@sol.test", "password_hash" => password_hash("secreta1", PASSWORD_BCRYPT), "activo" => 1]); foreach ([["Sitio web", "activo", null], ["Tienda", "mantenimiento", "Migramos el hosting, volvemos a las 18"], ["Mail", null, null]] as [$n, $m, $msg]) { crud_insert("servicios", ["cliente_id" => $c, "nombre" => $n, "url" => "https://" . strtolower(str_replace(" ", "", $n)) . ".sol.test", "estado_manual" => $m, "mensaje" => $msg]); } foreach (q_all("SELECT id FROM servicios WHERE cliente_id = ?", [$c]) as $s) { servicio_sincronizar((int)$s["id"]); } echo "cliente $c\n";'`
2. Abrir `http://localhost:8080/clientes/login`, ingresar con `prueba@sol.test` / `secreta1`.
3. Comprobar en `/clientes`: tres tarjetas. "Sitio web" con insignia **Activo**, "Tienda" con **Mantenimiento** y el mensaje, "Mail" con **Sin verificar** (no "Activo"). "Historial" abre y muestra el evento.
4. Abrir un historial y esperar 30 s: no se cierra al refrescar.
5. Achicar la ventana a 360 px de ancho: sin scroll horizontal, el botón "Historial" se toca bien.
6. Cambiar el estado de "Mail" en la base (`UPDATE servicios SET estado_manual = 'caido' WHERE nombre = 'Mail'`), esperar 30 s y comprobar que la tarjeta cambia a **Caído** sin recargar. (El historial de "Mail" se completa recién cuando corre `servicio_sincronizar`, que es lo esperado: el cron lo hace.)
7. Salir (`Salir`) y volver a `/clientes/api/servicios`: `401`.

Expected: todo lo anterior. Si la pantalla queda en "Cargando…", abrir la consola del navegador y revisar el error de red.

- [ ] **Step 6: Commit**

```bash
git add clientes/index.php clientes/assets/portal.js clientes/assets/portal.css admin/includes/portal_layout.php
git commit -m "Agregar la pantalla de estado de servicios al portal" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Admin de servicios (API, pantalla, menú y pestaña en la ficha)

**Files:**
- Create: `api/servicios.php`
- Create: `admin/servicios.php`
- Create: `admin/assets/mod-servicios.js`
- Create: `admin/assets/servicios.js`
- Modify: `admin/includes/layout.php` (`NAV_MAS`, `ICONOS`, `PANEL_ASSET_V` de `'4'` a `'5'`)
- Modify: `admin/cliente.php` (cargar `mod-servicios.js`)
- Modify: `admin/assets/cliente.js` (pestaña Servicios)
- Modify: `.htaccess` (ruta `/admin/servicios`)

**Interfaces:**
- Consumes (Task 1): `servicios_list`, `servicios_get`, `servicios_create`, `servicios_update`, `servicios_delete`. Del admin: `api_resource`, `Panel` (`el`, `get`, `post`, `put`, `del`, `modalForm`, `badge`, `vacio`, `boton`, `acciones`, `clientes`, `opcionesClientes`, `fmtFechaHora`, `manejarError`).
- Produces: `Servicios` (módulo JS) con `nuevo(valores)`, `editar(s)` e `item(s, recargar)`, que la pestaña de la ficha reutiliza igual que `Fixs`.

Esta tarea es de interfaz y se verifica a mano en el paso 8. El test que sí existe es `IconosTest`, que exige un ícono para cada ítem del menú.

- [ ] **Step 1: Crear `api/servicios.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource([
    'list' => 'servicios_list',
    'get' => 'servicios_get',
    'create' => 'servicios_create',
    'update' => 'servicios_update',
    'delete' => 'servicios_delete',
]);
```

- [ ] **Step 2: Crear la página `admin/servicios.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();
layout_start('Servicios', 'servicios', ['mod-servicios.js', 'servicios.js']);
?>
<div class="toolbar">
  <select class="filtro" id="filtro-cliente" aria-label="Filtrar por cliente">
    <option value="">Todos los clientes</option>
  </select>
</div>
<div id="lista" class="lista" aria-live="polite"></div>
<?php
layout_end();
```

- [ ] **Step 3: Crear el módulo `admin/assets/mod-servicios.js`**

```js
'use strict';

const Servicios = {
  ETQ: { activo: 'Activo', mantenimiento: 'Mantenimiento', caido: 'Caído', desconocido: 'Sin verificar' },
  VARIANTE: { activo: 'ok', mantenimiento: 'warn', caido: 'danger', desconocido: '' },

  campos(clientes) {
    return [
      { name: 'cliente_id', label: 'Cliente', type: 'select', required: true, vacio: 'Elegí un cliente', options: Panel.opcionesClientes(clientes) },
      { name: 'nombre', label: 'Nombre del servicio', required: true, placeholder: 'Sitio web' },
      { name: 'url', label: 'URL (opcional)', placeholder: 'https://sitio-del-cliente.com' },
      { name: 'chequear', label: 'Chequear automáticamente cada 5 minutos', type: 'checkbox', ayuda: 'Necesita una URL.' },
      {
        name: 'estado_manual', label: 'Estado manual', type: 'select', vacio: 'Automático (sin estado manual)',
        options: [['activo', 'Activo'], ['mantenimiento', 'Mantenimiento'], ['caido', 'Caído']],
        ayuda: 'Tiene prioridad sobre el chequeo automático.',
      },
      { name: 'estado_manual_hasta', label: 'El estado manual vence (opcional)', type: 'datetime-local' },
      { name: 'mensaje', label: 'Mensaje para el cliente (opcional)', type: 'textarea', ayuda: 'Se muestra mientras el estado manual esté vigente.' },
    ];
  },

  async nuevo(valores = {}) {
    const clientes = await Panel.clientes();
    return Panel.modalForm({ titulo: 'Nuevo servicio', campos: this.campos(clientes), valores, enviar: (d) => Panel.post('/api/servicios', d) });
  },

  async editar(s) {
    const clientes = await Panel.clientes();
    return Panel.modalForm({
      titulo: 'Editar servicio', campos: this.campos(clientes), valores: s,
      enviar: (d) => Panel.put(`/api/servicios/${s.id}`, d),
      eliminar: () => Panel.del(`/api/servicios/${s.id}`),
    });
  },

  origen(s) {
    if (s.override_vigente) return 'Estado manual';
    return Number(s.chequear) === 1 ? 'Chequeo automático' : 'Sin chequeo automático';
  },

  item(s, recargar) {
    const { el } = Panel;
    return el('div', { class: 'item' },
      el('div', { class: 'item-top' },
        el('button', { type: 'button', class: 'boton-texto item-titulo', text: s.nombre, onclick: async () => { if (await Servicios.editar(s)) recargar(); } }),
        Panel.badge(this.ETQ[s.estado] ?? s.estado, this.VARIANTE[s.estado])),
      el('div', { class: 'item-sub', text: [s.cliente_nombre, s.url].filter(Boolean).join(' · ') }),
      el('div', { class: 'meta' },
        Panel.badge(this.origen(s)),
        s.ultimo_chequeo && Number(s.chequear) === 1 ? Panel.badge(`Chequeado ${Panel.fmtFechaHora(s.ultimo_chequeo)}`) : null),
      s.override_vigente && s.mensaje ? el('p', { class: 'item-sub', text: s.mensaje }) : null,
      s.eventos.length
        ? el('details', {},
          el('summary', { text: 'Historial' }),
          el('ul', {}, s.eventos.map((e) => el('li', {
            text: `${Panel.fmtFechaHora(e.creado_en)} · ${this.ETQ[e.estado] ?? e.estado} (${e.origen === 'manual' ? 'manual' : 'automático'})${e.mensaje ? ` · ${e.mensaje}` : ''}`,
          }))))
        : null);
  },
};
```

- [ ] **Step 4: Crear `admin/assets/servicios.js`**

```js
'use strict';

(() => {
  const { el } = Panel;
  const lista = document.getElementById('lista');
  const filtro = document.getElementById('filtro-cliente');

  Panel.acciones(Panel.boton('+ Nuevo servicio', async () => {
    if (await Servicios.nuevo()) cargar();
  }, 'primario'));

  async function cargar() {
    try {
      const servicios = await Panel.get('/api/servicios', { cliente_id: filtro.value });
      Panel.llenar(lista, servicios.length
        ? servicios.map((s) => Servicios.item(s, cargar))
        : Panel.vacio('Todavía no hay servicios cargados.'));
    } catch (err) {
      Panel.manejarError(err);
    }
  }

  Panel.clientes()
    .then((clientes) => {
      for (const [valor, texto] of Panel.opcionesClientes(clientes)) filtro.append(el('option', { value: valor, text: texto }));
    })
    .catch(Panel.manejarError);
  filtro.addEventListener('change', cargar);
  cargar();
})();
```

- [ ] **Step 5: Agregar el menú y el ícono en `admin/includes/layout.php`**

Cambiar `const PANEL_ASSET_V = '4';` por `const PANEL_ASSET_V = '5';`.

En `NAV_MAS`, agregar después de `'fixs'`:

```php
    'servicios' => ['/admin/servicios', 'Servicios'],
```

En `ICONOS`, agregar después de la línea de `'fixs'` (ícono "activity" de Lucide):

```php
    'servicios' => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
```

- [ ] **Step 6: Agregar la pestaña "Servicios" a la ficha del cliente**

En `admin/cliente.php`, agregar `'mod-servicios.js'` a la lista de scripts, antes de `'mod-acceso.js'`:

```php
layout_start('Cliente', 'clientes', [
    'mod-clientes.js', 'mod-procesos.js', 'mod-cobros.js', 'mod-fixs.js', 'mod-eventos.js', 'mod-servicios.js', 'mod-acceso.js', 'cliente.js',
], ['cliente-id' => $id]);
```

En `admin/assets/cliente.js`, agregar en `TABS`, después de la pestaña `fixs`:

```js
    { id: 'servicios', label: 'Servicios', render: tabEntidad(Servicios, '/api/servicios', '+ Nuevo servicio', 'Este cliente todavía no tiene servicios.') },
```

- [ ] **Step 7: Agregar la ruta en `.htaccess`**

En el bloque del panel admin, cambiar la regla:

```apache
  RewriteRule ^admin/(clientes|procesos|cobros|gastos|tareas|fixs|agenda|reportes|migraciones)$ admin/$1.php [L,QSA]
```

por:

```apache
  RewriteRule ^admin/(clientes|procesos|cobros|gastos|tareas|fixs|servicios|agenda|reportes|migraciones)$ admin/$1.php [L,QSA]
```

- [ ] **Step 8: Verificar a mano en el navegador**

Con XAMPP prendido, entrar a `http://localhost:8080/admin` con tu usuario:

1. En el menú (en celular, dentro de "Más") aparece **Servicios** con su ícono, y `/admin/servicios` carga.
2. **+ Nuevo servicio**: cliente "Prueba Portal", nombre "Blog", URL `https://blog.sol.test`, marcar "Chequear automáticamente" → guarda. Desmarcar la URL y volver a guardar con el chequeo marcado → error "Cargá la URL para poder chequearlo" bajo el campo URL.
3. Editar "Blog": Estado manual **Mantenimiento**, vencimiento mañana, mensaje "Cambio de hosting" → la tarjeta pasa a **Mantenimiento · Estado manual**, y "Historial" muestra el evento manual con el mensaje.
4. Editar "Blog" y dejar el estado manual en "Automático": vuelve a **Sin verificar** (todavía no corrió el chequeo).
5. El filtro por cliente filtra la lista. En `/admin/clientes/<id>` hay una pestaña **Servicios** con "+ Nuevo servicio" que ya trae el cliente elegido.
6. Entrar al portal como "Prueba Portal" (`/clientes`) y comprobar que el cambio del paso 3 se ve en menos de 30 s.
7. Ancho de 360 px: sin scroll horizontal en `/admin/servicios`.

- [ ] **Step 9: Suite completa y commit**

```bash
php vendor/bin/phpunit
git add api/servicios.php admin/servicios.php admin/assets/mod-servicios.js admin/assets/servicios.js admin/includes/layout.php admin/cliente.php admin/assets/cliente.js .htaccess
git commit -m "Agregar la gestión de servicios al admin" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Verificación del deploy, documentación del cron y revisión final

**Files:**
- Modify: `scripts/verificar-portal.sh`
- Modify: `DEPLOY.md`

**Interfaces:**
- Consumes: todo lo anterior.
- Produces: nada.

- [ ] **Step 1: Ampliar `scripts/verificar-portal.sh`**

Después de la línea `esperar 401 "/api/acceso-cliente?cliente_id=1"`, agregar:

```bash
esperar 401 /clientes/api/servicios
esperar 401 /api/servicios
esperar 302 /admin/servicios
```

Y en la lista de rutas que tienen que dar 404 (`for ruta in /admin/includes/auth_cliente.php ...`), agregar:

```bash
            /admin/includes/repos/servicios.php /admin/includes/repos/chequeo_servicios.php \
            /admin/includes/repos/portal_servicios.php /admin/includes/portal_api.php \
            /scripts/chequear-servicios.php
```

(la lista queda dentro del mismo `for`, con `\` al final de cada línea menos la última).

- [ ] **Step 2: Documentar el cron en `DEPLOY.md`**

Al final de la sección "Portal de clientes (`/clientes`)", agregar:

```markdown
### Estado de servicios y chequeo automático

La fase de servicios **no necesita migración ni variables nuevas** en el `.env`: las tablas ya las creó `002_portal`. Alcanza con el `git push`.

Para que los servicios con "Chequear automáticamente" se verifiquen solos, hay que crear una tarea en hPanel:

1. hPanel → **Avanzado** → **Cron Jobs**.
2. Tipo **PHP**, y en el comando la ruta completa del script. La ruta exacta es la que aparece arriba en el **Administrador de archivos** cuando abrís `public_html`; queda así: `/home/<usuario>/domains/vezzadev.com/public_html/scripts/chequear-servicios.php`.
3. Frecuencia: **cada 5 minutos**.
4. Para probarlo antes de dejarlo fijo, ponelo una vez en "cada minuto", esperá un minuto y revisá en `/admin/servicios` que los servicios con chequeo muestren "Chequeado <hora>". Después pasalo a cada 5 minutos.

El script solo corre por línea de comandos: abrirlo desde el navegador da 404.

**Qué cuenta como caído:** un servicio pasa a Caído recién con **2 chequeos seguidos** que fallan (código distinto de 200 a 399, error de red, timeout de 8 s o una URL que apunte a una IP privada). Si un sitio protegido responde 401 o 403 y querés que figure Activo, forzalo desde el admin con un estado manual sin vencimiento.

**Si no ves "Cron Jobs" en Avanzado:** avisame. El plan alternativo es un Schedule Trigger de n8n que llame a un endpoint protegido con un token (`CRON_TOKEN` en el `.env`); no está construido todavía.
```

- [ ] **Step 3: Correr toda la suite y comprobar el conteo**

Run: `php vendor/bin/phpunit`
Expected: `OK`, con la cantidad de tests de la línea base más los de esta fase (`ServiciosTest`, `ChequeoServiciosTest`, `PortalServiciosTest`) y ninguno de los anteriores roto.

- [ ] **Step 4: Commit de la documentación**

```bash
git add scripts/verificar-portal.sh DEPLOY.md
git commit -m "Documentar el chequeo de servicios y ampliar la verificación del portal" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 5: Revisión final de la rama completa**

Pedir una revisión de toda la rama (`git diff main...portal-clientes-fase-2`) con foco en: aislamiento por cliente en `portal_servicios_list` y el endpoint, que la guarda anti-SSRF se aplique en cada redirección, que `servicio_sincronizar` sea idempotente, y que ninguna pantalla use `innerHTML` con datos. Corregir lo que encuentre antes de mergear.

- [ ] **Step 6: Merge, push y verificación en producción**

```bash
git switch main
git merge --ff-only portal-clientes-fase-2
git push origin main
```

Esperar el despliegue de Hostinger (hPanel → Git: estado "Completado" con el último commit). Después:

```bash
scripts/verificar-portal.sh https://vezzadev.com
```

Expected: `Todo OK`. Luego crear la tarea de cron según `DEPLOY.md`, cargar un servicio real con chequeo (por ejemplo `https://vezzadev.com`) y comprobar, 5 minutos después, que `/admin/servicios` lo muestra **Activo** con su hora de chequeo, y que el cliente lo ve en `/clientes`.

---

## Self-review

**Cobertura del spec (fase 2):**
- Estado efectivo en una única función compartida por portal y admin → Task 1 (`servicio_estado_efectivo`), usada en Task 3.
- Eventos solo cuando cambia el estado → Task 1 (`servicio_sincronizar`) + tests.
- Override con vencimiento que vence solo → Task 1 y Task 2 (runner).
- Panel de estado del cliente con insignias, mensaje, última verificación, línea de tiempo, refresco 30 s con pausa → Task 4.
- Admin de servicios: CRUD, override (estado, mensaje, vencimiento), eventos → Task 5; pestaña en la ficha → Task 5.
- Chequeo automático: cron CLI cada 5 min, anti-flapping de 2 fallos, SSRF (http/https, 8 s, 3 redirecciones, IPs privadas resolviendo antes) → Task 2, y documentación del cron en Task 6.
- API del portal separada (`/clientes/api/*`), `require_cliente_api()`, CSRF en no GET, aislamiento con `cliente_id` de la sesión → Task 3.
- `.htaccess` con las rutas nuevas, subir `PANEL_ASSET_V` → Tasks 3 y 5; `PORTAL_ASSET_V` → Task 4.
- `scripts/verificar-portal.sh` → Task 6.
- Lo que el spec asigna a otras fases no está acá a propósito: `/admin/planes`, `/admin/solicitudes`, tickets, tareas-clientes y los contadores del dashboard son de las fases 3 y 4. La asociación `servicios.suscripcion_id` queda sin tocar hasta la fase 4.
- Hueco declarado: el respaldo de cron por n8n (spec 8) queda fuera, ver "Decisiones de esta fase", punto 6.

**Búsqueda de placeholders:** el plan no deja "TODO" ni pasos sin código. Hay dos notas de corrección de tests (Task 2 y Task 3) con el código exacto a usar.

**Consistencia de nombres:** `servicio_estado_efectivo`, `servicio_override_vigente`, `servicio_sincronizar`, `servicio_eventos`, `servicios_*`, `chequeo_*`, `servicio_registrar_chequeo`, `chequear_servicios`, `portal_servicios_list` y `portal_api_run` se definen en la tarea que los produce y se usan con la misma firma en las siguientes. La API del portal devuelve exactamente las claves que `portal.js` lee (`id`, `nombre`, `url`, `estado`, `mensaje`, `ultimo_chequeo`, `eventos[].estado|mensaje|creado_en`).

**Review Focus:** las cinco líneas tienen test: 1 → `test_un_override_que_vence_solo_registra_el_evento_al_sincronizar` y `test_el_runner_registra_el_evento_de_un_override_vencido...`; 2 → el data provider `urlsPrivadasOMalas` y `test_una_redireccion_hacia_una_ip_privada_no_se_pide`; 3 → `test_un_fetcher_que_explota_cuenta_como_fallo...`; 4 → `test_cada_cliente_ve_solo_sus_servicios` y `test_no_expone_campos_internos`; 5 → `test_sin_chequeo_y_sin_override_es_desconocido...` y `test_apagar_el_chequeo_deja_de_mostrar_el_resultado_viejo`.
