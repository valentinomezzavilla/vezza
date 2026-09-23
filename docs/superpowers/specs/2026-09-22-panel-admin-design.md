# Panel de administración VEZZA — Diseño

Fecha: 2026-09-22 · Estado: pendiente de revisión

## 1. Objetivo

Sumar a vezzadev.com un panel privado para gestionar el negocio:

- clientes
- procesos
- cobros
- gastos
- tareas
- incidencias
- agenda
- reportes

Va en el mismo dominio, el mismo repo y el mismo deploy. Lo usa un solo admin y tiene que funcionar bien desde el celular.

### Criterios de éxito

- La landing (`/`) devuelve exactamente el mismo HTML y los mismos headers que antes del cambio. El deploy sigue siendo `git push` a `main`, sin pasos nuevos para la landing.
- Sin sesión válida no se puede ver ninguna página de `/admin/*` ni ningún endpoint de `/api/*`.
- No hay secretos en el repo: contraseña, secreto de sesión y credenciales de base viven solo en `.env`.
- Todas las pantallas se pueden usar en un celular de 360px de ancho.

### Fuera de alcance

- Varios usuarios, roles o recuperación de contraseña. Si te olvidás la contraseña, se regenera el hash y se edita el `.env`.
- Conversión de monedas.
- Notificaciones push o por mail.
- Facturación electrónica.

## 2. Contexto actual (inspección)

- El sitio es estático, hecho con HTML, CSS y JS puros (`index.html`, `css/styles.css`, `js/main.js`). No tiene build, `package.json` ni backend.
- El formulario de contacto hace POST a un webhook externo de n8n. El repo no usa ninguna base de datos.
- Está en Hostinger, plan Business. El deploy por Git copia el repo entero a `public_html` y Apache/LiteSpeed sirve los archivos directamente.
- El `.htaccess` actual fuerza HTTPS sin `www`, bloquea `.git/`, `imgs/` y los `.md`, y define caché, compresión y headers de seguridad.

## 3. Decisión de stack

Uso **PHP 8 plano + MySQL/MariaDB**, sin framework.

**Por qué:** Hostinger ejecuta PHP de forma nativa dentro de `public_html`, así que:

- el deploy no cambia;
- Apache sigue sirviendo la landing directo, con su caché y compresión;
- no hay que mantener un proceso vivo.

**Descarté Node/Express** porque, en Hostinger, una app Node toma el dominio entero a través de Passenger. Eso obliga a reimplementar la caché de la landing y a cambiar el deploy.

**Dependencias:**

- SortableJS: copia local, para el drag & drop en pantallas táctiles.
- PHPUnit: solo para desarrollo, vía Composer. `vendor/` no se commitea.

## 4. Estructura de archivos

```
public_html/  (= raíz del repo)
├── index.html, css/, js/, assets/, imgs/, robots.txt, sitemap.xml   ← sin cambios
├── .htaccess          ← se agregan bloques al final, no se toca lo existente
├── .gitignore         ← nuevo: .env, vendor/, uploads locales
├── .env.example       ← plantilla sin valores reales
├── admin/
│   ├── login.php  logout.php  index.php
│   ├── clientes.php  cliente.php
│   ├── procesos.php  proceso.php
│   ├── cobros.php  gastos.php  tareas.php  fixs.php  agenda.php  reportes.php
│   ├── includes/      env.php db.php auth.php csrf.php http.php layout.php
│   └── assets/        admin.css, admin.js (utilidades comunes), <página>.js, vendor/sortable.min.js
├── api/
│   ├── clientes.php notas-cliente.php procesos.php subtareas.php cobros.php
│   ├── suscripciones.php gastos.php tareas.php fixs.php eventos.php
│   ├── dashboard.php agenda.php reportes.php comprobante.php
├── db/
│   ├── migrations/001_inicial.sql ...
│   └── migrate.php    ← CLI o navegador con sesión admin
├── scripts/
│   └── hash-password.php   ← CLI: genera ADMIN_PASSWORD_HASH
├── tests/             ← PHPUnit (no hace falta en producción)
└── composer.json      ← solo require-dev
```

**Afuera de `public_html` (en el servidor, no en el repo):**

- `../.env`: es la ubicación preferida; `env.php` la busca primero ahí y después en la raíz.
- `../vezza_uploads/comprobantes/`: los comprobantes adjuntos.

## 5. Modelo de datos

Todas las tablas:

- usan InnoDB y `utf8mb4`;
- tienen `id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY`;
- tienen `created_at` y `updated_at` (`TIMESTAMP`), salvo que se indique otra cosa.

