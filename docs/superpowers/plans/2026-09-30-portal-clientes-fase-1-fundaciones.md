# Portal de clientes — Fase 1: Fundaciones — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que un cliente pueda recibir una invitación del admin, activar su cuenta, ingresar a `/clientes` con email y contraseña, y recuperar su contraseña, con sesiones totalmente separadas de las del admin, y con todo el esquema de datos del portal (migración 002) ya creado.

**Architecture:** El admin no cambia de comportamiento. Se extrae de `session_boot()` una función por ámbito (`session_boot_ambito`) y el portal usa su propia cookie `vezza_cliente` y su propio flag de sesión. Tabla nueva `usuarios_cliente` (un acceso por cliente), tokens de un solo uso guardados hasheados, mails por un webhook de n8n con respaldo manual (el admin ve el link). La migración 002 crea **todo** el esquema del portal de una vez (planes, suscripciones, servicios, tickets, etc.); las fases 2 a 4 solo agregan código sobre tablas ya existentes.

**Tech Stack:** PHP 8.1+ plano (sin frameworks), MySQL/MariaDB, JS puro, PHPUnit 10.5. Sin build. Deploy por `git push` a `main` (Hostinger).

**Spec:** `docs/superpowers/specs/2026-09-30-portal-clientes-design.md` (secciones 4, 5, 8 y 9, y la fase 1 de la sección 11).

## Global Constraints

Valores copiados del spec. Aplican a todas las tareas.

- Contraseña: **mínimo 6 caracteres**, guardada con `password_hash`.
- Cookie del portal: `vezza_cliente`, `SameSite=Strict`, `HttpOnly`, `Secure` en producción, 7 días.
- Tokens de invitación y recuperación: 32 bytes aleatorios, se guarda solo el hash (sha256), de un solo uso. La invitación vence a las 72 h.
- Límite de intentos de login del portal: **5 por IP y 5 por cuenta (email) en 15 minutos** (ámbito `cliente` de `login_intentos`).
- `recuperar` responde igual exista o no el email.
- Una sesión de cliente nunca cumple `auth_logged()` del admin, y una de admin nunca cumple el guard del portal.
- Aislamiento por cliente: el `cliente_id` sale de la sesión, nunca del request; lo ajeno responde **404**, no 403.
- La migración es **aditiva**: no se renombra ni borra nada existente. La tabla `suscripciones` (gastos propios del admin) no se toca.
- El admin se comporta igual que antes: los 125 tests existentes siguen pasando.
- El JS renderiza datos con `textContent` (o `Panel.el`), nunca con `innerHTML` para contenido del usuario.
- Sin secretos en el repo: todo va en `.env`. Variables nuevas: `MAIL_WEBHOOK_URL`, `MAIL_WEBHOOK_SECRET`, `PORTAL_URL`.
- Tema claro, sin modo oscuro: fondo `#F5F3EE`, tinta `#15131C`, índigo `#3D2FE0`; Manrope y Sora. Voz rioplatense con voseo.
- Todas las pantallas se usan en un celular de 360 px de ancho.
- Compatibilidad: PHP 8.1 (`composer.json` fija `platform.php = 8.1.0`). No usar sintaxis ni funciones de 8.2 o posterior.
- Commits en español, en el estilo del repo ("Agregar ...", "Hacer que ..."), y cada uno termina con el trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` (se pasa con un segundo `-m`).

## Review Focus

Entradas y condiciones que el spec implica pero que no salen de sus casos obvios. Cada línea tiene su test en la tarea dueña del código.

1. **Email con mayúsculas y espacios** (`"  Ana@Sol.COM "`) al ingresar: tiene que entrar igual. → Task 4.
2. **Token malformado** en el link (vacío, corto, mayúsculas, caracteres raros, o `?token[]=x`): página de "link vencido", nunca un error 500. → Task 5 (función) y Task 7 (página, `portal_pagina_clave` trata lo no-string como vacío).
3. **Contraseña con espacios al principio o al final, o de más de 72 bytes** (bcrypt corta en 72): los espacios se respetan tal cual y más de 72 bytes se rechaza con 422, sin truncar en silencio. → Task 4.
4. **Reinvitar** a un cliente que ya tiene acceso activo, o desactivado, y **invitar con el email de otro cliente**: no pisa la contraseña actual, la invitación nueva invalida la anterior, y el email ajeno da 409. → Task 6.
5. **Desactivar el acceso con la sesión abierta**: el siguiente request recibe 401 (la sesión se relee de la base). → Task 4.

## Estructura de archivos

**Crear**
- `db/migrations/002_portal.sql`: esquema completo del portal.
- `admin/includes/auth_cliente.php`: sesión, login, guards, tokens y recuperación del portal.
- `admin/includes/mail.php`: envío de mails por webhook de n8n y `portal_url()`.
- `admin/includes/portal_layout.php`: cabecera y pantalla de contraseña compartida del portal.
- `admin/includes/repos/acceso_cliente.php`: lado admin (invitar, desactivar, consultar).
- `api/acceso-cliente.php`: endpoint admin del acceso.
- `clientes/index.php`, `login.php`, `activar.php`, `recuperar.php`, `logout.php`, `.htaccess`.
- `clientes/assets/portal.css`, `clientes/assets/portal.js`.
- `admin/assets/mod-acceso.js`: bloque "Acceso al portal" de la ficha.
- `scripts/verificar-portal.sh`.
- Tests: `MigracionPortalTest`, `MailTest`, `AuthClienteTest`, `TokensClienteTest`, `AccesoClienteTest`.

**Modificar**
- `admin/includes/auth.php`: extraer `session_boot_ambito`; `login_intentos` con ámbito.
- `tests/DbTestCase.php`: helpers `crearCliente` y `crearUsuarioCliente`.
- `tests/AuthTest.php`, `tests/SesionesTest.php`: tests nuevos.
- `.htaccess`: rutas del portal.
- `admin/cliente.php`, `admin/assets/cliente.js`: bloque de acceso.
- `admin/includes/layout.php`: subir `PANEL_ASSET_V` a `'4'`.
- `.env.example`, `DEPLOY.md`.

## Prerrequisitos de entorno (una sola vez)

- MariaDB de XAMPP prendida: `cd /c/xampp/mysql/bin && ./mysqld.exe --defaults-file=my.ini --console &`. Sin eso, PHPUnit falla en el bootstrap con "conexión denegada".
- Línea base: `php vendor/bin/phpunit` debe dar `OK (125 tests, 453 assertions)` antes de tocar nada.
- Correr PHPUnit siempre desde la raíz del repo.

---

### Task 1: Migración 002, rama y helpers de test

**Files:**
- Create: `db/migrations/002_portal.sql`
- Create: `tests/MigracionPortalTest.php`
- Modify: `tests/DbTestCase.php`

**Interfaces:**
- Produces: tablas `usuarios_cliente`, `planes`, `suscripciones_cliente`, `solicitudes_suscripcion`, `servicios`, `servicio_eventos`, `categorias_ticket`, `tickets`, `tareas_cliente`; columnas `fixs.ticket_id`, `login_intentos.ambito` (default `'admin'`) y `login_intentos.clave`. Helpers de test `crearCliente(string $nombre = 'Cliente Test'): int` y `crearUsuarioCliente(int $clienteId, string $email = 'ana@sol.com', ?string $clave = 'secreta1', bool $activo = true): int`.

- [ ] **Step 1: Crear la rama de trabajo**

```bash
git checkout -b portal-clientes
```

- [ ] **Step 2: Agregar los helpers a `tests/DbTestCase.php`**

Dentro de la clase `DbTestCase`, después de `errores422`, agregar:

```php
    protected function crearCliente(string $nombre = 'Cliente Test'): int
    {
        return crud_insert('clientes', ['nombre' => $nombre]);
    }

    protected function crearUsuarioCliente(int $clienteId, string $email = 'ana@sol.com', ?string $clave = 'secreta1', bool $activo = true): int
    {
        return crud_insert('usuarios_cliente', [
            'cliente_id' => $clienteId,
            'email' => $email,
            'password_hash' => $clave === null ? null : password_hash($clave, PASSWORD_BCRYPT, ['cost' => 4]),
            'activo' => $activo ? 1 : 0,
        ]);
    }
```

- [ ] **Step 3: Escribir el test que falla**

Crear `tests/MigracionPortalTest.php`:

```php
<?php
declare(strict_types=1);

final class MigracionPortalTest extends DbTestCase
{
    public function test_existen_las_tablas_del_portal(): void
    {
        $tablas = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ([
            'usuarios_cliente', 'planes', 'suscripciones_cliente', 'solicitudes_suscripcion',
            'servicios', 'servicio_eventos', 'categorias_ticket', 'tickets', 'tareas_cliente',
        ] as $tabla) {
            $this->assertContains($tabla, $tablas, "Falta la tabla $tabla");
        }
    }

    public function test_la_tabla_suscripciones_de_gastos_propios_no_se_toca(): void
    {
        $columnas = db()->query('SHOW COLUMNS FROM suscripciones')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('servicio', $columnas);
        $this->assertContains('fecha_proximo_cobro', $columnas);
        $this->assertNotContains('cliente_id', $columnas);
    }

    public function test_categorias_iniciales_con_su_mapeo(): void
    {
        $filas = q_all('SELECT nombre, tipo, prioridad_default FROM categorias_ticket ORDER BY orden');
        $this->assertSame(
            ['Algo no funciona o está caído', 'Error visual o de datos', 'Cambio de contenido', 'Nueva funcionalidad', 'Mejora de algo existente', 'Consulta'],
            array_column($filas, 'nombre')
        );
        $this->assertSame(['fix', 'fix', 'tarea', 'tarea', 'tarea', 'tarea'], array_column($filas, 'tipo'));
        $this->assertSame(['alta', 'media', 'baja', 'media', 'media', 'baja'], array_column($filas, 'prioridad_default'));
    }

    public function test_los_fixs_viejos_quedan_sin_ticket(): void
    {
        $c = $this->crearCliente();
        $id = crud_insert('fixs', ['cliente_id' => $c, 'titulo' => 'Viejo', 'fecha_reportado' => '2026-01-01']);
        $this->assertNull(q_val('SELECT ticket_id FROM fixs WHERE id = ?', [$id]));
    }

    public function test_login_intentos_es_del_ambito_admin_por_defecto(): void
    {
        db()->exec("INSERT INTO login_intentos (ip) VALUES ('1.2.3.4')");
        $this->assertSame('admin', q_val("SELECT ambito FROM login_intentos WHERE ip = '1.2.3.4'"));
        $this->assertNull(q_val("SELECT clave FROM login_intentos WHERE ip = '1.2.3.4'"));
    }

    public function test_un_cliente_tiene_un_solo_acceso(): void
    {
        $c = $this->crearCliente();
        $this->crearUsuarioCliente($c, 'uno@x.com');
        $this->expectException(PDOException::class);
        $this->crearUsuarioCliente($c, 'dos@x.com');
    }

    public function test_el_email_del_acceso_es_unico_sin_importar_mayusculas(): void
    {
        $this->crearUsuarioCliente($this->crearCliente('A'), 'Ana@Sol.com');
        $this->expectException(PDOException::class);
        $this->crearUsuarioCliente($this->crearCliente('B'), 'ana@sol.com');
    }

    public function test_borrar_el_cliente_borra_su_acceso(): void
    {
        $c = $this->crearCliente();
        $this->crearUsuarioCliente($c);
        crud_delete('clientes', $c);
        $this->assertSame(0, (int)q_val('SELECT COUNT(*) FROM usuarios_cliente WHERE cliente_id = ?', [$c]));
    }

    public function test_un_ticket_solo_puede_tener_un_fix(): void
    {
        $c = $this->crearCliente();
        $cat = (int)q_val('SELECT id FROM categorias_ticket ORDER BY orden LIMIT 1');
        $t = crud_insert('tickets', ['cliente_id' => $c, 'categoria_id' => $cat, 'tipo' => 'fix', 'asunto' => 'A', 'descripcion' => 'B']);
        crud_insert('fixs', ['cliente_id' => $c, 'ticket_id' => $t, 'titulo' => 'F1', 'fecha_reportado' => '2026-01-01']);
        $this->expectException(PDOException::class);
        crud_insert('fixs', ['cliente_id' => $c, 'ticket_id' => $t, 'titulo' => 'F2', 'fecha_reportado' => '2026-01-01']);
    }

