# Portal de clientes VEZZA (`/clientes`) — Diseño

Fecha: 2026-09-30 · Estado: pendiente de revisión

## 1. Objetivo

Sumar a vezzadev.com un portal de autogestión para los clientes de VEZZA, en el mismo dominio, repo y deploy que la landing y el panel admin. Cada cliente entra con su cuenta y puede:

- **Gestionar suscripciones:** ver, contratar, cambiar o cancelar sus planes.
- **Monitorear sus servicios:** ver el estado de cada uno (Activo, Mantenimiento, Caído).
- **Abrir tickets:** reportar un problema o pedir un cambio. La categoría decide si en el admin entra como **Fix** o como **Tarea**.

Además, el admin se adapta para recibir y gestionar todo eso, y para que admin y cliente convivan de forma segura sin romper lo que ya funciona.

### Criterios de éxito

- Un cliente solo puede ver y tocar sus propios datos. Lo que no le pertenece responde 404, en cada endpoint.
- Una sesión de cliente nunca da acceso al admin, y una de admin no sirve en el portal.
- La landing y el panel admin actuales se comportan igual que antes. Los tests existentes siguen pasando sin modificarse, salvo los que cubren el refactor de sesión (sección 4.3), que conservan su intención.
- Sin secretos en el repo: todo va en `.env`.
- Todas las pantallas se usan en un celular de 360 px de ancho.

### Fuera de alcance (versión 1)

- **Cobro automático con MercadoPago.** Es la segunda etapa declarada. Este diseño no lo construye, pero separa aprobación, estado y cobro para poder enchufarlo después sin rehacer el modelo.
- Hilo de mensajes dentro de los tickets. El cliente reporta y sigue el estado.
- Adjuntos en tickets.
- Auto-registro de clientes.
- Varios usuarios por cliente.
- Pantalla para editar categorías de tickets (viven en una tabla con datos iniciales).
- Generación automática de cobros a partir de las suscripciones. El cobro sigue manual en el módulo de cobros.

## 2. Contexto actual (inspección)

- El admin es PHP plano + MySQL, sin frameworks ni build. Patrón: `admin/includes/repos/*.php` (lógica), `api/*.php` (endpoints con `api_resource` y `api_run`), `admin/*.php` + `admin/assets/*.js` (pantallas), migraciones SQL en `db/migrations/` aplicadas desde `/admin/migraciones`.
- **No existen usuarios.** El login del admin compara contra `ADMIN_USERNAME` y `ADMIN_PASSWORD_HASH` del `.env`. La sesión guarda `$_SESSION['admin'] = true` (cookie `vezza_admin`, 7 días). `auth_logged()` lo chequea y `require_admin()` y `require_admin_api()` protegen páginas y API.
- `clientes` son fichas sin login. `fixs` ya existe, atado a cliente y proceso, con su tablero.
- La tabla `suscripciones` actual son **los gastos recurrentes propios** (hosting, herramientas). No se toca ni se renombra. Las suscripciones de clientes usan otras tablas.
- `tareas_personales` no tiene cliente. No sirve como destino de los tickets de tipo Tarea.
- `login_intentos` limita intentos por IP (5 en 15 min).
- El `.htaccess` raíz bloquea `/admin/includes`, `/db`, `/scripts`, `/tests`, `/vendor` y `/docs`, y reescribe las rutas de `/admin` y `/api`.
- Hostinger plan Business, deploy por Git a `public_html`. Cada push a `main` publica.

## 3. Decisiones tomadas

| Tema | Decisión |
|---|---|
| Suscripciones | Catálogo de planes administrado por el admin. El cliente **solicita** alta, cambio o baja, y el admin **aprueba**. Sin pasarela de pago ahora. |
| Monitoreo | Ping HTTP automático **más** override manual (Mantenimiento, o forzar cualquier estado). Manda el override si existe. |
| Acceso | Invitación del admin. Login con email y contraseña. Nadie se registra solo. |
| Categorías de tickets | Fijas, con mapeo a Fix o Tarea. El admin puede reclasificar. |
| Modelo | Plan y servicio son entidades distintas. |
| Tickets | El ticket es una carcasa con lo que envió el cliente. El trabajo y su estado viven en `fixs` (existente) y `tareas_cliente` (nueva). Sin estado duplicado. |
| Contraseña | **Mínimo 6 caracteres.** |