Los montos son `DECIMAL(12,2)`. Las monedas son `CHAR(3)` con código ISO (ARS, USD…).

**Tablas:**

- **clientes**: `nombre` (NN), `email`, `telefono`, `rubro`, `estado` ENUM('activo','pausado','finalizado') default 'activo', `fecha_inicio` DATE.
- **notas_cliente**: `cliente_id` FK → clientes ON DELETE CASCADE, `contenido` TEXT NN.
- **procesos**: `cliente_id` FK ON DELETE CASCADE, `titulo` NN, `descripcion` TEXT, `estado` ENUM('por_hacer','en_curso','en_revision','entregado') default 'por_hacer', `prioridad` ENUM('baja','media','alta') default 'media', `fecha_inicio` DATE, `fecha_entrega_estimada` DATE, `orden` INT default 0 (posición dentro de la columna del kanban).
- **subtareas**: `proceso_id` FK ON DELETE CASCADE, `titulo` NN, `completada` TINYINT(1) default 0, `orden` INT default 0.
- **cobros**: `cliente_id` FK ON DELETE RESTRICT, `proceso_id` FK nullable ON DELETE SET NULL, `concepto`, `monto` NN, `moneda` NN, `fecha_vencimiento` DATE NN, `fecha_pago` DATE nullable, `estado` ENUM('pendiente','pagado') default 'pendiente', `metodo_pago`, `comprobante_url` (link externo), `comprobante_archivo` (nombre interno del archivo subido).
  - El estado **vencido** se calcula: `estado='pendiente' AND fecha_vencimiento < CURDATE()`. La API devuelve un campo `estado_efectivo` con 'pendiente', 'pagado' o 'vencido'.
  - Al marcar un cobro como pagado sin `fecha_pago`, se completa con la fecha de hoy.
- **suscripciones**: `servicio` NN, `categoria`, `monto` NN, `moneda` NN, `frecuencia` ENUM('mensual','anual') NN, `fecha_proximo_cobro` DATE NN, `activa` TINYINT(1) default 1.
- **gastos**: `suscripcion_id` FK nullable ON DELETE SET NULL, `concepto` NN, `categoria`, `monto` NN, `moneda` NN, `fecha` DATE NN.
  - La acción "Registrar pago" de una suscripción inserta un gasto con los datos de la suscripción y `fecha` = la fecha de próximo cobro, que se puede editar.
  - Después, la fecha de próximo cobro avanza 1 mes o 1 año. Si el día no existe en el mes destino, se usa el último día del mes.
- **tareas_personales**: `titulo` NN, `descripcion`, `estado` ENUM('pendiente','en_curso','hecha') default 'pendiente', `prioridad` ENUM('baja','media','alta') default 'media', `fecha_vencimiento` DATE.
- **fixs**: `cliente_id` FK ON DELETE CASCADE, `proceso_id` FK nullable ON DELETE SET NULL, `titulo` NN, `descripcion`, `estado` ENUM('reportado','en_progreso','resuelto') default 'reportado', `prioridad` default 'media', `fecha_reportado` DATE NN default hoy, `fecha_resuelto` DATE, `orden` INT default 0.
  - Al pasar un fix a 'resuelto', `fecha_resuelto` se completa con la fecha de hoy si estaba vacía.
  - Si sale de 'resuelto', `fecha_resuelto` se borra.
- **eventos**: `cliente_id` FK nullable ON DELETE SET NULL, `titulo` NN, `descripcion`, `fecha_hora` DATETIME NN, `duracion_min` INT nullable, `tipo` ENUM('reunion','llamada','recordatorio','otro') default 'otro'.
- **login_intentos**: `ip` VARCHAR(45), `creado_en` TIMESTAMP, con índice por (`ip`, `creado_en`). No tiene `updated_at`.
- **migraciones**: `nombre` PK, `aplicada_en` TIMESTAMP.

### Borrado de clientes

Los cobros usan `RESTRICT`, así que un cliente con cobros no se puede borrar: el historial de ingresos no se pierde por accidente. La interfaz lo explica y te ofrece marcar al cliente como 'finalizado'.

Todo lo demás del cliente se borra en cascada, con confirmación previa en la interfaz.

**Índices:** todas las FK, más:

- `procesos(estado)`
- `cobros(estado, fecha_vencimiento)` y `cobros(fecha_pago)`
- `gastos(fecha)`
- `suscripciones(activa, fecha_proximo_cobro)`
- `eventos(fecha_hora)`
- `fixs(estado)`

**Zona horaria:** PHP y MySQL usan `America/Argentina/Buenos_Aires`. Se configura en `db.php` con `SET time_zone`.