    public function test_borrar_el_ticket_deja_el_fix_sin_ticket(): void
    {
        $c = $this->crearCliente();
        $cat = (int)q_val('SELECT id FROM categorias_ticket ORDER BY orden LIMIT 1');
        $t = crud_insert('tickets', ['cliente_id' => $c, 'categoria_id' => $cat, 'tipo' => 'fix', 'asunto' => 'A', 'descripcion' => 'B']);
        $f = crud_insert('fixs', ['cliente_id' => $c, 'ticket_id' => $t, 'titulo' => 'F', 'fecha_reportado' => '2026-01-01']);
        crud_delete('tickets', $t);
        $this->assertNull(q_val('SELECT ticket_id FROM fixs WHERE id = ?', [$f]));
    }
}
```

- [ ] **Step 4: Correr el test y verificar que falla**

Run: `php vendor/bin/phpunit --filter MigracionPortalTest`
Expected: FAIL (la mayoría con "Falta la tabla usuarios_cliente" o `Table ... doesn't exist`).

- [ ] **Step 5: Escribir la migración**

Crear `db/migrations/002_portal.sql`:

```sql
-- VEZZA · Portal de clientes · esquema (spec 2026-09-30-portal-clientes-design)
-- Aditiva: no renombra ni borra nada existente. Se aplica desde /admin/migraciones.

CREATE TABLE usuarios_cliente (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  token_hash CHAR(64) NULL,
  token_tipo ENUM('invitacion','recuperacion') NULL,
  token_expira DATETIME NULL,
  ultimo_login DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_usuarios_cliente_email (email),
  UNIQUE KEY uk_usuarios_cliente_cliente (cliente_id),
  KEY idx_usuarios_cliente_token (token_hash),
  CONSTRAINT fk_usuarios_cliente_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE planes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(255) NOT NULL,
  descripcion TEXT NULL,
  monto DECIMAL(12,2) NOT NULL,
  moneda CHAR(3) NOT NULL,
  frecuencia ENUM('mensual','anual') NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  orden INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_planes_activo (activo, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE suscripciones_cliente (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NOT NULL,
  estado ENUM('activa','cancelada') NOT NULL DEFAULT 'activa',
  fecha_inicio DATE NOT NULL,
  fecha_proximo_cobro DATE NULL,
  fecha_baja DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_susc_cliente (cliente_id, estado),
  CONSTRAINT fk_susc_cliente_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE,
  CONSTRAINT fk_susc_cliente_plan FOREIGN KEY (plan_id) REFERENCES planes (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE solicitudes_suscripcion (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  suscripcion_id INT UNSIGNED NULL,
  tipo ENUM('alta','cambio','baja') NOT NULL,
  plan_id INT UNSIGNED NULL,
  estado ENUM('pendiente','aprobada','rechazada','retirada') NOT NULL DEFAULT 'pendiente',
  nota_cliente TEXT NULL,
  nota_admin TEXT NULL,
  resuelta_en DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_solicitudes_estado (estado, created_at),
  KEY idx_solicitudes_cliente (cliente_id),
  CONSTRAINT fk_solicitudes_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE,
  CONSTRAINT fk_solicitudes_suscripcion FOREIGN KEY (suscripcion_id) REFERENCES suscripciones_cliente (id) ON DELETE SET NULL,
  CONSTRAINT fk_solicitudes_plan FOREIGN KEY (plan_id) REFERENCES planes (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE servicios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  suscripcion_id INT UNSIGNED NULL,
  nombre VARCHAR(255) NOT NULL,
  url VARCHAR(500) NULL,
  chequear TINYINT(1) NOT NULL DEFAULT 0,
  estado_auto ENUM('activo','caido','desconocido') NOT NULL DEFAULT 'desconocido',
  fallos_consecutivos INT UNSIGNED NOT NULL DEFAULT 0,
  estado_manual ENUM('activo','mantenimiento','caido') NULL,
  estado_manual_hasta DATETIME NULL,
  mensaje VARCHAR(500) NULL,
  ultimo_chequeo DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_servicios_cliente (cliente_id),
  CONSTRAINT fk_servicios_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE,
  CONSTRAINT fk_servicios_suscripcion FOREIGN KEY (suscripcion_id) REFERENCES suscripciones_cliente (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE servicio_eventos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  servicio_id INT UNSIGNED NOT NULL,
  estado ENUM('activo','mantenimiento','caido') NOT NULL,
  origen ENUM('auto','manual') NOT NULL,
  mensaje VARCHAR(500) NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_servicio_eventos (servicio_id, creado_en),
  CONSTRAINT fk_servicio_eventos_servicio FOREIGN KEY (servicio_id) REFERENCES servicios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categorias_ticket (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(255) NOT NULL,
  tipo ENUM('fix','tarea') NOT NULL,
  prioridad_default ENUM('baja','media','alta') NOT NULL DEFAULT 'media',
  orden INT NOT NULL DEFAULT 0,
  activa TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO categorias_ticket (nombre, tipo, prioridad_default, orden) VALUES
  ('Algo no funciona o está caído', 'fix', 'alta', 1),
  ('Error visual o de datos', 'fix', 'media', 2),
  ('Cambio de contenido', 'tarea', 'baja', 3),
  ('Nueva funcionalidad', 'tarea', 'media', 4),
  ('Mejora de algo existente', 'tarea', 'media', 5),
  ('Consulta', 'tarea', 'baja', 6);

CREATE TABLE tickets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NULL,
  servicio_id INT UNSIGNED NULL,
  categoria_id INT UNSIGNED NOT NULL,
  tipo ENUM('fix','tarea') NOT NULL,
  asunto VARCHAR(255) NOT NULL,
  descripcion TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tickets_cliente (cliente_id, created_at),
  KEY idx_tickets_tipo (tipo, created_at),
  CONSTRAINT fk_tickets_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE,
  CONSTRAINT fk_tickets_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios_cliente (id) ON DELETE SET NULL,
  CONSTRAINT fk_tickets_servicio FOREIGN KEY (servicio_id) REFERENCES servicios (id) ON DELETE SET NULL,
  CONSTRAINT fk_tickets_categoria FOREIGN KEY (categoria_id) REFERENCES categorias_ticket (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tareas_cliente (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  ticket_id INT UNSIGNED NULL,
  proceso_id INT UNSIGNED NULL,
  titulo VARCHAR(255) NOT NULL,
  descripcion TEXT NULL,
  estado ENUM('pendiente','en_curso','hecha') NOT NULL DEFAULT 'pendiente',
  prioridad ENUM('baja','media','alta') NOT NULL DEFAULT 'media',
  fecha_vencimiento DATE NULL,
  orden INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_tareas_cliente_ticket (ticket_id),
  KEY idx_tareas_cliente_estado (estado, orden),
  CONSTRAINT fk_tareas_cliente_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE,
  CONSTRAINT fk_tareas_cliente_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE SET NULL,
  CONSTRAINT fk_tareas_cliente_proceso FOREIGN KEY (proceso_id) REFERENCES procesos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE fixs
  ADD COLUMN ticket_id INT UNSIGNED NULL AFTER proceso_id,
  ADD UNIQUE KEY uk_fixs_ticket (ticket_id),
  ADD CONSTRAINT fk_fixs_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE SET NULL;

ALTER TABLE login_intentos
  ADD COLUMN ambito VARCHAR(10) NOT NULL DEFAULT 'admin',
  ADD COLUMN clave VARCHAR(255) NULL,
  ADD KEY idx_login_ambito_clave (ambito, clave, creado_en);

INSERT IGNORE INTO migraciones (nombre) VALUES ('002_portal');
```

- [ ] **Step 6: Correr el test y verificar que pasa**

El bootstrap de los tests borra todas las tablas y vuelve a migrar, así que toma la 002 solo.

Run: `php vendor/bin/phpunit --filter MigracionPortalTest`
Expected: PASS (10 tests).

- [ ] **Step 7: Correr toda la suite (regresión)**

Run: `php vendor/bin/phpunit`
Expected: `OK` con 135 tests (125 + 10).

- [ ] **Step 8: Commit**

```bash
git add db/migrations/002_portal.sql tests/MigracionPortalTest.php tests/DbTestCase.php
git commit -m "Agregar el esquema del portal de clientes (migración 002)" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Refactor de sesión por ámbito y `login_intentos` con ámbito

**Files:**
- Modify: `admin/includes/auth.php` (funciones `session_boot`, `login_bloqueado`, `auth_attempt`)
- Modify: `tests/AuthTest.php`, `tests/SesionesTest.php`

**Interfaces:**
- Consumes: columnas `login_intentos.ambito` y `clave` (Task 1).
- Produces: `session_boot_ambito(string $cookie, string $flag): void`. `session_boot()` queda igual de cara afuera y delega con `('vezza_admin', 'admin')`. El conteo de intentos del admin ignora el ámbito `cliente`.

- [ ] **Step 1: Escribir los tests que fallan**

Agregar al final de la clase en `tests/AuthTest.php`:

```php
    public function test_el_bloqueo_del_admin_ignora_los_intentos_del_portal(): void
    {
        for ($i = 0; $i < 5; $i++) {
            db()->exec("INSERT INTO login_intentos (ip, ambito, clave) VALUES ('8.8.8.8', 'cliente', 'x@y.com')");
        }
        $this->assertFalse(login_bloqueado('8.8.8.8'));
        $this->assertSame('ok', auth_attempt('valen', 'clave-secreta', '8.8.8.8'));
    }

    public function test_el_login_exitoso_del_admin_no_borra_los_intentos_del_portal(): void
    {
        db()->exec("INSERT INTO login_intentos (ip, ambito, clave) VALUES ('9.9.9.9', 'cliente', 'x@y.com')");
        auth_attempt('valen', 'clave-secreta', '9.9.9.9');
        $this->assertSame(1, (int)q_val("SELECT COUNT(*) FROM login_intentos WHERE ip = '9.9.9.9' AND ambito = 'cliente'"));
    }
```

Agregar al final de la clase en `tests/SesionesTest.php`:

```php
    public function test_session_boot_ambito_en_cli_solo_inicializa_la_sesion(): void
    {
        $_SESSION = ['x' => 1];
        session_boot_ambito('vezza_cliente', 'cliente');
        $this->assertSame(['x' => 1], $_SESSION);
        session_boot();
        $this->assertSame(['x' => 1], $_SESSION);
        $_SESSION = [];
    }
```

- [ ] **Step 2: Correr los tests y verificar que fallan**

Run: `php vendor/bin/phpunit --filter "AuthTest|SesionesTest"`
Expected: FAIL: `test_el_bloqueo_del_admin_ignora...` (bloqueado = true), `test_el_login_exitoso...` (cuenta 0) y `Call to undefined function session_boot_ambito()`.

- [ ] **Step 3: Refactorizar `auth.php`**

Reemplazar la función `session_boot()` completa por estas dos:

```php
function session_boot(): void
{
    session_boot_ambito('vezza_admin', 'admin');
}

/**
 * Arranca la sesión de un ámbito (admin o cliente). Cada ámbito tiene su cookie y su flag de sesión,
 * y cada punto de entrada arranca uno solo: así una sesión de un ámbito nunca sirve en el otro.
 */