## 4. Roles, sesiones y aislamiento

### 4.1 Admin sin cambios funcionales

Sigue con login por `.env`, cookie `vezza_admin`, `require_admin()` y `require_admin_api()`. No se migra ninguna cuenta a la base.

### 4.2 Cliente: mundo paralelo

- Tabla `usuarios_cliente` (sección 5). Un usuario pertenece a **un** cliente.
- Cookie propia `vezza_cliente`, `SameSite=Strict`, `HttpOnly`, `Secure` en producción, 7 días.
- Middleware nuevo en `admin/includes/auth_cliente.php`: `require_cliente()` (páginas, redirige a `/clientes/login`) y `require_cliente_api()` (responde 401). Ambos devuelven el `cliente_id` de la sesión.
- Una sesión de cliente no cumple `auth_logged()` del admin porque vive en otro namespace de sesión, y al revés. `/api/*` con cookie de cliente responde 401.
- No se agrega columna `rol`: hoy el rol es el namespace con el que se autenticó. Los guards quedan con la misma forma para sumar roles después.

### 4.3 Refactor de sesión (mínimo)

Se extrae de `session_boot()` una función interna que recibe el nombre de la cookie y el flag de sesión (`admin` o `cliente`). `session_boot()` la usa con sus valores actuales, sin cambiar su comportamiento. El portal usa la misma con `vezza_cliente`. Cada punto de entrada arranca **solo una** de las dos. `SesionesTest` y `AuthTest` cubren que el admin no cambió.

### 4.4 Regla de aislamiento entre clientes

- Todo el código del portal recibe `$clienteId` **de la sesión, nunca del request**.
- Los IDs que vienen del request (servicio, suscripción, plan solicitado, ticket) se verifican contra ese cliente. Si no le pertenecen: **404**, no 403, para no permitir enumeración.
- Los repos del portal van en `admin/includes/repos/portal_*.php` y **no reutilizan** los `*_list` ni `*_get` del admin, que no filtran por cliente.

### 4.5 Login, invitación y recuperación

- Login con email y contraseña. Contraseña **de al menos 6 caracteres**, guardada con `password_hash`. La misma regla rige al activar la cuenta y al recuperarla.
- Límite de intentos reutilizando `login_intentos`, con la columna nueva `ambito` (`admin` o `cliente`) para que los contadores no se mezclen. CSRF reutilizado.
- Con solo 6 caracteres mínimos, el límite de intentos es la defensa principal contra fuerza bruta. Se mantiene: 5 intentos por IP en 15 minutos, y además **5 fallos por cuenta (email) en 15 minutos** dentro del ámbito `cliente`.
- **Invitación:** desde la ficha del cliente el admin genera un link con token aleatorio de 32 bytes. Se guarda solo su hash (sha256), vence a las 72 h y es de un solo uso. El link lleva a `/clientes/activar?token=…`, donde el cliente elige su contraseña.
- **Recuperación:** mismo mecanismo de token. `/clientes/recuperar` responde igual exista o no el email, para no revelar qué cuentas existen.
- Generar una invitación nueva invalida la anterior. Desactivar al usuario (`activo = 0`) corta su acceso, y una sesión abierta deja de valer en el siguiente request.

## 5. Modelo de datos (migración `db/migrations/002_portal.sql`)

Todo es aditivo. No se renombra ni borra nada existente. Convenciones de `001_inicial.sql`: InnoDB, `utf8mb4_unicode_ci`, `created_at` y `updated_at`.