## 6. Rutas

### Reglas de `.htaccess`

Se agregan al final del archivo. Las reglas existentes quedan intactas.

- Bloquean `.env*`, `composer.*`, `/admin/includes/`, `/db/`, `/scripts/`, `/tests/` y `/vendor/` (404).
- Mandan `X-Robots-Tag: noindex, nofollow` y `Cache-Control: no-store` en `/admin*` y `/api/*`.
- Definen las URLs limpias (tabla siguiente). Además, `/admin/migraciones` → `admin/migraciones.php`.

| URL | Archivo |
|---|---|
| `/admin-login` | `admin/login.php` (GET formulario, POST credenciales) |
| `/admin-logout` | `admin/logout.php` (solo POST con CSRF) |
| `/admin` | `admin/index.php` (dashboard) |
| `/admin/clientes` · `/admin/clientes/{id}` | `clientes.php` · `cliente.php?id=` |
| `/admin/procesos` · `/admin/procesos/{id}` | `procesos.php` · `proceso.php?id=` |
| `/admin/cobros`, `/gastos`, `/tareas`, `/fixs`, `/agenda`, `/reportes` | `<nombre>.php` |
| `/api/{recurso}` · `/api/{recurso}/{id}` | `api/{recurso}.php` · `?id=` |
| `/api/suscripciones/{id}/pagar` | `api/suscripciones.php?id=&accion=pagar` |

### Contrato de la API

- Todo se envía y se recibe en JSON (`Content-Type: application/json`). Solo la subida de comprobantes usa `multipart/form-data`.
- **Listas:** `GET /api/{recurso}` responde `{ "data": [...] }`.
- **Detalle:** `GET /api/{recurso}/{id}` responde `{ "data": {...} }`.
- **Alta:** `POST` crea el registro y responde `201` con `{ "data": {...} }`.
- **Edición:** `PUT /{id}` hace una actualización parcial: solo cambian los campos enviados.
- **Borrado:** `DELETE /{id}` responde `204`.
- **Errores:**
  - `400` y `422` responden `{ "error": "mensaje", "campos": { "campo": "motivo" } }`.
  - `401`: no hay sesión.
  - `403`: el token CSRF es inválido.
  - `404`: el registro no existe.
  - `409`: hay un conflicto, por ejemplo borrar un cliente que tiene cobros.
  - `500`: responde un mensaje genérico. El detalle queda en el log de errores de PHP.
- **Reordenar:** `PUT /api/{procesos|fixs|subtareas}/{id}` acepta `estado` y `antes_de`, que es el id de la tarjeta que queda justo después, o `null` para ubicarla al final. El servidor renumera la columna destino.
  - Se usa un id y no un índice porque, con el tablero filtrado por cliente, el índice visible no coincide con la posición real en la columna.

**Filtros:**

- **procesos:** `cliente_id`, `estado`
- **cobros:** `cliente_id`, `proceso_id`, `estado` (pendiente|pagado|vencido), `desde`, `hasta`
- **gastos:** `desde`, `hasta`, `categoria`
- **fixs:** `cliente_id`, `estado`
- **eventos:** `desde`, `hasta`
- **notas-cliente** y **subtareas:** `cliente_id` y `proceso_id`, respectivamente (obligatorios)

**Endpoints agregados, solo lectura:**

- `GET /api/dashboard` devuelve:
  - ingresos del mes: cobros pagados con `fecha_pago` en el mes, por moneda;
  - gastos del mes, por moneda;
  - balance por moneda;
  - cobros pendientes y vencidos;
  - suscripciones que se cobran en los próximos 14 días;
  - procesos activos (no entregados) agrupados por cliente;
  - cantidad de tareas personales abiertas y de fixs abiertos;
  - los próximos 5 eventos.
- `GET /api/agenda?desde=&hasta=`: el rango máximo es de 93 días. Devuelve una lista unificada de ítems con la forma `{ tipo, fecha, titulo, ref }`.
  - Los tipos son: `evento`, `cobro` (pendientes por fecha de vencimiento), `suscripcion` (fecha de próximo cobro de las activas), `entrega` (fecha de entrega estimada de procesos no entregados), `tarea` (fecha de vencimiento de tareas abiertas).
- `GET /api/reportes/balance?desde=&hasta=&cliente_id=` devuelve, por mes y por moneda, ingresos, gastos y balance.
  - Si hay `cliente_id`, filtra solo los ingresos. Los gastos no están asociados a clientes, así que en ese caso la respuesta los omite y lo indica.