function session_boot_ambito(string $cookie, string $flag): void
{
    if (PHP_SAPI === 'cli') {
        $_SESSION ??= [];
        return;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $dir = sessions_dir();
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    } else {
        error_log("[panel] No se pudo usar la carpeta de sesiones $dir; se usa la del hosting");
    }
    ini_set('session.gc_maxlifetime', (string)SESION_DURACION);
    ini_set('session.use_strict_mode', '1');
    session_name($cookie);
    $params = [
        'lifetime' => SESION_DURACION,
        'path' => '/',
        'secure' => env('APP_ENV') === 'production',
        'httponly' => true,
        'samesite' => 'Strict',
    ];
    session_set_cookie_params($params);
    session_start();
    if (!empty($_SESSION[$flag])) {
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
```

En `login_bloqueado`, cambiar la consulta para que cuente solo el ámbito admin:

```php
    $n = (int)q_val(
        "SELECT COUNT(*) FROM login_intentos WHERE ambito = 'admin' AND ip = ? AND creado_en > (NOW() - INTERVAL " . LOGIN_VENTANA_MIN . ' MINUTE)',
        [$ip]
    );
```

En `auth_attempt`, cambiar el borrado del login exitoso (el `INSERT` del fallo no cambia: el ámbito por defecto ya es `admin`):

```php
        db()->prepare("DELETE FROM login_intentos WHERE ip = ? AND ambito = 'admin'")->execute([$ip]);
```

- [ ] **Step 4: Correr los tests y verificar que pasan**

Run: `php vendor/bin/phpunit --filter "AuthTest|SesionesTest"`
Expected: PASS.

- [ ] **Step 5: Correr toda la suite (regresión del admin)**

Run: `php vendor/bin/phpunit`
Expected: `OK`, 138 tests. Ningún test previo se modificó.

- [ ] **Step 6: Commit**

```bash
git add admin/includes/auth.php tests/AuthTest.php tests/SesionesTest.php
git commit -m "Separar la sesión por ámbito y el conteo de intentos de login del admin" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Envío de mails por webhook de n8n

**Files:**
- Create: `admin/includes/mail.php`
- Create: `tests/MailTest.php`

**Interfaces:**
- Produces: `portal_url(string $ruta): string` (`PORTAL_URL` del `.env`, por defecto `https://vezzadev.com`, sin barra final, más `$ruta`), `mail_enviar(string $plantilla, string $destino, array $datos): bool` (nunca lanza; `false` si falla o no hay `MAIL_WEBHOOK_URL`), y la clase `Mail` con `Mail::$transporte` (callable de prueba `fn(string $url, string $secreto, array $payload): bool`).
- Contrato del webhook (lo consume el workflow de la Task 9): `POST` JSON `{"plantilla": "invitacion"|"recuperacion", "destino": "<email>", "datos": {"nombre"?: string, "link": string}}` con header `X-Webhook-Secret`. Responde 2xx si aceptó.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/MailTest.php`:

```php
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MailTest extends TestCase
{
    protected function tearDown(): void
    {
        Mail::$transporte = null;
        env_set('MAIL_WEBHOOK_URL', null);
        env_set('MAIL_WEBHOOK_SECRET', null);
        env_set('PORTAL_URL', null);
        parent::tearDown();
    }

    public function test_portal_url_usa_el_env_sin_barra_final(): void
    {
        env_set('PORTAL_URL', 'https://demo.test/');
        $this->assertSame('https://demo.test/clientes/activar?token=abc', portal_url('/clientes/activar?token=abc'));
    }

    public function test_portal_url_por_defecto_es_el_dominio_de_vezza(): void
    {
        env_set('PORTAL_URL', null);
        $this->assertSame('https://vezzadev.com/clientes', portal_url('/clientes'));
    }

    public function test_sin_webhook_configurado_devuelve_false(): void
    {
        env_set('MAIL_WEBHOOK_URL', null);
        Mail::$transporte = fn() => throw new LogicException('No debería intentar enviar');
        $this->assertFalse(mail_enviar('invitacion', 'ana@sol.com', ['link' => 'x']));
    }

    public function test_arma_el_payload_y_manda_el_secreto(): void
    {
        env_set('MAIL_WEBHOOK_URL', 'https://n8n.test/webhook/vezza-portal-mail');
        env_set('MAIL_WEBHOOK_SECRET', 'shh');
        $visto = [];
        Mail::$transporte = function (string $url, string $secreto, array $payload) use (&$visto): bool {
            $visto = [$url, $secreto, $payload];
            return true;
        };
        $this->assertTrue(mail_enviar('invitacion', 'ana@sol.com', ['nombre' => 'Sol', 'link' => 'https://x/y']));
        $this->assertSame('https://n8n.test/webhook/vezza-portal-mail', $visto[0]);
        $this->assertSame('shh', $visto[1]);
        $this->assertSame(
            ['plantilla' => 'invitacion', 'destino' => 'ana@sol.com', 'datos' => ['nombre' => 'Sol', 'link' => 'https://x/y']],
            $visto[2]
        );
    }

    public function test_si_el_transporte_falla_o_lanza_devuelve_false_sin_romper(): void
    {
        env_set('MAIL_WEBHOOK_URL', 'https://n8n.test/hook');
        Mail::$transporte = fn() => false;
        $this->assertFalse(mail_enviar('recuperacion', 'ana@sol.com', ['link' => 'x']));
        Mail::$transporte = fn() => throw new RuntimeException('se cayó n8n');
        $this->assertFalse(mail_enviar('recuperacion', 'ana@sol.com', ['link' => 'x']));
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php vendor/bin/phpunit --filter MailTest`
Expected: FAIL con `Class "Mail" not found` o `Call to undefined function portal_url()`.

- [ ] **Step 3: Implementar `admin/includes/mail.php`**

```php
<?php
declare(strict_types=1);

final class Mail
{
    /** @var (callable(string, string, array): bool)|null Transporte de prueba: recibe (url, secreto, payload). */
    public static $transporte = null;
}

function portal_url(string $ruta): string
{
    return rtrim(env('PORTAL_URL', 'https://vezzadev.com'), '/') . $ruta;
}

/**
 * Manda un mail por el webhook de n8n. Nunca lanza: si falla devuelve false y el llamador
 * sigue (el admin siempre ve el link para copiarlo y mandarlo por otro canal).
 */
function mail_enviar(string $plantilla, string $destino, array $datos): bool
{
    $url = env('MAIL_WEBHOOK_URL');
    if ($url === null) {
        error_log('[portal] MAIL_WEBHOOK_URL no está configurada; no se mandó el mail');
        return false;
    }
    $payload = ['plantilla' => $plantilla, 'destino' => $destino, 'datos' => $datos];
    $secreto = (string)env('MAIL_WEBHOOK_SECRET', '');
    try {
        if (Mail::$transporte !== null) {
            return (bool)(Mail::$transporte)($url, $secreto, $payload);
        }
        return mail_post($url, $secreto, $payload);
    } catch (Throwable $e) {
        error_log('[portal] No se pudo mandar el mail: ' . $e->getMessage());
        return false;
    }
}

function mail_post(string $url, string $secreto, array $payload): bool
{
    $contexto = stream_context_create(['http' => [
        'method' => 'POST',
        'timeout' => 6,
        'ignore_errors' => true,
        'header' => "Content-Type: application/json\r\nX-Webhook-Secret: $secreto\r\n",
        'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]]);
    $respuesta = @file_get_contents($url, false, $contexto);
    if ($respuesta === false) {
        return false;
    }
    $cabeceras = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    return (bool)preg_match('#^HTTP/\S+\s+2\d\d#', (string)($cabeceras[0] ?? ''));
}
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php vendor/bin/phpunit --filter MailTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add admin/includes/mail.php tests/MailTest.php
git commit -m "Agregar el envío de mails del portal por webhook de n8n" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Sesión, login y guards del cliente

**Files:**
- Create: `admin/includes/auth_cliente.php`
- Create: `tests/AuthClienteTest.php`

**Interfaces:**
- Consumes: `session_boot_ambito` (Task 2); `login_intentos.ambito/clave` (Task 1); `LOGIN_VENTANA_MIN`, `client_ip()`, `auth_logout()`, `HttpError`, `q_one`, `q_val`, `crud_*` existentes.
- Produces:
  - Constantes `CLIENTE_CLAVE_MIN = 6`, `CLIENTE_LOGIN_MAX_INTENTOS = 5`.
  - `session_boot_cliente(): void`
  - `cliente_email_normalizar(string $email): string` (trim + minúsculas)
  - `cliente_validar_clave(string $clave): void` (lanza `HttpError` 422 con `campos['clave']`)
  - `cliente_login_bloqueado(string $ip, string $email): bool`
  - `cliente_auth_attempt(string $email, string $clave, string $ip): string` (`'ok'`, `'invalido'` o `'bloqueado'`)
  - `cliente_logged(): bool`, `cliente_actual(): ?array` (`['usuario_id', 'cliente_id', 'email']`, releído de la base), `require_cliente(): int`, `require_cliente_api(): int` (ambos devuelven el `cliente_id`), `cliente_logout(): void`.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/AuthClienteTest.php`:

```php
<?php
declare(strict_types=1);

final class AuthClienteTest extends DbTestCase
{
    private int $clienteId;
    private int $usuarioId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clienteId = $this->crearCliente('Panadería Sol');
        $this->usuarioId = $this->crearUsuarioCliente($this->clienteId, 'ana@sol.com', 'secreta1');
    }

    public function test_login_correcto_abre_la_sesion_de_cliente(): void
    {
        $this->assertSame('ok', cliente_auth_attempt('ana@sol.com', 'secreta1', '1.1.1.1'));
        $this->assertTrue(cliente_logged());
        $this->assertSame($this->clienteId, require_cliente_api());
        $this->assertNotNull(q_val('SELECT ultimo_login FROM usuarios_cliente WHERE id = ?', [$this->usuarioId]));
    }

    public function test_el_email_se_normaliza_al_ingresar(): void
    {
        $this->assertSame('ok', cliente_auth_attempt('  Ana@Sol.COM ', 'secreta1', '1.1.1.1'));
    }

    public function test_clave_incorrecta_email_inexistente_sin_activar_o_desactivado_son_invalidos(): void
    {
        $this->crearUsuarioCliente($this->crearCliente('B'), 'sin@x.com', null);
        $this->crearUsuarioCliente($this->crearCliente('C'), 'off@x.com', 'secreta1', false);
        $this->assertSame('invalido', cliente_auth_attempt('ana@sol.com', 'otra-clave', '1.1.1.1'));
        $this->assertSame('invalido', cliente_auth_attempt('nadie@x.com', 'secreta1', '1.1.1.1'));
        $this->assertSame('invalido', cliente_auth_attempt('sin@x.com', '', '1.1.1.1'));
        $this->assertSame('invalido', cliente_auth_attempt('off@x.com', 'secreta1', '1.1.1.1'));
        $this->assertFalse(cliente_logged());
    }

    public function test_la_clave_se_respeta_tal_cual_sin_recortar_espacios(): void
    {
        $this->crearUsuarioCliente($this->crearCliente('D'), 'esp@x.com', ' abc123 ');
        $this->assertSame('invalido', cliente_auth_attempt('esp@x.com', 'abc123', '1.1.1.1'));
        $this->assertSame('ok', cliente_auth_attempt('esp@x.com', ' abc123 ', '1.1.1.1'));
    }

    public function test_cinco_fallos_desde_una_ip_bloquean_aunque_la_clave_sea_correcta(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('invalido', cliente_auth_attempt("otro$i@x.com", 'mal', '2.2.2.2'));
        }
        $this->assertSame('bloqueado', cliente_auth_attempt('ana@sol.com', 'secreta1', '2.2.2.2'));
        $this->assertFalse(cliente_logged());
        $this->assertSame('ok', cliente_auth_attempt('ana@sol.com', 'secreta1', '3.3.3.3'));
    }

    public function test_cinco_fallos_contra_una_cuenta_la_bloquean_desde_cualquier_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            cliente_auth_attempt('ana@sol.com', 'mal', "10.0.0.$i");
        }
        $this->assertSame('bloqueado', cliente_auth_attempt('ana@sol.com', 'secreta1', '10.0.0.99'));
        $this->crearUsuarioCliente($this->crearCliente('E'), 'otra@x.com', 'secreta1');
        $this->assertSame('ok', cliente_auth_attempt('otra@x.com', 'secreta1', '10.0.0.99'));
    }

    public function test_los_intentos_de_hace_mas_de_15_minutos_no_cuentan(): void
    {
        for ($i = 0; $i < 5; $i++) {
            db()->exec("INSERT INTO login_intentos (ip, ambito, clave, creado_en) VALUES ('4.4.4.4', 'cliente', 'ana@sol.com', NOW() - INTERVAL 16 MINUTE)");
        }
        $this->assertSame('ok', cliente_auth_attempt('ana@sol.com', 'secreta1', '4.4.4.4'));
    }

    public function test_el_login_exitoso_borra_los_fallos_de_esa_cuenta(): void
    {
        cliente_auth_attempt('ana@sol.com', 'mal', '5.5.5.5');
        cliente_auth_attempt('ana@sol.com', 'mal', '5.5.5.5');
        cliente_auth_attempt('ana@sol.com', 'secreta1', '5.5.5.5');
        $this->assertSame(0, (int)q_val("SELECT COUNT(*) FROM login_intentos WHERE ambito = 'cliente' AND clave = 'ana@sol.com'"));
    }

    public function test_los_intentos_de_cliente_y_de_admin_no_se_mezclan(): void
    {
        for ($i = 0; $i < 5; $i++) {
            db()->exec("INSERT INTO login_intentos (ip) VALUES ('6.6.6.6')");
        }
        $this->assertSame('ok', cliente_auth_attempt('ana@sol.com', 'secreta1', '6.6.6.6'));
        $this->assertSame(5, (int)q_val("SELECT COUNT(*) FROM login_intentos WHERE ip = '6.6.6.6' AND ambito = 'admin'"));
    }

    public function test_las_sesiones_de_admin_y_de_cliente_no_se_cruzan(): void
    {
        $_SESSION['cliente'] = ['usuario_id' => $this->usuarioId];
        $this->assertFalse(auth_logged());
        $this->assertHttp(401, fn() => require_admin_api());

        $_SESSION = ['admin' => true];
        $this->assertFalse(cliente_logged());
        $this->assertNull(cliente_actual());
        $this->assertHttp(401, fn() => require_cliente_api());
    }

    public function test_sin_sesion_el_guard_de_api_da_401(): void
    {
        $this->assertHttp(401, fn() => require_cliente_api());
    }

    public function test_desactivar_el_acceso_corta_la_sesion_abierta(): void
    {
        cliente_auth_attempt('ana@sol.com', 'secreta1', '7.7.7.7');
        $this->assertSame($this->clienteId, require_cliente_api());
        db()->prepare('UPDATE usuarios_cliente SET activo = 0 WHERE id = ?')->execute([$this->usuarioId]);
        $this->assertHttp(401, fn() => require_cliente_api());
        $this->assertFalse(cliente_logged());
    }

    public function test_logout_limpia_la_sesion(): void
    {
        cliente_auth_attempt('ana@sol.com', 'secreta1', '8.8.8.8');
        cliente_logout();
        $this->assertFalse(cliente_logged());
    }

    public function test_la_clave_tiene_que_tener_al_menos_6_caracteres(): void
    {
        $campos = $this->errores422(fn() => cliente_validar_clave('12345'));
        $this->assertArrayHasKey('clave', $campos);
        cliente_validar_clave('123456');
        cliente_validar_clave(str_repeat('a', 72));
        $this->addToAssertionCount(2);
    }

    public function test_una_clave_de_mas_de_72_bytes_se_rechaza_sin_truncar(): void
    {
        $campos = $this->errores422(fn() => cliente_validar_clave(str_repeat('a', 73)));
        $this->assertStringContainsString('72', $campos['clave']);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php vendor/bin/phpunit --filter AuthClienteTest`
Expected: FAIL con `Call to undefined function cliente_auth_attempt()`.

- [ ] **Step 3: Implementar `admin/includes/auth_cliente.php`**

```php
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
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php vendor/bin/phpunit --filter AuthClienteTest`
Expected: PASS (15 tests).

- [ ] **Step 5: Commit**

```bash
git add admin/includes/auth_cliente.php tests/AuthClienteTest.php
git commit -m "Agregar el login y los guards de sesión del portal de clientes" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Tokens de invitación y recuperación

**Files:**
- Modify: `admin/includes/auth_cliente.php` (agregar al final)
- Create: `tests/TokensClienteTest.php`

**Interfaces:**
- Consumes: `cliente_validar_clave`, `cliente_email_normalizar` (Task 4); `mail_enviar`, `portal_url` (Task 3).
- Produces:
  - `CLIENTE_TOKEN_HORAS = ['invitacion' => 72, 'recuperacion' => 2]`, `RECUPERAR_MAX_POR_IP = 5`.
  - `cliente_token_emitir(int $usuarioId, string $tipo): string`: devuelve el token **en claro** (64 hex); guarda solo el sha256; pisa cualquier token anterior del usuario.
  - `cliente_token_valido(string $token, string $tipo): bool`
  - `cliente_token_consumir(string $token, string $tipo, string $clave): int`: fija la contraseña y deja el token inservible; devuelve el `usuario_id`; lanza `HttpError` 410 (link vencido, usado o inválido) o 422 (clave corta o larga).
  - `cliente_recuperar_solicitar(string $email, string $ip): void`: nunca revela si la cuenta existe.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/TokensClienteTest.php`:

```php
<?php
declare(strict_types=1);

final class TokensClienteTest extends DbTestCase
{
    private int $usuarioId;
    /** @var array<int, array> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuarioId = $this->crearUsuarioCliente($this->crearCliente(), 'ana@sol.com', null);
        $this->mails = [];
        env_set('MAIL_WEBHOOK_URL', 'https://n8n.test/hook');
        env_set('PORTAL_URL', 'https://portal.test');
        Mail::$transporte = function (string $url, string $secreto, array $payload): bool {
            $this->mails[] = $payload;
            return true;
        };
    }

    protected function tearDown(): void
    {
        Mail::$transporte = null;
        env_set('MAIL_WEBHOOK_URL', null);
        env_set('PORTAL_URL', null);
        parent::tearDown();
    }

    private function minutosHastaVencer(): int
    {
        return (int)q_val('SELECT TIMESTAMPDIFF(MINUTE, NOW(), token_expira) FROM usuarios_cliente WHERE id = ?', [$this->usuarioId]);
    }

    public function test_emitir_devuelve_64_hex_y_guarda_solo_el_hash(): void
    {
        $t = cliente_token_emitir($this->usuarioId, 'invitacion');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $t);
        $fila = q_one('SELECT * FROM usuarios_cliente WHERE id = ?', [$this->usuarioId]);
        $this->assertSame(hash('sha256', $t), $fila['token_hash']);
        $this->assertNotSame($t, $fila['token_hash']);
        $this->assertSame('invitacion', $fila['token_tipo']);
    }

    public function test_la_invitacion_vence_a_las_72_horas_y_la_recuperacion_a_las_2(): void
    {
        cliente_token_emitir($this->usuarioId, 'invitacion');
        $this->assertGreaterThanOrEqual(72 * 60 - 2, $this->minutosHastaVencer());
        $this->assertLessThanOrEqual(72 * 60, $this->minutosHastaVencer());
        cliente_token_emitir($this->usuarioId, 'recuperacion');
        $this->assertGreaterThanOrEqual(2 * 60 - 2, $this->minutosHastaVencer());
        $this->assertLessThanOrEqual(2 * 60, $this->minutosHastaVencer());
    }

    public function test_tipo_de_token_desconocido_es_un_error_de_programacion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        cliente_token_emitir($this->usuarioId, 'otro');
    }

    public function test_consumir_fija_la_clave_y_el_token_no_sirve_de_nuevo(): void
    {
        $t = cliente_token_emitir($this->usuarioId, 'invitacion');
        $this->assertTrue(cliente_token_valido($t, 'invitacion'));
        $this->assertSame($this->usuarioId, cliente_token_consumir($t, 'invitacion', 'nueva123'));
        $hash = (string)q_val('SELECT password_hash FROM usuarios_cliente WHERE id = ?', [$this->usuarioId]);
        $this->assertTrue(password_verify('nueva123', $hash));
        $this->assertSame('ok', cliente_auth_attempt('ana@sol.com', 'nueva123', '1.1.1.1'));
        $this->assertFalse(cliente_token_valido($t, 'invitacion'));
        $this->assertHttp(410, fn() => cliente_token_consumir($t, 'invitacion', 'otra-clave'));
    }

    public function test_un_token_vencido_da_410(): void
    {
        $t = cliente_token_emitir($this->usuarioId, 'invitacion');
        db()->prepare('UPDATE usuarios_cliente SET token_expira = NOW() - INTERVAL 1 MINUTE WHERE id = ?')->execute([$this->usuarioId]);
        $this->assertFalse(cliente_token_valido($t, 'invitacion'));
        $this->assertHttp(410, fn() => cliente_token_consumir($t, 'invitacion', 'nueva123'));
    }

    public function test_un_token_de_otro_tipo_no_sirve(): void
    {
        $t = cliente_token_emitir($this->usuarioId, 'invitacion');
        $this->assertFalse(cliente_token_valido($t, 'recuperacion'));
        $this->assertHttp(410, fn() => cliente_token_consumir($t, 'recuperacion', 'nueva123'));
    }

    public function test_una_invitacion_nueva_invalida_la_anterior(): void
    {
        $viejo = cliente_token_emitir($this->usuarioId, 'invitacion');
        $nuevo = cliente_token_emitir($this->usuarioId, 'invitacion');
        $this->assertFalse(cliente_token_valido($viejo, 'invitacion'));
        $this->assertTrue(cliente_token_valido($nuevo, 'invitacion'));
    }

    public function test_una_clave_corta_da_422_y_no_gasta_el_token(): void
    {
        $t = cliente_token_emitir($this->usuarioId, 'invitacion');
        $campos = $this->errores422(fn() => cliente_token_consumir($t, 'invitacion', '12345'));
        $this->assertArrayHasKey('clave', $campos);
        $this->assertTrue(cliente_token_valido($t, 'invitacion'));
        cliente_token_consumir($t, 'invitacion', '123456');
        $this->assertFalse(cliente_token_valido($t, 'invitacion'));
    }

    public function test_los_tokens_mal_formados_son_invalidos_y_dan_410_no_500(): void
    {
        cliente_token_emitir($this->usuarioId, 'invitacion');
        foreach (['', 'abc', '  ', str_repeat('z', 64), str_repeat('A', 64), '../../etc/passwd', str_repeat('a', 65)] as $malo) {
            $this->assertFalse(cliente_token_valido($malo, 'invitacion'), 'Aceptó: ' . var_export($malo, true));
            $this->assertHttp(410, fn() => cliente_token_consumir($malo, 'invitacion', 'nueva123'));
        }
    }

    public function test_un_usuario_desactivado_no_puede_usar_su_token(): void
    {
        $t = cliente_token_emitir($this->usuarioId, 'invitacion');
        db()->prepare('UPDATE usuarios_cliente SET activo = 0 WHERE id = ?')->execute([$this->usuarioId]);
        $this->assertFalse(cliente_token_valido($t, 'invitacion'));
    }

    public function test_cambiar_la_clave_limpia_los_fallos_de_login_de_la_cuenta(): void
    {
        db()->exec("INSERT INTO login_intentos (ip, ambito, clave) VALUES ('1.1.1.1', 'cliente', 'ana@sol.com')");
        $t = cliente_token_emitir($this->usuarioId, 'invitacion');
        cliente_token_consumir($t, 'invitacion', 'nueva123');
        $this->assertSame(0, (int)q_val("SELECT COUNT(*) FROM login_intentos WHERE ambito = 'cliente' AND clave = 'ana@sol.com'"));
    }

    public function test_recuperar_manda_el_mail_solo_a_cuentas_activadas_y_activas(): void
    {
        $this->crearUsuarioCliente($this->crearCliente('B'), 'act@x.com', 'secreta1');
        cliente_recuperar_solicitar('  ACT@x.com ', '1.1.1.1');
        $this->assertCount(1, $this->mails);
        $this->assertSame('recuperacion', $this->mails[0]['plantilla']);
        $this->assertSame('act@x.com', $this->mails[0]['destino']);
        $link = $this->mails[0]['datos']['link'];
        $this->assertStringStartsWith('https://portal.test/clientes/recuperar?token=', $link);
        parse_str((string)parse_url($link, PHP_URL_QUERY), $q);
        $this->assertTrue(cliente_token_valido($q['token'], 'recuperacion'));
    }

    public function test_recuperar_no_hace_nada_ni_revela_nada_si_la_cuenta_no_califica(): void
    {
        $this->crearUsuarioCliente($this->crearCliente('B'), 'off@x.com', 'secreta1', false);
        cliente_recuperar_solicitar('nadie@x.com', '1.1.1.1');
        cliente_recuperar_solicitar('ana@sol.com', '1.1.1.1');
        cliente_recuperar_solicitar('off@x.com', '1.1.1.1');
        $this->assertSame([], $this->mails);
    }

    public function test_recuperar_se_limita_a_5_pedidos_por_ip_sin_avisar(): void
    {
        $this->crearUsuarioCliente($this->crearCliente('B'), 'act@x.com', 'secreta1');
        for ($i = 0; $i < 7; $i++) {
            cliente_recuperar_solicitar('act@x.com', '9.9.9.9');
        }
        $this->assertCount(5, $this->mails);
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php vendor/bin/phpunit --filter TokensClienteTest`
Expected: FAIL con `Call to undefined function cliente_token_emitir()`.

- [ ] **Step 3: Agregar las constantes al inicio de `auth_cliente.php`**

Debajo de `const CLIENTE_LOGIN_MAX_INTENTOS = 5; ...`:

```php
const CLIENTE_TOKEN_HORAS = ['invitacion' => 72, 'recuperacion' => 2];
const RECUPERAR_MAX_POR_IP = 5; // pedidos de recuperación por IP, dentro de LOGIN_VENTANA_MIN minutos
```

- [ ] **Step 4: Agregar las funciones al final de `auth_cliente.php`**

```php
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
```

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `php vendor/bin/phpunit --filter TokensClienteTest`
Expected: PASS (14 tests).

- [ ] **Step 6: Correr toda la suite**

Run: `php vendor/bin/phpunit`
Expected: `OK`, sin fallos ni warnings.

- [ ] **Step 7: Commit**

```bash
git add admin/includes/auth_cliente.php tests/TokensClienteTest.php
git commit -m "Agregar tokens de invitación y recuperación de contraseña del portal" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Acceso al portal desde el admin (repo y API)

**Files:**
- Create: `admin/includes/repos/acceso_cliente.php`
- Create: `api/acceso-cliente.php`
- Create: `tests/AccesoClienteTest.php`

**Interfaces:**
- Consumes: `cliente_token_emitir`, `cliente_token_valido`, `cliente_email_normalizar` (Tasks 4 y 5); `mail_enviar`, `portal_url` (Task 3); `crud_*`, `tx`, `validate`, `HttpError`.
- Produces:
  - `acceso_cliente_get(int $clienteId): array` devuelve `['estado' => 'sin_acceso'|'invitado'|'activo'|'desactivado', 'email', 'ultimo_login', 'invitacion_vigente' => bool, 'invitacion_vence']`. No expone nunca `password_hash` ni `token_hash`.
  - `acceso_cliente_invitar(int $clienteId, array $input): array` es lo anterior más `'link'` y `'mail_enviado' => bool`.
  - `acceso_cliente_set_activo(int $clienteId, bool $activo): array`
  - Endpoint admin `/api/acceso-cliente?cliente_id=N` (`GET`), `&accion=invitar` (`POST`, body `{email?}`) y `&accion=activo` (`POST`, body `{activo: bool}`).

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/AccesoClienteTest.php`:

```php
<?php
declare(strict_types=1);

final class AccesoClienteTest extends DbTestCase
{
    private int $clienteId;
    /** @var array<int, array> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clienteId = crud_insert('clientes', ['nombre' => 'Panadería Sol', 'email' => 'cli@sol.com']);
        $this->mails = [];
        env_set('MAIL_WEBHOOK_URL', 'https://n8n.test/hook');
        env_set('PORTAL_URL', 'https://portal.test');
        Mail::$transporte = function (string $url, string $secreto, array $payload): bool {
            $this->mails[] = $payload;
            return true;
        };
    }

    protected function tearDown(): void
    {
        Mail::$transporte = null;
        env_set('MAIL_WEBHOOK_URL', null);
        env_set('PORTAL_URL', null);
        parent::tearDown();
    }

    private function tokenDe(array $resultado): string
    {
        parse_str((string)parse_url($resultado['link'], PHP_URL_QUERY), $q);
        return $q['token'];
    }

    public function test_un_cliente_sin_acceso(): void
    {
        $a = acceso_cliente_get($this->clienteId);
        $this->assertSame('sin_acceso', $a['estado']);
        $this->assertNull($a['email']);
        $this->assertFalse($a['invitacion_vigente']);
    }

    public function test_cliente_inexistente_da_404(): void
    {
        $this->assertHttp(404, fn() => acceso_cliente_get(999999));
        $this->assertHttp(404, fn() => acceso_cliente_invitar(999999, []));
    }

    public function test_invitar_usa_el_email_del_cliente_y_devuelve_un_link_que_sirve(): void
    {
        $r = acceso_cliente_invitar($this->clienteId, []);
        $this->assertSame('invitado', $r['estado']);
        $this->assertSame('cli@sol.com', $r['email']);
        $this->assertTrue($r['invitacion_vigente']);
        $this->assertTrue($r['mail_enviado']);
        $this->assertStringStartsWith('https://portal.test/clientes/activar?token=', $r['link']);
        $this->assertTrue(cliente_token_valido($this->tokenDe($r), 'invitacion'));
        $this->assertSame('invitacion', $this->mails[0]['plantilla']);
        $this->assertSame('cli@sol.com', $this->mails[0]['destino']);
        $this->assertSame('Panadería Sol', $this->mails[0]['datos']['nombre']);
        $this->assertSame($r['link'], $this->mails[0]['datos']['link']);
    }

    public function test_invitar_con_un_email_explicito_lo_normaliza_y_pisa_el_del_cliente(): void
    {
        $r = acceso_cliente_invitar($this->clienteId, ['email' => '  Otro@Sol.COM ']);
        $this->assertSame('otro@sol.com', $r['email']);
    }

    public function test_invitar_sin_ningun_email_da_422(): void
    {
        $sinMail = crud_insert('clientes', ['nombre' => 'Sin mail']);
        $campos = $this->errores422(fn() => acceso_cliente_invitar($sinMail, []));
        $this->assertArrayHasKey('email', $campos);
        $campos = $this->errores422(fn() => acceso_cliente_invitar($sinMail, ['email' => 'no-es-mail']));
        $this->assertArrayHasKey('email', $campos);
    }

    public function test_invitar_con_el_email_de_otro_cliente_da_409(): void
    {
        $otro = crud_insert('clientes', ['nombre' => 'Otro']);
        acceso_cliente_invitar($otro, ['email' => 'compartido@x.com']);
        $this->assertHttp(409, fn() => acceso_cliente_invitar($this->clienteId, ['email' => 'compartido@x.com']));
        $this->assertSame('sin_acceso', acceso_cliente_get($this->clienteId)['estado']);
    }

    public function test_reinvitar_invalida_el_link_anterior(): void
    {
        $uno = acceso_cliente_invitar($this->clienteId, []);
        $dos = acceso_cliente_invitar($this->clienteId, []);
        $this->assertFalse(cliente_token_valido($this->tokenDe($uno), 'invitacion'));
        $this->assertTrue(cliente_token_valido($this->tokenDe($dos), 'invitacion'));
        $this->assertSame(1, (int)q_val('SELECT COUNT(*) FROM usuarios_cliente WHERE cliente_id = ?', [$this->clienteId]));
    }

    public function test_reinvitar_a_un_cliente_activo_no_toca_su_contrasena(): void
    {
        $this->crearUsuarioCliente($this->clienteId, 'cli@sol.com', 'secreta1');
        $r = acceso_cliente_invitar($this->clienteId, []);
        $this->assertSame('activo', $r['estado']);
        $this->assertTrue($r['invitacion_vigente']);
        $this->assertSame('ok', cliente_auth_attempt('cli@sol.com', 'secreta1', '1.1.1.1'));
    }

    public function test_reinvitar_a_un_desactivado_lo_reactiva(): void
    {
        acceso_cliente_invitar($this->clienteId, []);
        acceso_cliente_set_activo($this->clienteId, false);
        $this->assertSame('desactivado', acceso_cliente_get($this->clienteId)['estado']);
        $r = acceso_cliente_invitar($this->clienteId, []);
        $this->assertSame('invitado', $r['estado']);
        $this->assertTrue(cliente_token_valido($this->tokenDe($r), 'invitacion'));
    }

    public function test_si_el_mail_falla_la_invitacion_igual_devuelve_el_link(): void
    {
        Mail::$transporte = fn() => throw new RuntimeException('n8n caído');
        $r = acceso_cliente_invitar($this->clienteId, []);
        $this->assertFalse($r['mail_enviado']);
        $this->assertTrue(cliente_token_valido($this->tokenDe($r), 'invitacion'));
    }

    public function test_desactivar_y_reactivar(): void
    {
        $this->crearUsuarioCliente($this->clienteId, 'cli@sol.com', 'secreta1');
        $this->assertSame('activo', acceso_cliente_get($this->clienteId)['estado']);
        $this->assertSame('desactivado', acceso_cliente_set_activo($this->clienteId, false)['estado']);
        $this->assertSame('invalido', cliente_auth_attempt('cli@sol.com', 'secreta1', '1.1.1.1'));
        $this->assertSame('activo', acceso_cliente_set_activo($this->clienteId, true)['estado']);
        $this->assertSame('ok', cliente_auth_attempt('cli@sol.com', 'secreta1', '2.2.2.2'));
    }

    public function test_desactivar_a_un_cliente_sin_acceso_da_404(): void
    {
        $this->assertHttp(404, fn() => acceso_cliente_set_activo($this->clienteId, false));
    }

    public function test_la_respuesta_no_expone_hashes(): void
    {
        $r = acceso_cliente_invitar($this->clienteId, []);
        foreach (['password_hash', 'token_hash', 'token_tipo', 'id', 'cliente_id'] as $clave) {
            $this->assertArrayNotHasKey($clave, $r);
        }
    }
}
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php vendor/bin/phpunit --filter AccesoClienteTest`
Expected: FAIL con `Call to undefined function acceso_cliente_get()`.

- [ ] **Step 3: Implementar `admin/includes/repos/acceso_cliente.php`**

```php
<?php
declare(strict_types=1);

function acceso_cliente_publico(?array $u): array
{
    if ($u === null) {
        return ['estado' => 'sin_acceso', 'email' => null, 'ultimo_login' => null, 'invitacion_vigente' => false, 'invitacion_vence' => null];
    }
    $estado = !(int)$u['activo'] ? 'desactivado' : ($u['password_hash'] !== null ? 'activo' : 'invitado');
    $hayInvitacion = $u['token_tipo'] === 'invitacion' && $u['token_hash'] !== null;
    return [
        'estado' => $estado,
        'email' => $u['email'],
        'ultimo_login' => $u['ultimo_login'],
        'invitacion_vigente' => $hayInvitacion && (bool)$u['token_vigente'],
        'invitacion_vence' => $hayInvitacion ? $u['token_expira'] : null,
    ];
}

function acceso_cliente_get(int $clienteId): array
{
    crud_find('clientes', $clienteId);
    $u = q_one(
        'SELECT *, (token_expira IS NOT NULL AND token_expira > NOW()) AS token_vigente FROM usuarios_cliente WHERE cliente_id = ?',
        [$clienteId]
    );
    return acceso_cliente_publico($u);
}

/**
 * Crea (o renueva) la invitación del cliente. Invitar de nuevo invalida el link anterior, reactiva un
 * acceso desactivado y no toca la contraseña de quien ya la tenía. El link se devuelve siempre:
 * si el mail falla, el admin lo copia y lo manda por otro canal.
 */
function acceso_cliente_invitar(int $clienteId, array $input): array
{
    $cliente = crud_find('clientes', $clienteId);
    $datos = validate($input, ['email' => ['type' => 'email']], true);
    $email = cliente_email_normalizar((string)($datos['email'] ?? $cliente['email'] ?? ''));
    if ($email === '') {
        throw new HttpError(422, 'Revisá los datos marcados', ['email' => 'Cargá un email para invitar al cliente']);
    }
    $ajeno = q_one('SELECT cliente_id FROM usuarios_cliente WHERE email = ?', [$email]);
    if ($ajeno !== null && (int)$ajeno['cliente_id'] !== $clienteId) {
        throw new HttpError(409, 'Ese email ya tiene acceso al portal de otro cliente.');
    }
    $token = tx(function () use ($clienteId, $email): string {
        $actual = q_one('SELECT id FROM usuarios_cliente WHERE cliente_id = ?', [$clienteId]);
        if ($actual === null) {
            $usuarioId = crud_insert('usuarios_cliente', ['cliente_id' => $clienteId, 'email' => $email]);
        } else {
            $usuarioId = (int)$actual['id'];
            crud_update('usuarios_cliente', $usuarioId, ['email' => $email, 'activo' => 1]);
        }
        return cliente_token_emitir($usuarioId, 'invitacion');
    });
    $link = portal_url('/clientes/activar?token=' . $token);
    $enviado = mail_enviar('invitacion', $email, ['nombre' => $cliente['nombre'], 'link' => $link]);
    return acceso_cliente_get($clienteId) + ['link' => $link, 'mail_enviado' => $enviado];
}

function acceso_cliente_set_activo(int $clienteId, bool $activo): array
{
    crud_find('clientes', $clienteId);
    $u = q_one('SELECT id FROM usuarios_cliente WHERE cliente_id = ?', [$clienteId])
        ?? throw new HttpError(404, 'Este cliente todavía no tiene acceso al portal.');
    crud_update('usuarios_cliente', (int)$u['id'], ['activo' => $activo ? 1 : 0]);
    return acceso_cliente_get($clienteId);
}
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php vendor/bin/phpunit --filter AccesoClienteTest`
Expected: PASS (13 tests).

- [ ] **Step 5: Crear el endpoint `api/acceso-cliente.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_run(function (): void {
    $crudo = $_GET['cliente_id'] ?? null;
    if (!is_string($crudo) || !ctype_digit($crudo) || (int)$crudo < 1) {
        throw new HttpError(404, 'No encontrado');
    }
    $clienteId = (int)$crudo;
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($metodo === 'GET') {
        json_out(200, ['data' => acceso_cliente_get($clienteId)]);
        return;
    }
    if ($metodo !== 'POST') {
        throw new HttpError(405, 'Método no permitido');
    }
    $accion = $_GET['accion'] ?? '';
    if ($accion === 'invitar') {
        json_out(201, ['data' => acceso_cliente_invitar($clienteId, request_json())]);
        return;
    }
    if ($accion === 'activo') {
        $in = validate(request_json(), ['activo' => ['type' => 'bool', 'required' => true]]);
        json_out(200, ['data' => acceso_cliente_set_activo($clienteId, (bool)$in['activo'])]);
        return;
    }
    throw new HttpError(404, 'No encontrado');
});
```

- [ ] **Step 6: Verificar que el archivo no tiene errores de sintaxis y que la suite sigue verde**

Run: `php -l api/acceso-cliente.php && php vendor/bin/phpunit`
Expected: `No syntax errors detected` y `OK`.

- [ ] **Step 7: Commit**

```bash
git add admin/includes/repos/acceso_cliente.php api/acceso-cliente.php tests/AccesoClienteTest.php
git commit -m "Agregar la gestión del acceso al portal desde el admin" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Páginas del portal, estilos, rutas y script de verificación

**Files:**
- Create: `admin/includes/portal_layout.php`
- Create: `clientes/index.php`, `clientes/login.php`, `clientes/activar.php`, `clientes/recuperar.php`, `clientes/logout.php`, `clientes/.htaccess`
- Create: `clientes/assets/portal.css`, `clientes/assets/portal.js`
- Create: `scripts/verificar-portal.sh`
- Modify: `.htaccess` (agregar al final)

**Interfaces:**
- Consumes: `session_boot_cliente`, `cliente_actual`, `cliente_auth_attempt`, `cliente_token_valido`, `cliente_token_consumir`, `cliente_recuperar_solicitar`, `require_cliente`, `cliente_logout` (Tasks 4 y 5); `csrf_*`, `client_ip()`, `e()`, `send_panel_headers()`, `PANEL_ASSET_V`.
- Produces: `PORTAL_ASSET_V`, `portal_inicio(string $titulo, string $claseBody = ''): void` (imprime hasta `<body>`), `portal_fin(): void`, `portal_pagina_clave(string $tipo, string $titulo, string $intro, string $textoBoton): void`. Rutas `/clientes`, `/clientes/login`, `/clientes/activar`, `/clientes/recuperar`, `/clientes/logout`.

Estas pantallas no tienen tests PHPUnit (el repo tampoco los tiene para páginas: se verifican con `verificar-*.sh` y a mano). La lógica ya está cubierta por los tests de las Tasks 4 y 5.

- [ ] **Step 1: Crear `admin/includes/portal_layout.php`**

```php
<?php
declare(strict_types=1);

const PORTAL_ASSET_V = '1';

/** Imprime el <head> y abre el <body>. La sesión del portal ya tiene que estar arrancada. */
function portal_inicio(string $titulo, string $claseBody = ''): void
{
    send_panel_headers();
    header('Content-Type: text/html; charset=utf-8');
    $v = PORTAL_ASSET_V;
    $pv = PANEL_ASSET_V;
    ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#F5F3EE">
<meta name="color-scheme" content="light">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($titulo) ?> · VEZZA</title>
<link rel="icon" href="/assets/icons/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Sora:wght@600;700&display=swap">
<link rel="stylesheet" href="/admin/assets/admin.css?v=<?= $pv ?>">
<link rel="stylesheet" href="/clientes/assets/portal.css?v=<?= $v ?>">
<script src="/clientes/assets/portal.js?v=<?= $v ?>" defer></script>
</head>
<body class="<?= e($claseBody) ?>">
<a class="skip" href="#main">Saltar al contenido</a>
    <?php
}

function portal_fin(): void
{
    echo "</body>\n</html>\n";
}

/**
 * Pantalla para elegir contraseña con un token (activar la cuenta o recuperarla).
 * Un token ausente, malformado o que no es texto se trata como inválido: muestra "link vencido", nunca un error.
 */
function portal_pagina_clave(string $tipo, string $titulo, string $intro, string $textoBoton): void
{
    session_boot_cliente();
    $crudo = $_POST['token'] ?? $_GET['token'] ?? '';
    $token = is_string($crudo) ? $crudo : '';
    $error = null;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            csrf_check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null);
            $clave = is_string($_POST['clave'] ?? null) ? $_POST['clave'] : '';
            $repetir = is_string($_POST['repetir'] ?? null) ? $_POST['repetir'] : '';
            if (cliente_token_valido($token, $tipo) && $clave !== $repetir) {
                throw new HttpError(422, 'Las contraseñas no coinciden.');
            }
            cliente_token_consumir($token, $tipo, $clave);
            header('Location: /clientes/login?aviso=' . ($tipo === 'invitacion' ? 'activada' : 'cambiada'), true, 303);
            exit;
        } catch (HttpError $e) {
            $error = $e->campos['clave'] ?? $e->getMessage();
            http_response_code($e->status);
        }
    }
    $valido = cliente_token_valido($token, $tipo);
    portal_inicio($titulo, 'portal-auth');
    ?>
<main id="main" class="portal-auth-card card" tabindex="-1">
  <h1><?= e($titulo) ?></h1>
  <?php if (!$valido): ?>
    <p>Este link venció o ya se usó. Pedinos uno nuevo y te lo mandamos.</p>
    <a class="btn" href="/clientes/login">Ir a ingresar</a>
  <?php else: ?>
    <p><?= e($intro) ?></p>
    <?php if ($error !== null): ?>
      <div class="form-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <div class="campo">
        <label for="clave">Contraseña nueva</label>
        <div class="fila-clave">
          <input id="clave" name="clave" type="password" autocomplete="new-password" minlength="<?= CLIENTE_CLAVE_MIN ?>" required autofocus>
          <button type="button" class="btn" data-ver-clave="clave" aria-controls="clave" aria-pressed="false">Mostrar</button>
        </div>
        <small class="item-sub">Al menos <?= CLIENTE_CLAVE_MIN ?> caracteres.</small>
      </div>
      <div class="campo">
        <label for="repetir">Repetila</label>
        <input id="repetir" name="repetir" type="password" autocomplete="new-password" minlength="<?= CLIENTE_CLAVE_MIN ?>" required>
      </div>
      <button class="btn btn-primario" type="submit"><?= e($textoBoton) ?></button>
    </form>
  <?php endif; ?>
</main>
    <?php
    portal_fin();
}
```

- [ ] **Step 2: Crear `clientes/login.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

session_boot_cliente();
if (cliente_actual() !== null) {
    header('Location: /clientes', true, 302);
    exit;
}

$aviso = match ($_GET['aviso'] ?? null) {
    'activada' => 'Listo, tu contraseña quedó guardada. Ya podés ingresar.',
    'cambiada' => 'Listo, cambiaste tu contraseña. Ya podés ingresar.',
    default => null,
};
$error = null;
$email = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        csrf_check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null);
        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $clave = is_string($_POST['clave'] ?? null) ? $_POST['clave'] : '';
        $resultado = cliente_auth_attempt($email, $clave, client_ip());
        if ($resultado === 'ok') {
            header('Location: /clientes', true, 303);
            exit;
        }
        $error = $resultado === 'bloqueado'
            ? 'Demasiados intentos, probá en unos minutos.'
            : 'El email o la contraseña no son correctos.';
        http_response_code($resultado === 'bloqueado' ? 429 : 401);
    } catch (HttpError $e) {
        $error = $e->getMessage();
        http_response_code($e->status);
    }
}

portal_inicio('Ingresar', 'portal-auth');
?>
<main id="main" class="portal-auth-card card" tabindex="-1">
  <h1>Tu portal de VEZZA</h1>
  <p>Ingresá para ver tus servicios y tus tickets.</p>
  <?php if ($aviso !== null): ?>
    <div class="aviso" role="status"><?= e($aviso) ?></div>
  <?php endif; ?>
  <?php if ($error !== null): ?>
    <div class="form-error" role="alert"><?= e($error) ?></div>
  <?php endif; ?>
  <form method="post" action="/clientes/login">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="campo">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" value="<?= e($email) ?>" autocomplete="username" autocapitalize="none" required autofocus>
    </div>
    <div class="campo">
      <label for="clave">Contraseña</label>
      <div class="fila-clave">
        <input id="clave" name="clave" type="password" autocomplete="current-password" required>
        <button type="button" class="btn" data-ver-clave="clave" aria-controls="clave" aria-pressed="false">Mostrar</button>
      </div>
    </div>
    <button class="btn btn-primario" type="submit">Ingresar</button>
  </form>
  <p class="portal-enlace"><a href="/clientes/recuperar">Me olvidé la contraseña</a></p>
</main>
<?php
portal_fin();
```

- [ ] **Step 3: Crear `clientes/activar.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

portal_pagina_clave(
    'invitacion',
    'Activá tu acceso',
    'Elegí una contraseña para ingresar al portal de VEZZA.',
    'Activar mi acceso'
);
```

- [ ] **Step 4: Crear `clientes/recuperar.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

if (isset($_GET['token']) || isset($_POST['token'])) {
    portal_pagina_clave(
        'recuperacion',
        'Elegí tu contraseña nueva',
        'Escribí la contraseña con la que vas a ingresar de ahora en adelante.',
        'Guardar contraseña'
    );
    exit;
}

session_boot_cliente();
$enviado = false;
$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        csrf_check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null);
        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        cliente_recuperar_solicitar($email, client_ip());
        $enviado = true; // misma respuesta exista o no la cuenta
    } catch (HttpError $e) {
        $error = $e->getMessage();
        http_response_code($e->status);
    }
}

portal_inicio('Recuperar contraseña', 'portal-auth');
?>
<main id="main" class="portal-auth-card card" tabindex="-1">
  <h1>Recuperar tu contraseña</h1>
  <?php if ($enviado): ?>
    <div class="aviso" role="status">Si ese email tiene acceso al portal, te mandamos un link para elegir una contraseña nueva. Vale 2 horas.</div>
    <a class="btn" href="/clientes/login">Volver a ingresar</a>
  <?php else: ?>
    <p>Escribí el email con el que ingresás y te mandamos un link.</p>
    <?php if ($error !== null): ?>
      <div class="form-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/clientes/recuperar">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="campo">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="username" autocapitalize="none" required autofocus>
      </div>
      <button class="btn btn-primario" type="submit">Mandame el link</button>
    </form>
    <p class="portal-enlace"><a href="/clientes/login">Volver a ingresar</a></p>
  <?php endif; ?>
</main>
<?php
portal_fin();
```

- [ ] **Step 5: Crear `clientes/logout.php` y `clientes/index.php`**

`clientes/logout.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

session_boot_cliente();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: /clientes', true, 303);
    exit;
}
try {
    csrf_check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null);
} catch (HttpError) {
    header('Location: /clientes', true, 303);
    exit;
}
cliente_logout();
header('Location: /clientes/login', true, 303);
```

`clientes/index.php` (la Fase 2 reemplaza el contenido por el estado de los servicios):

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

$clienteId = require_cliente();
$nombre = (string)q_val('SELECT nombre FROM clientes WHERE id = ?', [$clienteId]);

portal_inicio('Inicio');
?>
<main id="main" class="portal-main" tabindex="-1">
  <header class="page-head">
    <h1>Hola, <?= e($nombre) ?></h1>
    <form method="post" action="/clientes/logout">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <button class="btn btn-chico" type="submit">Salir</button>
    </form>
  </header>
  <section class="card">
    <p>Desde acá vas a poder ver el estado de tus servicios, gestionar tus suscripciones y abrir tickets. Lo estamos terminando de armar.</p>
  </section>
</main>
<?php
portal_fin();
```

- [ ] **Step 6: Crear `clientes/.htaccess`, los assets y agregar las rutas al `.htaccess` raíz**

`clientes/.htaccess` (mismo motivo que `admin/.htaccess`: sin esto mod_dir redirige `/clientes` a `/clientes/` antes de que actúe la regla de reescritura):

```apache
# /clientes (sin barra) lo resuelve el .htaccess raíz; sin esto mod_dir redirige a /clientes/ antes.
DirectorySlash Off
```

`clientes/assets/portal.css`:

```css
/* VEZZA · Portal de clientes. Base, botones, formularios y tarjetas vienen de admin.css. */
body { padding-bottom: 0; }
.portal-main { max-width: 960px; margin: 0 auto; padding: 16px; }
@media (min-width: 768px) { .portal-main { padding: 24px 32px; } }

.portal-auth { min-height: 100vh; min-height: 100dvh; display: grid; place-items: center; padding: 16px; }
.portal-auth-card { width: min(420px, 100%); }
.portal-auth-card h1 { margin-bottom: 6px; }
.portal-auth-card > p { color: var(--muted); margin-bottom: 18px; }
.portal-auth-card .btn-primario { width: 100%; }
.portal-enlace { margin: 16px 0 0; text-align: center; font-size: .9rem; }
```

`clientes/assets/portal.js`:

```js
'use strict';

// Botón "Mostrar / Ocultar" de los campos de contraseña.
document.querySelectorAll('[data-ver-clave]').forEach((boton) => {
  const campo = document.getElementById(boton.dataset.verClave);
  if (!campo) return;
  boton.addEventListener('click', () => {
    const mostrar = campo.type === 'password';
    campo.type = mostrar ? 'text' : 'password';
    boton.textContent = mostrar ? 'Ocultar' : 'Mostrar';
    boton.setAttribute('aria-pressed', String(mostrar));
    campo.focus();
  });
});
```

Agregar al **final** del `.htaccess` raíz (no se modifica nada de lo de arriba):

```apache

# ==========================================================================
# Portal de clientes (/clientes). Agregado al final: no modifica nada de arriba.
# ==========================================================================
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteRule ^clientes/?$ clientes/index.php [L,QSA]
  RewriteRule ^clientes/(login|activar|recuperar|logout)$ clientes/$1.php [L,QSA]
</IfModule>
```

- [ ] **Step 7: Crear `scripts/verificar-portal.sh`**

```bash
#!/usr/bin/env bash
# Verifica rutas, bloqueos y headers del portal de clientes.
# Uso: scripts/verificar-portal.sh <url-base>
set -uo pipefail
base="${1:?Pasá la URL base, ej. https://vezzadev.com}"
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
esperar 200 /clientes/login
esperar 200 /clientes/recuperar
esperar 200 /clientes/activar
esperar 302 /clientes
esperar 401 "/api/acceso-cliente?cliente_id=1"
for ruta in /admin/includes/auth_cliente.php /admin/includes/mail.php \
            /admin/includes/repos/acceso_cliente.php /db/migrations/002_portal.sql; do
  esperar 404 "$ruta"
done

cuerpo=$(curl -s "$base/clientes/activar?token=zzz")
if grep -q "venció o ya se usó" <<<"$cuerpo"; then
  echo "OK    /clientes/activar con token inválido muestra 'link vencido'"
else
  echo "FALLA /clientes/activar con token inválido no muestra 'link vencido'"
  fallos=$((fallos + 1))
fi
cuerpo=$(curl -s "$base/clientes/activar?token%5B%5D=x")
if grep -q "venció o ya se usó" <<<"$cuerpo"; then
  echo "OK    /clientes/activar con token como array muestra 'link vencido'"
else
  echo "FALLA /clientes/activar con token como array no muestra 'link vencido'"
  fallos=$((fallos + 1))
fi

cabeceras=$(curl -sI "$base/clientes/login" | tr -d '\r')
for patron in '^x-robots-tag: noindex' '^cache-control: no-store' '^content-security-policy:'; do
  if grep -qi "$patron" <<<"$cabeceras"; then
    echo "OK    header $patron"
  else
    echo "FALLA falta header $patron en /clientes/login"
    fallos=$((fallos + 1))
  fi
done

echo
if [[ $fallos -eq 0 ]]; then echo "Todo OK"; else echo "$fallos verificaciones fallaron"; exit 1; fi
```

- [ ] **Step 8: Verificar sintaxis de todos los archivos PHP nuevos**

Run:
```bash
for f in admin/includes/portal_layout.php clientes/index.php clientes/login.php clientes/activar.php clientes/recuperar.php clientes/logout.php; do php -l "$f"; done
```
Expected: `No syntax errors detected` seis veces.

- [ ] **Step 9: Aplicar la migración a la base de desarrollo y probar las páginas con el servidor embebido**

La base de desarrollo (`.env`, `DB_NAME=vezza_admin`) todavía no tiene la 002.

```bash
php db/migrate.php
```
Expected: `Aplicadas: 002_portal`.

Crear un acceso de prueba, levantar el servidor y probar (el servidor embebido no lee `.htaccess`, por eso se usan las rutas `.php`):

```bash
php -r 'require "admin/includes/bootstrap.php"; $c = crud_insert("clientes", ["nombre" => "Prueba Portal", "email" => "prueba@vezza.test"]); $r = acceso_cliente_invitar($c, []); echo $r["link"], PHP_EOL;'
```
Expected: imprime un link `https://vezzadev.com/clientes/activar?token=<64 hex>` (el mail no sale porque `MAIL_WEBHOOK_URL` no está en `.env`: es lo esperado, `mail_enviado` da `false`).

```bash
php -S 127.0.0.1:8080 -t . > /tmp/portal-server.log 2>&1 &
sleep 2
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8080/clientes/login.php
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8080/clientes/index.php
curl -s 'http://127.0.0.1:8080/clientes/activar.php?token=zzz' | grep -c 'venció o ya se usó'
curl -s 'http://127.0.0.1:8080/clientes/activar.php?token%5B%5D=x' | grep -c 'venció o ya se usó'
```
Expected: `200`, `302`, `1`, `1`.

Abrir en el navegador `http://127.0.0.1:8080/clientes/activar.php?token=<el token del link>` (reemplazando el dominio por `127.0.0.1:8080` y `.php`), elegir una contraseña de 6 caracteres, verificar que redirige al login con el aviso verde, ingresar y ver "Hola, Prueba Portal". Probar también con el ancho de ventana en 360 px.

Cerrar el servidor: `kill %1`. Limpiar el cliente de prueba:

```bash
php -r 'require "admin/includes/bootstrap.php"; db()->exec("DELETE FROM clientes WHERE nombre = \"Prueba Portal\"");'
```

- [ ] **Step 10: Commit**

```bash
git add admin/includes/portal_layout.php clientes .htaccess scripts/verificar-portal.sh
git commit -m "Agregar las pantallas de ingreso, activación y recuperación del portal" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Bloque "Acceso al portal" en la ficha del cliente (admin)

**Files:**
- Create: `admin/assets/mod-acceso.js`
- Modify: `admin/cliente.php`
- Modify: `admin/assets/cliente.js`
- Modify: `admin/includes/layout.php` (línea de `PANEL_ASSET_V`)

**Interfaces:**
- Consumes: endpoint `/api/acceso-cliente` (Task 6); `Panel.get/post/el/llenar/boton/badge/modalForm/confirmar/toast/manejarError/qs/fmtFechaHora` (existentes en `admin.js`).
- Produces: módulo global `Acceso` con `Acceso.vista(clienteId, cliente, acceso, recargar): HTMLElement`.

- [ ] **Step 1: Crear `admin/assets/mod-acceso.js`**

```js
'use strict';

const Acceso = (() => {
  const { el } = Panel;

  const ESTADOS = {
    sin_acceso: ['Sin acceso', ''],
    invitado: ['Invitación pendiente', 'warn'],
    activo: ['Activo', 'ok'],
    desactivado: ['Desactivado', 'danger'],
  };

  const url = (clienteId, accion) => `/api/acceso-cliente${Panel.qs({ cliente_id: clienteId, accion })}`;

  const dato = (label, valor) => el('div', {},
    el('div', { class: 'stat-label', text: label }),
    valor ? el('div', { text: valor }) : el('div', { class: 'item-sub', text: '—' }));

  function mostrarLink(r) {
    const ayuda = r.mail_enviado
      ? 'Le mandamos el mail con este link. Igual podés copiarlo.'
      : 'No pudimos mandar el mail. Copiá el link y mandáselo por WhatsApp o Instagram.';
    return Panel.modalForm({
      titulo: 'Invitación lista',
      campos: [{ name: 'link', label: 'Link de invitación (vale 72 horas)', soloLectura: true, ayuda }],
      valores: { link: r.link },
      textoBoton: 'Copiar link',
      enviar: async () => {
        await navigator.clipboard.writeText(r.link);
        Panel.toast('Link copiado');
        return { copiado: true };
      },
    });
  }

  async function invitar(clienteId, emailInicial) {
    const r = await Panel.modalForm({
      titulo: 'Invitar al portal',
      campos: [{
        name: 'email', label: 'Email del cliente', type: 'email', required: true,
        ayuda: 'Va a usar este email para ingresar. Si ya tenía una invitación, la anterior deja de funcionar.',
      }],
      valores: { email: emailInicial },
      textoBoton: 'Generar invitación',
      enviar: (d) => Panel.post(url(clienteId, 'invitar'), { email: d.email }),
    });
    if (!r) return false;
    await mostrarLink(r);
    return true;
  }

  async function cambiarActivo(clienteId, activo) {
    if (!activo && !(await Panel.confirmar('El cliente no va a poder ingresar al portal hasta que lo reactives.', 'Desactivar'))) return false;
    await Panel.post(url(clienteId, 'activo'), { activo });
    return true;
  }

  function vista(clienteId, cliente, acceso, recargar) {
    const [texto, variante] = ESTADOS[acceso.estado] ?? [acceso.estado, ''];
    const botones = [
      Panel.boton(acceso.estado === 'sin_acceso' ? 'Invitar al portal' : 'Enviar invitación nueva', async () => {
        if (await invitar(clienteId, acceso.email || cliente.email || '')) recargar();
      }, 'chico'),
    ];
    if (acceso.estado !== 'sin_acceso') {
      const activar = acceso.estado === 'desactivado';
      botones.push(Panel.boton(activar ? 'Reactivar acceso' : 'Desactivar acceso', async () => {
        try {
          if (await cambiarActivo(clienteId, activar)) recargar();
        } catch (err) {
          Panel.manejarError(err);
        }
      }, 'chico'));
    }
    return el('div', {},
      el('div', { class: 'item-top' },
        el('h2', { text: 'Acceso al portal' }),
        Panel.badge(texto, variante)),
      el('div', { class: 'datos' },
        dato('Email', acceso.email),
        dato('Último ingreso', acceso.ultimo_login ? Panel.fmtFechaHora(acceso.ultimo_login) : null),
        dato('La invitación vence', acceso.invitacion_vigente ? Panel.fmtFechaHora(acceso.invitacion_vence) : null)),
      el('div', { class: 'item-acciones seccion' }, botones));
  }

  return { vista };
})();
```

- [ ] **Step 2: Agregar la sección y el script en `admin/cliente.php`**

Cambiar la lista de scripts para sumar `mod-acceso.js` antes de `cliente.js`:

```php
layout_start('Cliente', 'clientes', [
    'mod-clientes.js', 'mod-procesos.js', 'mod-cobros.js', 'mod-fixs.js', 'mod-eventos.js', 'mod-acceso.js', 'cliente.js',
], ['cliente-id' => $id]);
```

y agregar la sección del acceso entre la ficha y las pestañas:

```php
<section id="ficha" class="card" aria-live="polite"></section>
<section id="acceso" class="card seccion" aria-live="polite"></section>
<section id="pestanas" class="seccion"></section>
```

- [ ] **Step 3: Cargar el bloque desde `admin/assets/cliente.js`**

Agregar la función después de `cargarFicha`:

```js
  async function cargarAcceso() {
    const [c, acceso] = await Promise.all([
      Panel.get(`/api/clientes/${id}`),
      Panel.get('/api/acceso-cliente', { cliente_id: id }),
    ]);
    Panel.llenar(document.getElementById('acceso'),
      Acceso.vista(id, c, acceso, () => cargarAcceso().catch(Panel.manejarError)));
  }
```

y reemplazar el bloque final (`cargarFicha()\n    .then(...)\n    .catch(...)`) por este, para que un fallo del acceso no bloquee la ficha ni las pestañas:

```js
  cargarFicha()
    .then(() => Panel.tabs(document.getElementById('pestanas'), TABS))
    .catch(Panel.manejarError);
  cargarAcceso().catch(Panel.manejarError);
```

- [ ] **Step 4: Subir la versión de assets del panel**

En `admin/includes/layout.php`:

```php
const PANEL_ASSET_V = '4';
```

- [ ] **Step 5: Verificar a mano con el servidor embebido**

```bash
php -l admin/cliente.php
php -S 127.0.0.1:8080 -t . > /tmp/portal-server.log 2>&1 &
```

Para entrar al admin local hace falta el usuario y el hash del `.env`. En el navegador: `http://127.0.0.1:8080/admin/login.php`, ingresar, abrir `http://127.0.0.1:8080/admin/cliente.php?id=<un id de la tabla clientes>`. Comprobar:
1. Aparece la tarjeta "Acceso al portal" con la insignia "Sin acceso".
2. "Invitar al portal" abre el formulario con el email del cliente. Al generar, muestra el link con el aviso de "No pudimos mandar el mail" (local no tiene n8n) y "Copiar link" lo copia.
3. La insignia pasa a "Invitación pendiente" y muestra cuándo vence.
4. "Desactivar acceso" pide confirmación y deja "Desactivado". "Reactivar acceso" lo devuelve.
5. Con un ancho de 360 px los botones y el modal se usan bien.

Cerrar el servidor: `kill %1`.

- [ ] **Step 6: Commit**

```bash
git add admin/assets/mod-acceso.js admin/cliente.php admin/assets/cliente.js admin/includes/layout.php
git commit -m "Agregar el bloque de acceso al portal en la ficha del cliente" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Configuración, documentación, workflow de n8n y verificación final

**Files:**
- Modify: `.env.example`
- Modify: `DEPLOY.md`
- Externo: workflow de n8n "VEZZA · Mails del portal"

**Interfaces:**
- Consumes: contrato del webhook (Task 3).
- Produces: variables de entorno documentadas y el workflow activo que envía los mails.

- [ ] **Step 1: Documentar las variables en `.env.example`**

Agregar al final:

```dotenv
# Portal de clientes. PORTAL_URL: dominio público (sin barra final), se usa en los links de los mails.
PORTAL_URL=https://vezzadev.com
# Webhook de n8n que manda los mails de invitación y recuperación. Vacío = no se mandan mails (el admin copia el link).
MAIL_WEBHOOK_URL=
MAIL_WEBHOOK_SECRET=
```

- [ ] **Step 2: Documentar el portal en `DEPLOY.md`**

Agregar al final una sección nueva:

````markdown
## Portal de clientes (`/clientes`)

Vive en el mismo repo y el mismo `public_html`. Es PHP plano más la misma base MySQL del panel. El `git push` sigue siendo el único paso de deploy.

### Primer deploy del portal

1. Después del push, entrá a `https://vezzadev.com/admin/migraciones` y tocá **Aplicar migraciones** (aplica `002_portal`).
2. Agregá al `.env` (un nivel arriba de `public_html`):

   ```dotenv
   PORTAL_URL=https://vezzadev.com
   MAIL_WEBHOOK_URL=https://vmezza.app.n8n.cloud/webhook/vezza-portal-mail
   MAIL_WEBHOOK_SECRET=un-secreto-largo-y-aleatorio
   ```

   El secreto tiene que ser el mismo que usa el workflow de n8n "VEZZA · Mails del portal". Sin `MAIL_WEBHOOK_URL` el portal funciona igual, pero los mails no salen: desde la ficha del cliente copiás el link de invitación y lo mandás por WhatsApp o Instagram.
3. Corré `scripts/verificar-portal.sh https://vezzadev.com` y confirmá `Todo OK`.

### Cómo dar acceso a un cliente

En el admin, abrí la ficha del cliente → **Acceso al portal** → **Invitar al portal**. El cliente recibe un mail con un link que vale 72 horas para elegir su contraseña (mínimo 6 caracteres). Si el mail no llega, copiá el link que muestra el panel. Desde ahí también podés mandar una invitación nueva o desactivar el acceso.

### Si un cliente se olvida la contraseña

Puede pedir un link en `/clientes/recuperar` (vale 2 horas). Si no le llega, mandale una invitación nueva desde su ficha.
````

- [ ] **Step 3: Confirmar con Valentino y crear el workflow de n8n**

Esto publica en un servicio externo (la instancia `vmezza.app.n8n.cloud`). **Preguntarle a Valentino antes de crearlo o activarlo.** Especificación del workflow "VEZZA · Mails del portal":

1. **Webhook**: método `POST`, path `vezza-portal-mail`, respuesta "Using Respond to Webhook Node". Sin CORS (lo llama el servidor PHP, no un navegador).
2. **IF** "Secreto correcto": la expresión `{{ $json.headers['x-webhook-secret'] }}` es igual al secreto que Valentino elija (el mismo de `MAIL_WEBHOOK_SECRET`). Si no coincide: **Respond to Webhook** con código 401 y cuerpo `{"ok": false}`.
3. **Switch** por `{{ $json.body.plantilla }}`: rama `invitacion` y rama `recuperacion`. Cualquier otro valor: Respond 400.
4. **Send Email** (credencial SMTP "SMTP account", id `9AFbeBMpyrnC04da`), remitente `VEZZA <info@vezzadev.com>`, destinatario `{{ $json.body.destino }}`:
   - Rama `invitacion`: asunto `Te invitamos a tu portal de VEZZA`. Cuerpo: `Hola {{ $json.body.datos.nombre }}, ya podés entrar a tu portal de VEZZA para ver el estado de tus servicios, gestionar tus suscripciones y abrir tickets. Activá tu acceso y elegí tu contraseña desde este link (vale 72 horas): {{ $json.body.datos.link }}  Si no esperabas este mail, ignoralo. — VEZZA`
   - Rama `recuperacion`: asunto `Cambiá tu contraseña de VEZZA`. Cuerpo: `Pediste cambiar tu contraseña del portal de VEZZA. Usá este link (vale 2 horas): {{ $json.body.datos.link }}  Si no fuiste vos, ignorá este mail: tu contraseña sigue igual. — VEZZA`
5. **Respond to Webhook** con código 200 y `{"ok": true}` después de enviar.

Este flujo se puede construir con las herramientas de n8n disponibles en la sesión (`n8n_create_workflow`, `n8n_validate_workflow`). Después de crearlo: validar, activarlo y probarlo desde la ficha de un cliente de prueba con un email real (MailChannels rechaza direcciones tipo `test@gmail.com`). El secreto elegido se guarda solo en el `.env` del hosting y en n8n, nunca en el repo.

- [ ] **Step 4: Verificación final completa**

Run:
```bash
php vendor/bin/phpunit
```
Expected: `OK` con 185 tests (los 125 originales sin modificar más 60 nuevos), sin warnings.

Run:
```bash
git status --short
git log --oneline main..portal-clientes
```
Expected: árbol limpio y 8 commits en la rama (Tasks 1 a 8). El de la Task 9 se hace en el paso siguiente, y el spec y el plan ya están en `main`.

- [ ] **Step 5: Commit**

```bash
git add .env.example DEPLOY.md
git commit -m "Documentar el deploy y la configuración del portal de clientes" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 6: Publicar (solo con el OK de Valentino)**

Publicar es lo que dispara el deploy a producción: cada push a `main` se publica en Hostinger. **No hacer merge ni push sin confirmación explícita.** Con el OK:

```bash
git push -u origin portal-clientes
```

Abrir el PR hacia `main` con `gh pr create`. Después del merge y del deploy: aplicar la migración desde `/admin/migraciones`, completar el `.env` y correr `scripts/verificar-portal.sh https://vezzadev.com` (Task 9, pasos 2 y 3 de "Primer deploy del portal"). Hacer esa secuencia en ese orden: el código del portal consulta tablas que recién existen al aplicar la migración.

---

## Self-review

**Cobertura del spec (fase 1):**
- §4.1 admin sin cambios: Task 2 (regresión completa) · §4.2 cliente paralelo, cookie propia, guards: Tasks 2 y 4 · §4.3 refactor de sesión: Task 2 · §4.4 aislamiento: `cliente_actual()` saca el `cliente_id` de la sesión y de la base (Task 4); la verificación por recurso ajeno aplica desde la Fase 2, cuando existen endpoints con IDs del request · §4.5 login, límites por IP y por cuenta, invitación, recuperación, desactivar corta la sesión: Tasks 4, 5 y 6.
- §5 esquema completo (todas las tablas, `fixs.ticket_id`, `login_intentos`): Task 1.
- §6 pantallas de acceso (`login`, `activar`, `recuperar`, `logout`) y la home mínima: Task 7. Las pantallas de estado, suscripciones y tickets son de las fases 2 a 4.
- §7 bloque "Acceso al portal" en la ficha: Task 8. Las demás pantallas de admin son de las fases 2 a 4.
- §8 mails por webhook con respaldo manual y variables de `.env`: Tasks 3, 6 y 9. El cron y el chequeo son de la Fase 2.
- §9 seguridad: contraseña de 6 (Task 4), tokens hasheados (Task 5), `recuperar` sin revelar (Tasks 5 y 7), CSRF y headers (Task 7), `textContent` (Task 8).
- §10 pruebas: aislamiento de sesiones, tokens, contraseña, límites, regresión: Tasks 1 a 6, y `verificar-portal.sh` en Task 7.

**Decisiones menores que el spec no fijaba (se tomaron acá y se pueden vetar):**
- La recuperación de contraseña vence a las **2 horas** (la invitación, 72 h como dice el spec). Un link de recuperación de 72 h sería innecesariamente largo.
- `usuarios_cliente` tiene `UNIQUE (cliente_id)`: un acceso por cliente, coherente con "varios usuarios por cliente" fuera de alcance.
- Invitar de nuevo a un acceso desactivado lo reactiva. Desactivar/reactivar a secas siguen disponibles.
- Un cliente con la cuenta bloqueada por 5 fallos queda bloqueado 15 minutos aunque use la clave correcta. Es el costo de limitar por cuenta, y es lo que el spec pidió.

**Escaneo de placeholders:** ninguna tarea tiene "TBD", "similar a la tarea N" ni pasos sin código. El único paso con contenido por decidir en ejecución es el secreto del webhook de n8n (Task 9, paso 3), y está explícito que lo elige Valentino y solo vive en el `.env` del hosting y en n8n.

**Consistencia de tipos y nombres:** `cliente_token_emitir` (Task 5) es la que consume `acceso_cliente_invitar` (Task 6) con `('invitacion')`. `cliente_token_valido` / `cliente_token_consumir` (Task 5) son las que usa `portal_pagina_clave` (Task 7). `portal_url` y `mail_enviar` (Task 3) se usan igual en las Tasks 5 y 6. `CLIENTE_CLAVE_MIN` (Task 4) se usa en las pantallas (Task 7). `acceso_cliente_get` devuelve las claves que `Acceso.vista` lee en el JS (`estado`, `email`, `ultimo_login`, `invitacion_vigente`, `invitacion_vence`). El módulo JS se llama `Acceso` en los dos archivos que lo usan.

**Review Focus:** las 5 líneas tienen su test: (1) `test_el_email_se_normaliza_al_ingresar`; (2) `test_los_tokens_mal_formados...` y el caso del array en `verificar-portal.sh`; (3) `test_la_clave_se_respeta_tal_cual...` y `test_una_clave_de_mas_de_72_bytes...`; (4) `test_reinvitar_*` e `invitar_con_el_email_de_otro_cliente_da_409`; (5) `test_desactivar_el_acceso_corta_la_sesion_abierta`.

**Pendiente para las fases siguientes (no son huecos de esta):** API del portal (`/clientes/api/*`) y aislamiento por recurso (Fase 2), confirmar cron en Hostinger (Fase 2), categorías y tickets (Fase 3), suscripciones (Fase 4).