### Acceso
- **`usuarios_cliente`**: `id`, `cliente_id` (FK `clientes`, ON DELETE CASCADE), `email` (único), `password_hash` (nulo hasta activar), `activo`, `token_hash`, `token_expira`, `token_tipo` (`invitacion` o `recuperacion`), `ultimo_login`.
- **`login_intentos`**: `ALTER ADD ambito VARCHAR(10) NOT NULL DEFAULT 'admin'`, y columna `clave VARCHAR(255) NULL` para el email en el ámbito cliente.

### Clientes → Planes → Suscripciones
- **`planes`**: `id`, `nombre`, `descripcion`, `monto`, `moneda`, `frecuencia` (`mensual` o `anual`), `activo`, `orden`.
- **`suscripciones_cliente`**: `id`, `cliente_id` (FK), `plan_id` (FK, RESTRICT), `estado` (`activa` o `cancelada`), `fecha_inicio`, `fecha_proximo_cobro`, `fecha_baja`.
- **`solicitudes_suscripcion`**: `id`, `cliente_id` (FK), `suscripcion_id` (nulo para altas), `tipo` (`alta`, `cambio`, `baja`), `plan_id` (plan destino; nulo en bajas), `estado` (`pendiente`, `aprobada`, `rechazada`, `retirada`), `nota_cliente`, `nota_admin`, `resuelta_en`.
  - **Aprobar** corre en una transacción: alta crea la suscripción, cambio actualiza `plan_id`, baja marca `cancelada` y `fecha_baja`.
  - Un cliente no puede tener dos solicitudes pendientes sobre la misma suscripción.
  - Las solicitudes no se borran: quedan como historial.

### Servicios y estado
- **`servicios`**: `id`, `cliente_id` (FK), `suscripcion_id` (nulo, ON DELETE SET NULL), `nombre`, `url`, `chequear` (0 o 1), `estado_auto` (`activo`, `caido`, `desconocido`), `fallos_consecutivos`, `estado_manual` (`activo`, `mantenimiento`, `caido`; nulo = sin override), `estado_manual_hasta` (nulo = sin vencimiento), `mensaje`, `ultimo_chequeo`.
- **Estado efectivo:** si `estado_manual` no es nulo y (`estado_manual_hasta` es nulo o futuro), manda ese. Si no, manda `estado_auto`. Una única función lo calcula y la comparten portal y admin.
- **`servicio_eventos`**: `id`, `servicio_id` (FK CASCADE), `estado`, `origen` (`auto` o `manual`), `mensaje`, `creado_en`. Se inserta **solo cuando cambia** el estado efectivo. Es la línea de tiempo que ve el cliente. Sin un registro por chequeo.

### Tickets
- **`categorias_ticket`**: `id`, `nombre`, `tipo` (`fix` o `tarea`), `prioridad_default`, `orden`, `activa`. Datos iniciales:

  | Categoría | Tipo | Prioridad |
  |---|---|---|
  | Algo no funciona o está caído | fix | alta |
  | Error visual o de datos | fix | media |
  | Cambio de contenido | tarea | baja |
  | Nueva funcionalidad | tarea | media |
  | Mejora de algo existente | tarea | media |
  | Consulta | tarea | baja |

- **`tickets`**: `id`, `cliente_id` (FK), `usuario_id` (FK `usuarios_cliente`, SET NULL), `servicio_id` (nulo, SET NULL), `categoria_id` (FK), `tipo` (`fix` o `tarea`; copia al crear, cambia al reclasificar), `asunto`, `descripcion`.
- **`tareas_cliente`** (nueva): `id`, `cliente_id` (FK), `ticket_id` (único, FK SET NULL), `proceso_id` (nulo), `titulo`, `descripcion`, `estado` (`pendiente`, `en_curso`, `hecha`), `prioridad`, `fecha_vencimiento`, `orden`. Misma forma que `fixs` para reutilizar el patrón del tablero.
- **`fixs`**: `ALTER ADD ticket_id INT UNSIGNED NULL UNIQUE` con FK ON DELETE SET NULL. Las filas existentes no cambian.
- **Estado visible al cliente**, derivado por join sin duplicar:

  | Trabajo | Estado del cliente |
  |---|---|
  | fix `reportado` o tarea `pendiente` | Recibido |
  | fix `en_progreso` o tarea `en_curso` | En curso |
  | fix `resuelto` o tarea `hecha` | Resuelto |