- `GET /api/comprobante/{cobro_id}` sirve el archivo adjunto con su tipo real.
  - Usa `Content-Disposition: inline` y `X-Content-Type-Options: nosniff`.
  - Exige sesión.

## 7. Autenticación y seguridad

- **Variables de `.env`:** `ADMIN_USERNAME`, `ADMIN_PASSWORD_HASH`, `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `APP_ENV` (local|production), `UPLOADS_DIR`.
  - No hay secreto de sesión porque se usa la sesión nativa de PHP.
  - El hash se genera con `php scripts/hash-password.php`, que pide la contraseña sin mostrarla en pantalla.
- **Login:**
  1. Si la IP tiene 5 o más intentos fallidos en los últimos 15 minutos, responde "Demasiados intentos, probá en unos minutos".
  2. Compara el usuario con `hash_equals` y la contraseña con `password_verify`.
  3. El mensaje de error es el mismo sin importar qué dato falló.
  4. Si el login sale bien: `session_regenerate_id(true)` y se borran los intentos de esa IP.
  5. Si falla, se registra el intento.
  6. Los intentos de más de 1 día se borran de forma oportunista.
- **Cookie de sesión:**
  - nombre `vezza_admin`;
  - `HttpOnly`, `SameSite=Strict`, y `Secure` cuando `APP_ENV=production`;
  - `Path=/`;
  - `gc_maxlifetime` y duración de la cookie de 7 días; `last_activity` se renueva en cada request.
  - Las sesiones se guardan en una carpeta propia fuera de `public_html` si el hosting lo permite; si no, en la carpeta por defecto.
- **`require_admin()`:** si no hay sesión, redirige con 302 a `/admin-login?next=<ruta>`. Solo acepta rutas relativas que empiecen con `/admin`, para evitar redirecciones abiertas.
- **`require_admin_api()`:** si no hay sesión, responde `401` en JSON.
- **CSRF:**
  - El token se crea por sesión con `random_bytes(32)` y se expone en `<meta name="csrf-token">`.
  - Es obligatorio en todo método distinto de GET, tanto en la API (header `X-CSRF-Token`) como en los formularios de login y logout (campo oculto).
  - El login usa un token de la sesión previa al login.
- **SQL:** PDO con `ERRMODE_EXCEPTION`, consultas preparadas reales (`EMULATE_PREPARES=false`) y validación por lista blanca de los campos ordenables y filtrables.
- **XSS:** `htmlspecialchars` en el PHP y `textContent` o `createElement` en el JS. Nunca se usa `innerHTML` con datos.
- **Subidas de comprobantes:**
  - hasta 5 MB;
  - el tipo se detecta con `finfo`, entre PDF, JPEG, PNG y WEBP;
  - el nombre se genera con `bin2hex(random_bytes(16))` más la extensión según el tipo;
  - se guardan en `UPLOADS_DIR`;
  - si se reemplaza o se borra el cobro, se borra el archivo anterior.
- **Headers:** en el panel se agrega una CSP estricta (`default-src 'self'`, sin scripts inline, fuentes de Google Fonts permitidas). La CSP de la landing no se toca.

## 8. Frontend del panel

- Cada página es un archivo PHP con `layout.php`, que arma el head, la navegación y el meta del CSRF.
- Cada página tiene su propio JS, que usa `admin.js` (helpers comunes). No hay framework.
- **admin.js:**
  - `api(method, url, body)`: fetch con JSON y el token CSRF. Si recibe un 401, redirige al login.
  - Helpers para formularios, modales, toasts y formateo de montos, que usan `Intl.NumberFormat('es-AR')` con la moneda de cada monto.
  - Confirmación antes de borrar.
- **Navegación:**
  - En el celular, barra fija abajo con: Inicio, Clientes, Procesos, Cobros y "Más" (Gastos, Tareas, Fixs, Agenda, Reportes, Salir).
  - Desde 900px de ancho, sidebar lateral.
- **Estilo:**
  - fondo `#F5F3EE`, texto `#15131C`, acento `#3D2FE0`;
  - Sora para los títulos y Manrope para el texto;
  - áreas táctiles de al menos 44px;
  - las tablas se convierten en tarjetas debajo de 640px;
  - los formularios se abren en un modal, que en el celular ocupa toda la pantalla.
- **Kanban de procesos y fixs:**
  - Una columna por estado, con scroll horizontal y snap en el celular.
  - SortableJS con `delay` táctil, para que el scroll no dispare un arrastre.
  - Cada tarjeta tiene además un selector de estado.
  - Filtro por cliente.
  - Procesos también tiene vista de lista.
- **Subtareas:** checklist en el detalle del proceso, con alta rápida, tildado y reordenamiento. Las tarjetas del kanban muestran el avance, por ejemplo "3/7".
- **Agenda:**
  - En desktop, grilla de mes; en el celular, lista agrupada por día.
  - Colores por tipo de ítem.
  - Tocar un ítem lleva a su registro.
  - El botón "+ Evento" abre el modal de alta.
- **Reportes:** filtros de fecha y cliente, tabla mensual por moneda y barras hechas en CSS con ingresos y gastos lado a lado.
- **Cliente:** ficha con datos editables y pestañas de Procesos, Cobros, Fixs, Bitácora y Eventos.
- **Bitácora:** campo de alta rápida arriba y notas en orden inverso, con fecha. Se pueden editar y borrar.
- **Cobros:** pestañas Pendientes, Vencidos, Pagados y Todos. Tienen acción rápida "Marcar pagado" y el adjunto se sube desde el formulario.
- **Gastos:** una sección de Suscripciones, con la próxima fecha, alerta si faltan 7 días o menos y el botón "Registrar pago", y una sección de Gastos con el historial filtrable.
- **Assets:** llevan `?v=N`, igual que en la landing. La landing no carga ningún archivo del panel.

## 9. Migraciones

- `db/migrations/NNN_nombre.sql` se aplica en orden.
- La lógica vive en `admin/includes/migrator.php`: lee la tabla `migraciones` y aplica cada archivo pendiente dentro de una transacción donde sea posible. Se usa de dos formas:
  - por CLI, con `php db/migrate.php`;
  - por navegador, desde `/admin/migraciones`, con sesión admin y un botón POST protegido por CSRF.

  `/db/` queda bloqueado al público.
- **Primer deploy:** la tabla `migraciones` todavía no existe, así que la migración 001 se importa en phpMyAdmin y registra su propio nombre.

## 10. Tests

- **Stack de tests:** PHPUnit 11 contra una base MariaDB de test (`DB_NAME` de `.env.testing`). Cada test corre dentro de una transacción que se revierte al final.
- **Cobertura:**
  - **env y db:** carga del `.env` y conexión.
  - **auth:** verificación de contraseña, bloqueo al sexto intento, regeneración de sesión y validación de `next`.
  - **csrf:** token ausente o inválido → 403.
  - **Repositorio de cada entidad:** crear, leer, actualizar parcialmente, borrar y validaciones.
  - **Reglas:**
    - cálculo de vencido;
    - `fecha_pago` automática;
    - avance de suscripciones: 31/01 + 1 mes = 28 o 29/02, y 29/02 + 1 año = 28/02;
    - `fecha_resuelto` de los fixs;
    - reordenamiento de columnas;
    - `RESTRICT` al borrar clientes con cobros.
  - **Agregados:** dashboard, agenda y balance con datos armados, separados por moneda y por mes.
  - **Subidas:** rechazo por tipo y por tamaño.
- **Organización del código:** la lógica vive en funciones y repositorios dentro de `admin/includes/`. Los `api/*.php` solo parsean, validan y delegan, y así los tests no necesitan un servidor HTTP.
- **Smoke manual en XAMPP:**
  - URLs limpias;
  - bloqueos de `.htaccess`;
  - login y logout;
  - que `/` sea idéntica: se compara el HTML y los headers con `curl -I` antes y después del cambio.
- **En producción, después del deploy:** repetir la comparación de `/` con `curl -I` y revisar los 404 de `/.env` y `/db/`.

## 11. Deploy

- **Setup único en Hostinger:**
  1. Crear la base y el usuario MySQL en hPanel.
  2. Importar la migración 001 en phpMyAdmin.
  3. Crear `../.env` con el Administrador de archivos.
  4. Crear `../vezza_uploads/comprobantes/`.
  5. Verificar que PHP sea 8.1 o superior.
- **Deploys siguientes:** se hace `git push` y, si hay migraciones nuevas, se aplican desde `/admin/migraciones`.
- `DEPLOY.md` suma una sección "Panel admin".
- **Rollback:** hacer revert del commit y push. La landing no depende del panel.

## 12. Fases de implementación

1. Base: `.gitignore`, env, db, auth, CSRF, layout, login y logout, migración 001, reglas de `.htaccess` y tests.
2. Clientes y bitácora.
3. Procesos, kanban y subtareas.
4. Cobros y comprobantes.
5. Suscripciones y gastos.
6. Dashboard.
7. Tareas personales.
8. Fixs.
9. Agenda y eventos.
10. Reportes.
11. `DEPLOY.md` y verificación final de la landing.