- **Crear ticket:** en una transacción inserta el ticket y su fila de trabajo según el `tipo` de la categoría (`fixs` con `fecha_reportado = hoy`, o `tareas_cliente`), con la prioridad por defecto de la categoría.
- **Reclasificar (solo admin):** en una transacción mueve el trabajo a la otra tabla, conserva título, descripción y prioridad, **reinicia el estado** y actualiza `tickets.tipo`.

## 6. Superficie del portal

Archivos en la carpeta nueva `clientes/` (páginas PHP) y `clientes/assets/` (CSS y JS), con reglas nuevas en el `.htaccess` raíz, agregadas al final como se hizo con el admin. Tokens de marca: fondo `#F5F3EE`, tinta `#15131C`, índigo `#3D2FE0`, Manrope y Sora. Es un tema claro, sin modo oscuro. Voz: rioplatense con voseo.

### Pantallas
- `/clientes/login`, `/clientes/activar?token=`, `/clientes/recuperar`, `/clientes/logout`.
- **Estado** (`/clientes`): una tarjeta por servicio con insignia *Activo*, *Mantenimiento* o *Caído*, el mensaje si hay, la hora de la última verificación y la línea de tiempo de los últimos eventos. Se refresca cada 30 s y se pausa con la pestaña oculta.
- **Suscripciones** (`/clientes/suscripciones`): planes activos con próximo cobro y botones *Cambiar* y *Cancelar* (la baja pide confirmación). Catálogo para *Contratar*. Solicitudes pendientes, que se pueden retirar.
- **Tickets** (`/clientes/tickets`): lista con estado y detalle de solo lectura. Formulario con servicio (opcional), categoría, asunto y descripción. Límite de **5 tickets por hora por cliente**.

### API (`/clientes/api/*`)
Separada de `/api/*`. Todo pasa por `require_cliente_api()` y por la regla de aislamiento (4.4). Mismas convenciones de JSON y errores que la API del admin, y CSRF en métodos no GET. Recursos: `servicios`, `suscripciones`, `planes`, `solicitudes`, `tickets`, `categorias`.

## 7. Cambios en el admin

Nuevas pantallas en el menú actual, con el mismo layout, CSS y helpers JS:

- **Ficha del cliente:** bloque *Acceso al portal* (invitar, reenviar, desactivar, mostrar el link para copiar), más sus suscripciones y servicios.
- **`/admin/planes`:** CRUD del catálogo.
- **`/admin/solicitudes`:** aprobar o rechazar con nota, con contador de pendientes.
- **`/admin/servicios`:** CRUD, override manual (estado, mensaje y vencimiento opcional) y eventos.
- **`/admin/tickets`:** bandeja con pestañas **Fixes | Tareas**, filtros por cliente, servicio y estado, y acción de reclasificar.
- **`/admin/tareas-clientes`:** tablero de las tareas de clientes, con el mismo estilo que el de Fixs. El nombre evita chocar con `/admin/tareas` (tareas personales).
- **Tablero de Fixs:** los fixs con `ticket_id` llevan la marca **Portal** y un link al ticket.
- **Dashboard:** dos contadores nuevos (solicitudes pendientes y tickets nuevos).
- Las rutas nuevas se agregan al `.htaccess` con el patrón actual, y se sube `PANEL_ASSET_V`.

## 8. Infraestructura

### Mails (invitación y recuperación)
- Salen por un **webhook de n8n**, como el formulario de la landing, con la credencial SMTP de `info@vezzadev.com`. No se instalan librerías en Hostinger.
- El webhook va protegido con un secreto compartido. Variables nuevas del `.env`: `MAIL_WEBHOOK_URL`, `MAIL_WEBHOOK_SECRET`, `PORTAL_URL`.
- **Respaldo:** el link de invitación siempre se muestra en el admin para copiarlo y enviarlo por otro canal. Si n8n falla, el envío no se bloquea.

### Chequeo automático de servicios
- `scripts/chequear-servicios.php`, por CLI, desde Cron Jobs de hPanel cada 5 minutos. `/scripts` ya está bloqueado por web.
- **Verificar al empezar la fase 2 que el plan de Hostinger ofrezca cron.** Si no, el respaldo es un Schedule Trigger de n8n que llama a un endpoint protegido con token (`CRON_TOKEN` en el `.env`).
- **Anti-flapping:** un servicio pasa a `caido` recién con 2 fallos seguidos (`fallos_consecutivos`).
- **Seguridad de las URLs (SSRF):** solo las carga el admin. Igual se valida http o https, timeout de 8 s, máximo 3 redirecciones y se rechazan IPs privadas o reservadas, resolviendo el host antes de pedirlo.
- Un override manual con `estado_manual_hasta` vencido deja de aplicar solo.

### Deploy
- Sigue siendo `git push` a `main`. Las migraciones se aplican desde `/admin/migraciones`. `002` es aditiva y segura de aplicar con datos existentes.

## 9. Seguridad

- Contraseña mínima de 6 caracteres (decisión explícita), con `password_hash`. Los tokens se guardan hasheados.
- Límite de intentos por IP y por cuenta (4.5). Bloqueo con el mismo criterio del admin.
- `recuperar` no revela si el email existe.
- CSRF en toda mutación. Headers anti-caché (`send_panel_headers`) también en el portal y su API.
- Todo el JS del portal renderiza datos con `textContent`, sin `innerHTML` para contenido del usuario.
- Errores internos responden un 500 genérico y se loguean, como en el admin.
- Aislamiento por cliente con 404 uniforme (4.4).

## 10. Pruebas

PHPUnit sobre `DbTestCase`, como las actuales. Cada fase agrega sus tests antes del código (TDD).

- **Aislamiento:** el cliente A no lee ni modifica datos del B en cada endpoint y recibe 404.
- Las sesiones de admin y de cliente no se cruzan (en ambos sentidos).
- Invitación y recuperación: token de un solo uso, con vencimiento, y la invitación nueva invalida la anterior.
- Contraseña: rechaza menos de 6 caracteres, acepta 6.
- Límite de intentos por IP y por cuenta.
- Aprobar solicitudes es transaccional (alta, cambio y baja) y no admite dos pendientes sobre la misma suscripción.
- Estado efectivo: override vigente, vencido y ausente.
- Chequeo de servicios con un fetcher inyectado (anti-flapping, eventos solo en cambios, rechazo de IPs privadas).
- Crear ticket genera el trabajo correcto según la categoría. Reclasificar mueve entre tablas y reinicia el estado.
- Regresión: los tests actuales del admin pasan sin cambios.
- `scripts/verificar-portal.sh` revisa rutas y bloqueos, como `verificar-panel.sh`.

## 11. Fases

Cada una se puede desplegar sola.

1. **Fundaciones:** migración 002 (completa), refactor de sesión, login de cliente, invitación y recuperación, y bloque de acceso en la ficha del admin.
2. **Servicios y estado:** panel de estado del cliente, admin de servicios y cron.
3. **Tickets:** formulario, bandeja, tablero de tareas-clientes y reclasificación.
4. **Suscripciones:** planes, solicitudes y aprobación.

Sobre cada fase: la migración 002 se escribe entera en la fase 1 para tener un solo paso de esquema, y las fases siguientes agregan código sobre tablas ya creadas.

## 12. Después de la versión 1

- **MercadoPago:** suscripciones recurrentes con webhooks. El modelo ya separa la solicitud, el estado de la suscripción y el cobro, así que se suma como migración y módulo nuevos.
- Hilo de mensajes y adjuntos en tickets.
- Pantalla de edición de categorías.
