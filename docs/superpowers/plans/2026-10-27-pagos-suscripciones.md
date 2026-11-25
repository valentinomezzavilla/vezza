# Cobro de suscripciones mensuales con Mercado Pago — Plan de implementación

> Reemplazado en lo que respecta al cobro por `2026-11-25-pagos-estilo-vezzashop-design.md` (spec) y `2026-11-25-pagos-estilo-vezzashop.md` (plan).

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que cada cliente pague su suscripción mensual por débito automático o link de Mercado Pago (o por transferencia, como hoy), que el sistema valide solo cada pago, avise por mail y WhatsApp cuando no pagó sin pausar nada, y que se vea en la pestaña "Suscripciones mensuales" de Cobros y en la pantalla Pagos del portal.

**Architecture:** Una migración aditiva (`003_pagos`) agrega modalidad, estado de Mercado Pago y los vínculos suscripción→cobro. Un cron diario por CLI genera los cobros del mes (uno por suscripción y período), reconcilia pagos y manda avisos; todo lo que toca la red (Mercado Pago, mail, WhatsApp) pasa por costuras inyectables como `Mail::$transporte`, así los tests no usan la red. El webhook de Mercado Pago verifica firma y consulta la API antes de marcar un cobro como pagado. n8n solo entrega mails y WhatsApp; la lógica de cuándo avisar vive en PHP.

**Tech Stack:** PHP 8.1 plano, MySQL/MariaDB, JS puro, PHPUnit 10.5, API HTTP de Mercado Pago (sin SDK), n8n. Sin build. Deploy por `git push` a `main` (Hostinger).

**Spec:** `docs/superpowers/specs/2026-10-27-pagos-suscripciones-design.md` (aprobado). Referencia visual: `.superpowers/brainstorm/*/content/pagos-estilos-v2.html`.

## Global Constraints

Valores copiados del spec. Aplican a todas las tareas.

- PHP 8.1 (`composer.json` fija `platform.php = 8.1.0`): nada de sintaxis ni funciones de 8.2+. Sin frameworks ni SDK de Mercado Pago, salvo que se justifique.
- Migración nueva `db/migrations/003_pagos.sql`, aditiva, InnoDB, `utf8mb4_unicode_ci`, `created_at`/`updated_at`, registrada con `INSERT IGNORE INTO migraciones`.
- Sin secretos en el repo. Variables nuevas en `.env.example` y `DEPLOY.md`: `MP_ACCESS_TOKEN`, `MP_WEBHOOK_SECRET`, `WHATSAPP_WEBHOOK_URL` (vacía = canal apagado).
- Solo pesos argentinos (`ARS`) para `debito` y `link`; una suscripción en otra moneda solo puede ser `transferencia`.
- **Nunca se pausa un servicio por falta de pago.** Solo avisos y pagos pendientes visibles.
- Un cobro por suscripción y período (`YYYY-MM`), garantizado por clave única. Un cobro solo pasa a `pagado` tras verificarlo contra la API de Mercado Pago, con monto y moneda coincidentes.
- Un aviso (cobro, tipo, canal, número) no se manda dos veces.
- Tiempos de aviso: 3 días antes del vencimiento, el día del vencimiento, y cada 3 días mientras esté vencido. El cobro se genera cuando faltan 5 días o menos para el vencimiento.
- `fecha_proximo_cobro` avanza un mes **al generar** el cobro, no al pagarlo; un pago atrasado no la mueve.
- Aislamiento por cliente: el `cliente_id` sale de la sesión, nunca del request; un id ajeno responde 404. El portal tiene sus propios repos (`portal_*`) y no reutiliza los `*_list`/`*_get` del admin. `/api/*` con cookie de cliente responde 401.
- El JS del portal usa `textContent` o el helper `el`, nunca `innerHTML` con datos del usuario. CSRF en métodos no GET.
- Estilo: solo tokens de `admin/assets/admin.css`; clases existentes (`.item`, `.badge-*`, `.stats`, `.tabs`, `.btn*`); estilos nuevos únicamente `.aviso-pago` (`-warn`/`-danger`), `.contador`, `.monto` y el ícono dentro de insignias. El estado nunca va solo en color. Todo usable a 360 px; controles táctiles de 44 px. Subir `PANEL_ASSET_V` y `PORTAL_ASSET_V` al tocar CSS/JS.
- Voz: voseo, primera persona singular. Montos `$85.000`, fechas cortas ("1 nov").
- Los tests existentes del admin y del portal siguen pasando sin modificarse.
- Commits en español, estilo del repo ("Agregar ...", "Hacer que ..."), cada uno con el trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` como segundo `-m`.

## Review Focus

Entradas y condiciones que el spec implica y que podrían romper el sistema. Cada línea tiene su test en la tarea dueña del código.

1. **Aviso de Mercado Pago repetido, fuera de orden o sin firma válida:** el cobro se paga una sola vez, la confirmación sale una sola vez y una firma mala no toca nada. → Task 5.
2. **Pago aprobado de un monto o moneda distintos del cobro (o de un cobro ajeno):** no marca pagado; queda registrado como "sin cobro asignado". → Task 5.
3. **Cron corrido dos veces el mismo día, y vencimientos del 29, 30 o 31:** no duplica cobros ni avisos, y el mes siguiente cae en el último día válido. → Task 4 y Task 7.
4. **Un cliente pidiendo cobros, links o suscripciones de otro, o viendo ids internos de Mercado Pago:** 404 y la respuesta no trae `mp_*`. → Task 13.
5. **Mercado Pago, n8n o el mail caídos, o un cliente sin mail ni teléfono:** el cron sigue con los demás, el cobro se crea igual y el aviso se reintenta en la próxima corrida. → Task 4, Task 6 y Task 7.

## Estructura de archivos

**Crear**
- `db/migrations/003_pagos.sql`
- `admin/includes/mercadopago.php`: cliente HTTP de Mercado Pago con costura de transporte y verificación de firma.
- `admin/includes/whatsapp.php`: envío por webhook de n8n y normalización de teléfonos.
- `admin/includes/repos/planes.php`, `suscripciones_cliente.php`, `suscripciones_ciclo.php`, `cobros_suscripciones.php`, `pagos_mp.php`, `avisos_pago.php`, `suscripciones_mensuales.php`, `portal_pagos.php`
- `admin/includes/portal_api.php` (solo si la fase 2 todavía no lo creó)
- `api/planes.php`, `api/suscripciones-cliente.php`, `api/mp-webhook.php`
- `clientes/pagos.php`, `clientes/api/pagos.php`
- `admin/planes.php`, `admin/assets/planes.js`, `admin/assets/mod-suscripciones.js`
- `scripts/cobros-diario.php`
- `docs/n8n/vezza-avisos-de-pago.json`
- Tests: `MigracionPagosTest`, `PlanesTest`, `SuscripcionesClienteTest`, `MercadoPagoTest`, `CobrosSuscripcionesTest`, `PagosMpTest`, `AvisosPagoTest`, `SuscripcionesCicloTest`, `SuscripcionesMensualesTest`, `PortalPagosTest`

**Modificar**
- `admin/includes/repos/cobros.php`: invalidar el link al marcar pagado a mano (Task 8).
- `admin/cobros.php`, `admin/assets/cobros.js`: pestaña nueva.
- `clientes/index.php`, `admin/includes/portal_layout.php`, `clientes/assets/portal.js`, `clientes/assets/portal.css`: navegación mínima, cartel y pantalla Pagos.
- `admin/includes/layout.php`: `PANEL_ASSET_V`. `.htaccess`, `.env.example`, `DEPLOY.md`.

## Prerrequisitos de entorno (una sola vez)

- MariaDB de XAMPP prendida: `cd /c/xampp/mysql/bin && ./mysqld.exe --defaults-file=my.ini --console &`. Sin eso PHPUnit falla en el bootstrap.
- Línea base: `php vendor/bin/phpunit` da `OK`; anotar la cantidad de tests. Correr siempre desde la raíz.
- Rama: `git switch main && git pull && git switch -c pagos-suscripciones`.
- Antes de la Task 3, abrir la documentación vigente de Mercado Pago (Checkout Pro, Suscripciones/preapproval y Notificaciones/webhooks) y credenciales de prueba. Para el cierre del proyecto hacen falta además las credenciales reales y la URL del webhook cargada en el panel de Mercado Pago.

---

### Task 1: Migración `003_pagos`

**Files:**
- Create: `db/migrations/003_pagos.sql`
- Create: `tests/MigracionPagosTest.php` (mismo estilo que `MigracionPortalTest.php`)

**Interfaces:**
- Produces (las demás tareas dependen de estos nombres exactos):
  - `suscripciones_cliente` + `monto DECIMAL(12,2)`, `moneda CHAR(3)`, `modalidad ENUM('debito','link','transferencia') DEFAULT 'transferencia'`, `mp_preapproval_id VARCHAR(100) NULL UNIQUE`, `mp_preapproval_estado ENUM('pendiente','autorizada','pausada','cancelada') NULL`, `mp_init_point VARCHAR(500) NULL` (para reenviar el link de alta), `pausada TINYINT(1) DEFAULT 0`.
  - `cobros` + `suscripcion_cliente_id INT UNSIGNED NULL` (FK, `ON DELETE SET NULL`), `periodo CHAR(7) NULL`, `mp_payment_id VARCHAR(100) NULL UNIQUE`, `mp_preference_id VARCHAR(100) NULL`, `link_pago VARCHAR(500) NULL`; clave única `(suscripcion_cliente_id, periodo)`.
  - `mp_notificaciones(id, tipo VARCHAR(60), recurso_id VARCHAR(100), payload TEXT, recibida_en, procesada_en NULL, sin_cobro TINYINT(1) DEFAULT 0, error VARCHAR(255) NULL)`.
  - `avisos_pago(id, cobro_id FK CASCADE, tipo ENUM('previo','vencimiento','vencido','confirmacion','rechazo'), canal ENUM('mail','whatsapp'), numero INT DEFAULT 0, enviado_en, ok TINYINT(1))`, única `(cobro_id, tipo, canal, numero)`. *(`rechazo` agrega el aviso de débito rechazado que el spec nombra como plantilla.)*
  - `suscripciones_precio_historial(id, suscripcion_cliente_id FK CASCADE, monto_anterior, monto_nuevo, moneda, creado_en, avisado_en NULL)`.

- [ ] **Step 1: Crear la rama y confirmar la línea base** (ver prerrequisitos). Commitear antes el diseño y este plan: `git add docs/superpowers && git commit -m "Agregar el diseño y el plan del cobro de suscripciones" -m "<trailer>"`.
- [ ] **Step 2: Escribir los tests que fallan.** Tests: `test_las_columnas_nuevas_existen` (consulta `information_schema` por cada columna del bloque Interfaces); `test_no_se_puede_repetir_un_periodo_por_suscripcion` (dos `cobros` con el mismo `(suscripcion_cliente_id, periodo)` → `PDOException`, pero dos con `suscripcion_cliente_id NULL` y `periodo NULL` sí entran); `test_no_se_repite_un_aviso` (misma clave única de `avisos_pago` → excepción); `test_las_suscripciones_existentes_copian_monto_y_moneda_del_plan` (con la migración ya aplicada, simular el backfill ejecutando el `UPDATE` de la migración sobre una fila nueva y comprobar `monto`/`moneda` = los del plan y `modalidad = 'transferencia'`); `test_borrar_una_suscripcion_deja_el_cobro` (el cobro queda con `suscripcion_cliente_id NULL`).
- [ ] **Step 3: Correr y ver fallar.** Run: `php vendor/bin/phpunit tests/MigracionPagosTest.php`. Expected: FAIL (columnas inexistentes).
- [ ] **Step 4: Escribir `003_pagos.sql`.** Agregar `monto`/`moneda` con `DEFAULT 0`/`'ARS'`, hacer `UPDATE suscripciones_cliente sc JOIN planes p ON p.id = sc.plan_id SET sc.monto = p.monto, sc.moneda = p.moneda`, y terminar con `INSERT IGNORE INTO migraciones (nombre) VALUES ('003_pagos');`.
- [ ] **Step 5: Correr los tests y la suite completa.** Run: `php vendor/bin/phpunit`. Expected: `OK`, con los tests anteriores intactos (el bootstrap de tests recrea la base aplicando todas las migraciones).
- [ ] **Step 6: Commit** `Agregar la migración de pagos y suscripciones`.

---

### Task 2: Planes y suscripciones de cliente (repo del admin y API)

**Files:**
- Create: `admin/includes/repos/planes.php`, `admin/includes/repos/suscripciones_cliente.php`
- Create: `api/planes.php`, `api/suscripciones-cliente.php` (solo `api_resource`; las acciones de ciclo se agregan en la Task 8)
- Test: `tests/PlanesTest.php`, `tests/SuscripcionesClienteTest.php`

**Interfaces:**
- Consumes: `crud_*`, `q_*`, `tx`, `validate`, `filtros`, `where_eq`, `HttpError` (`crud.php`, `validate.php`, `http.php`); `sumar_periodo(string $fecha, string $frecuencia): string` (`repos/suscripciones.php`).
- Produces:
  - `const MODALIDADES = ['debito', 'link', 'transferencia']`, `const MONEDA_MP = 'ARS'`
  - `planes_list(array $get): array`, `planes_get(int $id): array`, `planes_create(array $input): array`, `planes_update(int $id, array $input): array`, `planes_delete(int $id): void` (409 si hay suscripciones que lo usan).
  - `suscripciones_cliente_list(array $get): array` (filtros `cliente_id`, `estado`), `suscripciones_cliente_get(int $id): array`, `suscripciones_cliente_create(array $input): array`, `suscripciones_cliente_update(int $id, array $input): array`, `suscripciones_cliente_delete(int $id): void`. Cada fila trae `cliente_nombre`, `plan_nombre`, `monto`, `moneda`, `modalidad`, `mp_preapproval_estado`, `pausada`, `fecha_proximo_cobro`.

- [ ] **Step 1: Escribir los tests de planes que fallan.** `PlanesTest`: crear con `nombre`, `monto`, `moneda`, `frecuencia`; `monto` vacío o `moneda` inválida → 422; listar ordena por `orden`; `planes_delete` de un plan con suscripciones → `assertHttp(409, ...)`; 404 en `get`/`update` de uno inexistente.
- [ ] **Step 2: Escribir los tests de suscripciones que fallan.** `SuscripcionesClienteTest`:
  - `test_crear_copia_monto_y_moneda_del_plan_y_arranca_en_transferencia`;
  - `test_se_puede_dar_un_monto_propio_al_crear` (el `monto` del input pisa al del plan);
  - `test_debito_y_link_exigen_moneda_ars` (plan en USD + `modalidad = 'link'` → 422 en `modalidad`; con `transferencia` entra);
  - `test_debito_y_link_exigen_que_el_cliente_tenga_mail` (cliente sin `email` → 422 en `modalidad`);
  - `test_fecha_proximo_cobro_es_obligatoria_para_suscripciones_activas`;
  - `test_listar_filtra_por_cliente`;
  - `test_borrar_una_suscripcion_deja_sus_cobros`.
- [ ] **Step 3: Correr y ver fallar.** Run: `php vendor/bin/phpunit tests/PlanesTest.php tests/SuscripcionesClienteTest.php`. Expected: FAIL con `Call to undefined function planes_create()`.
- [ ] **Step 4: Implementar los repos** con la misma forma que `repos/cobros.php` (`*_schema()`, normalización, `tx` donde corresponda). `suscripciones_cliente_update` no permite cambiar `cliente_id`; cambios de `modalidad`, pausa y cancelación **no** pasan por acá (los hace la Task 8): el schema de update ignora `modalidad`, `pausada`, `estado` y `mp_*`.
- [ ] **Step 5: Crear los endpoints** con `api_resource` como `api/cobros.php`. Las rutas genéricas `api/([a-z-]+)` del `.htaccess` ya los cubren.
- [ ] **Step 6: Correr los tests y la suite.** Expected: `OK`.
- [ ] **Step 7: Commit** `Agregar planes y suscripciones de cliente al admin`.

---

### Task 3: Cliente de Mercado Pago y verificación de firma

**Files:**
- Create: `admin/includes/mercadopago.php`
- Test: `tests/MercadoPagoTest.php`

**Interfaces:**
- Consumes: `env()`, `portal_url()` (`mail.php`).
- Produces (sin red en los tests: `MercadoPago::$transporte` es `?callable(string $metodo, string $ruta, ?array $cuerpo): array`):
  - `final class MercadoPago { public static $transporte = null; }`
  - `mp_request(string $metodo, string $ruta, ?array $cuerpo = null): array`: usa el transporte si existe; si no, HTTP real a `https://api.mercadopago.com` con `Authorization: Bearer MP_ACCESS_TOKEN`, timeout 25 s; lanza `RuntimeException` ante red caída, token ausente o respuesta no 2xx.
  - `mp_crear_preferencia(array $cobro, string $emailCliente): array{id: string, init_point: string}`
  - `mp_obtener_pago(string $id): array`
  - `mp_crear_preapproval(array $suscripcion, string $emailCliente, string $inicio): array{id: string, init_point: string}`
  - `mp_obtener_preapproval(string $id): array`
  - `mp_actualizar_preapproval(string $id, array $cambios): array`; `mp_pausar_preapproval(string $id): void`, `mp_reanudar_preapproval(string $id): void`, `mp_cancelar_preapproval(string $id): void`
  - `mp_buscar_pagos(string $referenciaExterna): array` (lista de pagos de una referencia, para la reconciliación)
  - `mp_firma_valida(array $headers, string $dataId, string $secreto): bool`

- [ ] **Step 1: Confirmar contra la documentación vigente de Mercado Pago** los endpoints, los campos y el formato de firma de los siete métodos de arriba (preferencias de Checkout Pro, `preapproval`, búsqueda de pagos, notificaciones). Anotar en el docblock de cada función los nombres de campo confirmados. Si algo difiere de lo que sigue, mandan los docs.
- [ ] **Step 2: Escribir los tests que fallan** (con un transporte falso que registra `(metodo, ruta, cuerpo)` y devuelve una respuesta fija):
  - `test_la_preferencia_lleva_el_id_del_cobro_como_referencia_externa` (`external_reference === (string)$cobro['id']`, `currency_id === 'ARS'`, `unit_price` = monto del cobro, `notification_url === portal_url('/api/mp-webhook')`, `back_urls` hacia `portal_url('/clientes/pagos')`, mail del cliente en `payer`); devuelve `id` e `init_point`;
  - `test_el_preapproval_arranca_en_la_fecha_pedida_con_monto_y_moneda` (frecuencia 1 mes, `transaction_amount`, `currency_id ARS`, inicio = `$inicio`);
  - `test_actualizar_pausar_reanudar_y_cancelar_mandan_el_estado_correcto`;
  - `test_sin_token_lanza_runtime_exception` (transporte nulo y `MP_ACCESS_TOKEN` ausente);
  - `test_firma_valida` (se arma el manifiesto y el HMAC-SHA256 con `ts`, `x-request-id` y `data.id` según los docs, y se acepta); `test_firma_alterada_o_ausente_se_rechaza` (cambiar el `ts`, el `dataId`, el `v1`, o faltar el header → `false`); `test_la_firma_se_compara_en_tiempo_constante` (basta con que use `hash_equals`; se verifica leyendo el código en la revisión).
- [ ] **Step 3: Correr y ver fallar.** Run: `php vendor/bin/phpunit tests/MercadoPagoTest.php`. Expected: FAIL (funciones inexistentes).
- [ ] **Step 4: Implementar `admin/includes/mercadopago.php`.** El transporte real usa streams como `mail_post` (sin cURL obligatorio) y decodifica JSON con `JSON_THROW_ON_ERROR`.
- [ ] **Step 5: Correr los tests y la suite.** Expected: `OK`.
- [ ] **Step 6: Commit** `Agregar el cliente de Mercado Pago con verificación de firma`.

---

### Task 4: Generación de cobros mensuales

**Files:**
- Create: `admin/includes/repos/cobros_suscripciones.php`
- Test: `tests/CobrosSuscripcionesTest.php`

**Interfaces:**
- Consumes: `suscripciones_cliente_*` (Task 2), `sumar_periodo`, `mp_crear_preferencia` (Task 3), `crud_*`, `tx`, `hoy()`, `dias_entre()` (`clock.php`).
- Produces:
  - `const COBRO_ANTICIPACION_DIAS = 5`
  - `cobros_generar_pendientes(?string $hoy = null): array{creados: int, errores: int}`: por cada suscripción `estado = 'activa'` y `pausada = 0` cuyo `fecha_proximo_cobro` sea `<= hoy + 5 días` y no sea nula, crea el cobro del período (`periodo` = `YYYY-MM` del vencimiento, `concepto` = "{plan} · {mes} {año}", monto/moneda de la suscripción, `fecha_vencimiento = fecha_proximo_cobro`) y avanza `fecha_proximo_cobro` un mes en la misma transacción. Máximo 12 períodos por suscripción por corrida.
  - `cobros_completar_links(): array{completados: int, errores: int}`: crea el link a los cobros `pendiente` de modalidad `link` que no lo tienen todavía.

- [ ] **Step 1: Escribir los tests que fallan.**
  - `test_crea_el_cobro_cuando_faltan_5_dias_o_menos_y_no_antes` (vencimiento en hoy+5 crea; hoy+6 no);
  - `test_avanza_fecha_proximo_cobro_un_mes_al_generar`;
  - `test_correr_dos_veces_no_duplica` (mismo `creados` total 1 y un solo cobro);
  - `test_vencimientos_del_31_caen_en_el_ultimo_dia_del_mes_siguiente` (31 ene → 28 feb; 31 mar → 30 abr; sin saltarse el día en los meses siguientes);
  - `test_una_suscripcion_pausada_o_cancelada_no_genera`;
  - `test_si_no_se_paga_el_mes_siguiente_genera_su_propio_cobro` (con `hoy` avanzado un mes hay 2 cobros pendientes y la fecha sigue avanzando);
  - `test_modalidad_link_crea_el_link_con_la_pasarela_falsa` (`link_pago` e `mp_preference_id` guardados); `transferencia` y `debito` quedan sin link;
  - `test_si_la_pasarela_falla_el_cobro_se_crea_igual_y_el_link_se_reintenta` (`errores = 1` en la primera corrida, cobro creado con `link_pago NULL`, y `cobros_completar_links()` lo completa cuando la pasarela vuelve);
  - `test_el_cobro_hereda_cliente_monto_y_moneda_de_la_suscripcion` (un precio ajustado se refleja; no el del plan).
- [ ] **Step 2: Correr y ver fallar.** Run: `php vendor/bin/phpunit tests/CobrosSuscripcionesTest.php`. Expected: FAIL.
- [ ] **Step 3: Implementar `cobros_generar_pendientes` y `cobros_completar_links`.** Cada suscripción en su propia transacción: un error en una no frena a las demás (se loguea con `error_log('[cobros] ...')` y suma a `errores`). El link se pide **fuera** de la transacción del cobro.
- [ ] **Step 4: Correr los tests y la suite.** Expected: `OK`.
- [ ] **Step 5: Commit** `Generar el cobro mensual de cada suscripción`.

---

### Task 5: Webhook de Mercado Pago y registro de pagos

**Files:**
- Create: `admin/includes/repos/pagos_mp.php`, `api/mp-webhook.php`
- Test: `tests/PagosMpTest.php`

**Interfaces:**
- Consumes: `mp_firma_valida`, `mp_obtener_pago`, `mp_obtener_preapproval` (Task 3), `mail_enviar` (`mail.php`), tablas de la Task 1, `cobros_update`-compatible campos de `cobros`.
- Produces:
  - `mp_webhook_procesar(array $headers, array $query, string $cuerpoCrudo): array{http: int, resultado: string}`: 401 si la firma no valida (no toca nada); si valida, guarda el aviso en `mp_notificaciones` y procesa; 200 si procesó o si no hay nada que hacer; 500 solo si la consulta a Mercado Pago falló (para que reintente).
  - `cobro_registrar_pago_mp(array $pago): string`: devuelve `'pagado'`, `'ya_pagado'`, `'sin_cobro'`, `'monto_distinto'` o `'no_aprobado'`.
  - `cobro_de_pago_mp(array $pago): ?array`: resuelve el cobro por `external_reference` (pagos por link) o, para débito, por el `preapproval_id` del pago: el cobro `pendiente` más antiguo de esa suscripción cuyo monto coincida.
  - `suscripcion_confirmar_preapproval(string $preapprovalId): void`: cuando Mercado Pago informa la autorización, pone `mp_preapproval_estado = 'autorizada'` y `modalidad = 'debito'`.
  - `api/mp-webhook.php`: endpoint público, sin sesión ni CSRF; arma `$headers` desde `$_SERVER`, llama a `mp_webhook_procesar` y responde con `http_response_code`.

- [ ] **Step 1: Escribir los tests que fallan** (pasarela y mail falsos; un helper arma un pago aprobado `{id, status, transaction_amount, currency_id, external_reference}` y los headers con una firma válida generada con `MP_WEBHOOK_SECRET` de prueba):
  - `test_firma_invalida_responde_401_y_no_cambia_nada` (cobro sigue `pendiente`, `mp_notificaciones` sin filas);
  - `test_pago_aprobado_marca_pagado_con_fecha_metodo_e_id` (`estado = 'pagado'`, `fecha_pago = hoy()`, `metodo_pago = 'Mercado Pago · link'`, `mp_payment_id` guardado);
  - `test_el_mismo_aviso_cinco_veces_paga_una_sola_vez_y_confirma_una_sola_vez` (un solo mail `pago_confirmado`; un solo registro `confirmacion` en `avisos_pago`);
  - `test_un_pago_con_monto_distinto_no_marca_pagado` y `test_un_pago_con_otra_moneda_no_marca_pagado` (resultado `monto_distinto`, cobro `pendiente`, y la notificación queda con `sin_cobro = 1` y un `error` descriptivo);
  - `test_un_pago_de_un_cobro_inexistente_queda_sin_cobro_asignado` (200, `sin_cobro = 1`);
  - `test_pagos_pendientes_o_rechazados_no_cambian_el_cobro` (y el rechazado de un débito dispara el aviso `rechazo` una sola vez);
  - `test_el_pago_de_un_debito_se_asigna_al_cobro_pendiente_mas_antiguo_con_ese_monto` (`metodo_pago = 'Mercado Pago · débito'`);
  - `test_si_mercado_pago_no_responde_se_devuelve_500_y_se_registra_el_error`;
  - `test_la_confirmacion_de_un_preapproval_pasa_la_suscripcion_a_debito` (de `pendiente` a `autorizada`, `modalidad = 'debito'`);
  - `test_pagar_no_mueve_fecha_proximo_cobro`.
- [ ] **Step 2: Correr y ver fallar.** Run: `php vendor/bin/phpunit tests/PagosMpTest.php`. Expected: FAIL.
- [ ] **Step 3: Implementar `pagos_mp.php`.** La idempotencia sale de `mp_payment_id` único y del estado del cobro dentro de una transacción con `SELECT ... FOR UPDATE`; la confirmación se registra en `avisos_pago` (tipo `confirmacion`, canal `mail`) **antes** de mandar el mail, usando la clave única.
- [ ] **Step 4: Crear `api/mp-webhook.php`.** Delgado: toda la lógica vive en el repo para poder testearla.
- [ ] **Step 5: Correr los tests y la suite.** Expected: `OK`.
- [ ] **Step 6: Commit** `Validar los pagos de Mercado Pago con webhook verificado`.

---

### Task 6: Reconciliación de respaldo

**Files:**
- Modify: `admin/includes/repos/pagos_mp.php`
- Test: `tests/PagosMpTest.php` (agregar casos)

**Interfaces:**
- Consumes: `mp_buscar_pagos` (Task 3), `cobro_registrar_pago_mp` (Task 5).
- Produces: `pagos_reconciliar(): array{revisados: int, pagados: int, errores: int}`: para cada cobro `pendiente` con `suscripcion_cliente_id` y de los últimos 45 días, busca en Mercado Pago los pagos de su referencia (cobros por link: id del cobro; débito: el preapproval) y procesa los aprobados como lo haría el webhook.

- [ ] **Step 1: Escribir los tests que fallan.** `test_reconciliar_paga_un_cobro_cuyo_webhook_se_perdio`; `test_reconciliar_es_idempotente_con_el_webhook` (webhook primero y reconciliación después, o al revés: un solo pago, un solo mail); `test_reconciliar_ignora_cobros_pagados_y_sin_suscripcion`; `test_si_la_pasarela_falla_cuenta_el_error_y_sigue_con_los_demas`.
- [ ] **Step 2: Correr y ver fallar.** Run: `php vendor/bin/phpunit tests/PagosMpTest.php`. Expected: los casos nuevos fallan.
- [ ] **Step 3: Implementar `pagos_reconciliar`** reutilizando `cobro_registrar_pago_mp`; nada de lógica de pago duplicada.
- [ ] **Step 4: Correr los tests y la suite.** Expected: `OK`.
- [ ] **Step 5: Commit** `Agregar la reconciliación de pagos de respaldo`.

---

### Task 7: Avisos por mail y WhatsApp

**Files:**
- Create: `admin/includes/repos/avisos_pago.php`, `admin/includes/whatsapp.php`
- Test: `tests/AvisosPagoTest.php`

**Interfaces:**
- Consumes: `mail_enviar`, `mail_post`, `portal_url` (`mail.php`), `dias_entre`, `hoy`, tabla `avisos_pago`.
- Produces:
  - `final class Whatsapp { public static $transporte = null; }` (misma firma que `Mail::$transporte`)
  - `whatsapp_enviar(string $plantilla, string $telefono, array $datos): bool`: nunca lanza; sin `WHATSAPP_WEBHOOK_URL` devuelve `false` sin error; usa `MAIL_WEBHOOK_SECRET`.
  - `whatsapp_telefono_normalizar(string $telefono): ?string`: deja solo dígitos con prefijo `54` y `9` para móviles argentinos (`'11 5555-1234'` → `'5491155551234'`, `'+54 9 11 5555 1234'` → `'5491155551234'`); `null` si no parece un teléfono.
  - `aviso_corresponde(string $hoy, string $fechaVencimiento): ?array{tipo: string, numero: int}`: `dias = dias_entre($hoy, $fechaVencimiento)`; `3` → `previo`/0; `0` → `vencimiento`/0; negativo múltiplo de 3 → `vencido`/`-dias/3`; si no, `null`.
  - `avisos_enviar_pendientes(?string $hoy = null): array{enviados: int, fallidos: int, omitidos: int}`: para cada cobro `pendiente` con `suscripcion_cliente_id` calcula el aviso, lo registra en `avisos_pago` por canal (una fila por canal) **antes** de enviar y actualiza `ok`. Un canal que falla se reintenta en la próxima corrida; no bloquea al otro. Datos de la plantilla: `nombre`, `concepto`, `monto` (formato `$85.000`), `fecha_vencimiento`, `dias`, `link_pago`, `portal` (`portal_url('/clientes/pagos')`). Plantillas de mail y de WhatsApp: `pago_previo`, `pago_vencimiento`, `pago_vencido`, `pago_confirmado`, `precio_cambiado`, `debito_rechazado`.
  - `cobro_whatsapp_manual(array $cobro, array $cliente): ?string`: URL `https://wa.me/{telefono}?text={mensaje}` con el mensaje ya escrito en voseo; `null` si no hay teléfono válido.
  - `cobro_reenviar_link(int $cobroId): bool`: reenvía el mail de aviso correspondiente sin tocar `avisos_pago`.

- [ ] **Step 1: Escribir los tests que fallan.**
  - `test_aviso_corresponde_en_los_dias_3_antes_0_y_cada_3_vencido` (tabla: `+3` previo, `+2` null, `0` vencimiento, `-1` null, `-3` vencido 1, `-6` vencido 2, `-9` vencido 3);
  - `test_manda_mail_y_whatsapp_con_los_datos_del_cobro` (transportes falsos; payload `{plantilla, destino, datos}`; monto formateado `$85.000`);
  - `test_correr_dos_veces_el_mismo_dia_no_repite_el_aviso`;
  - `test_un_cobro_creado_con_menos_de_3_dias_no_manda_el_previo`;
  - `test_un_cobro_pagado_no_recibe_avisos`;
  - `test_si_el_mail_falla_el_whatsapp_sale_igual_y_el_mail_se_reintenta_despues` (fila `ok = 0` del mail y reintento al llamar de nuevo);
  - `test_whatsapp_apagado_no_rompe_ni_registra_filas_de_whatsapp`;
  - `test_cliente_sin_mail_ni_telefono_se_omite_y_no_frena_a_los_demas` (`omitidos` suma, el otro cliente recibe su aviso);
  - `test_normalizar_telefonos` (los dos ejemplos de arriba, un número con letras → `null`);
  - `test_el_link_de_whatsapp_manual_trae_el_mensaje_codificado`.
- [ ] **Step 2: Correr y ver fallar.** Run: `php vendor/bin/phpunit tests/AvisosPagoTest.php`. Expected: FAIL.
- [ ] **Step 3: Implementar `whatsapp.php` y `avisos_pago.php`.** El registro previo al envío usa `INSERT IGNORE` sobre la clave única `(cobro_id, tipo, canal, numero)`; si la fila existe con `ok = 1` se omite, si existe con `ok = 0` se reintenta.
- [ ] **Step 4: Correr los tests y la suite.** Expected: `OK`.
- [ ] **Step 5: Commit** `Agregar los avisos de pago por mail y WhatsApp`.

---

### Task 8: Ciclo de vida de la suscripción (precio, modalidad, pausa y cancelación)

**Files:**
- Create: `admin/includes/repos/suscripciones_ciclo.php`
- Modify: `admin/includes/repos/cobros.php` (invalidar el link al marcar pagado a mano), `api/suscripciones-cliente.php` (acciones)
- Modify: `.htaccess` (regla `^api/suscripciones-cliente/([0-9]+)/([a-z-]+)$ api/suscripciones-cliente.php?id=$1&accion=$2`)
- Test: `tests/SuscripcionesCicloTest.php`

**Interfaces:**
- Consumes: Tasks 2, 3, 5, 7.
- Produces:
  - `suscripcion_cliente_ajustar_precio(int $id, string $montoNuevo): array`: guarda el monto, registra `suscripciones_precio_historial`, manda el mail `precio_cambiado` (y marca `avisado_en`), y si la modalidad es `debito` con `mp_preapproval_estado = 'autorizada'` actualiza el monto en Mercado Pago. Los cobros ya generados no cambian.
  - `suscripcion_cliente_cambiar_modalidad(int $id, string $modalidad, string $origen): array`: `$origen` es `'admin'` o `'cliente'`. El cliente solo alterna entre `debito` y `link`. A `debito`: crea el preapproval con inicio en `fecha_proximo_cobro`, guarda `mp_preapproval_id`, `mp_init_point` y `mp_preapproval_estado = 'pendiente'`, y **deja la modalidad anterior** hasta la autorización (Task 5). A `link` o `transferencia`: cancela el preapproval si existe. 409 si hay un pago en proceso (cobro con pago `in_process` registrado o un `mp_notificaciones` sin procesar de esa suscripción).
  - `suscripcion_cliente_pausar(int $id, bool $pausada): array` (pausa o reanuda también el preapproval) y `suscripcion_cliente_cancelar(int $id): array` (estado `cancelada`, `fecha_baja = hoy()`, cancela el preapproval).
  - `cobro_invalidar_link(int $cobroId): void`: mejor esfuerzo; se llama desde `cobros_update` cuando un cobro con `mp_preference_id` pasa a `pagado` a mano.

- [ ] **Step 1: Escribir los tests que fallan.**
  - Precio: `test_ajustar_precio_cambia_el_monto_registra_historial_y_manda_el_mail`; `test_no_cambia_los_cobros_ya_generados` y `test_el_cobro_siguiente_sale_con_el_monto_nuevo`; `test_con_debito_autorizado_actualiza_el_monto_en_mercado_pago`; `test_monto_invalido_da_422`.
  - Modalidad: `test_pasar_a_debito_crea_el_preapproval_y_deja_la_modalidad_anterior_pendiente`; `test_el_cliente_no_puede_elegir_transferencia` (422); `test_pasar_a_link_cancela_el_preapproval`; `test_se_bloquea_el_cambio_con_un_pago_en_proceso` (409); `test_el_cobro_abierto_conserva_su_via_hasta_pagarse`.
  - Pausa y cancelación: `test_pausar_y_reanudar_sincronizan_el_preapproval`; `test_cancelar_marca_fecha_baja_y_cancela_en_mercado_pago`; `test_si_mercado_pago_falla_no_se_cambia_el_estado_local` (la operación falla con error y el estado queda como estaba).
  - Link: `test_marcar_pagado_a_mano_invalida_el_link` y `test_los_cobros_manuales_sin_link_siguen_igual` (los tests de `CobrosTest` pasan sin tocarse).
- [ ] **Step 2: Correr y ver fallar.** Run: `php vendor/bin/phpunit tests/SuscripcionesCicloTest.php`. Expected: FAIL.
- [ ] **Step 3: Implementar el ciclo.** Orden de operaciones: primero la llamada a Mercado Pago, después el cambio local en la transacción, para que una falla de la pasarela no deje estados inconsistentes.
- [ ] **Step 4: Agregar las acciones al endpoint** `api/suscripciones-cliente.php` (`precio`, `modalidad`, `pausar`, `reanudar`, `cancelar`) y la regla del `.htaccess`.
- [ ] **Step 5: Modificar `cobros_update`** para llamar a `cobro_invalidar_link` solo cuando el estado pasa de `pendiente` a `pagado` y existe `mp_preference_id`.
- [ ] **Step 6: Correr los tests y la suite.** Expected: `OK`.
- [ ] **Step 7: Commit** `Agregar el ajuste de precio, el cambio de modalidad y la pausa de suscripciones`.

---

### Task 9: Cron diario, configuración y documentación de deploy

**Files:**
- Create: `scripts/cobros-diario.php`
- Modify: `.env.example`, `DEPLOY.md`, `scripts/verificar-portal.sh` (si existe: agregar `/api/mp-webhook`, `/clientes/pagos`)
- Test: ninguno nuevo (el script solo orquesta funciones ya testeadas)

**Interfaces:**
- Consumes: `cobros_generar_pendientes`, `cobros_completar_links` (Task 4), `pagos_reconciliar` (Task 6), `avisos_enviar_pendientes` (Task 7).
- Produces: `scripts/cobros-diario.php`, solo CLI (`PHP_SAPI !== 'cli'` → 404), corre en este orden y no se corta si un paso falla: generar → completar links → reconciliar → avisos. Imprime una línea de resumen por paso.

- [ ] **Step 1: Escribir el script** con la misma guarda y `require` que `scripts/chequear-servicios.php` (plan de la fase 2) / los scripts existentes.
- [ ] **Step 2: Documentar en `.env.example` y `DEPLOY.md`:** variables nuevas sin valores, el Cron Job diario de hPanel (`php /ruta/scripts/cobros-diario.php`, una vez al día a la mañana), la URL del webhook para cargar en Mercado Pago (`https://vezzadev.com/api/mp-webhook`) y los eventos a suscribir, y el orden de deploy: migración `003` desde `/admin/migraciones` antes de activar el cron.
- [ ] **Step 3: Verificar a mano.** Run: `php scripts/cobros-diario.php` con MariaDB prendida y la base local migrada. Expected: cuatro líneas de resumen con ceros y sin errores. Después del deploy, `https://vezzadev.com/scripts/cobros-diario.php` tiene que responder 404 (el `.htaccess` ya bloquea `/scripts`).
- [ ] **Step 4: Commit** `Agregar el cron diario de cobros y documentar el deploy`.

---

### Task 10: Workflow de n8n para mails y WhatsApp

**Files:**
- Create: `docs/n8n/vezza-avisos-de-pago.json`, `docs/n8n/README.md` (cómo importar y qué credenciales cargar)
- Test: `tests/N8nWorkflowTest.php`

**Interfaces:**
- Consumes: el contrato `{plantilla, destino, datos}` y el header `X-Webhook-Secret` de `mail_enviar`.
- Produces: un workflow importable "VEZZA · Avisos de pago": nodo Webhook que valida el secreto, un Switch por `plantilla` con las seis plantillas de la Task 7, una rama de mail (SMTP de `info@vezzadev.com`, asunto y cuerpo en voseo) y una rama de WhatsApp (WhatsApp Business Cloud) **deshabilitada** por defecto. Responde 2xx al terminar el envío. El MCP de n8n no estaba conectado al diseñar esto: si se reconecta, se puede crear directo; si no, queda el JSON.

- [ ] **Step 1: Escribir el test que falla.** `N8nWorkflowTest`: `test_el_json_es_valido`; `test_cubre_las_seis_plantillas_de_mail` (cada nombre aparece en el Switch); `test_la_rama_de_whatsapp_esta_deshabilitada`; `test_no_contiene_secretos` (ningún valor que parezca token, solo referencias a credenciales).
- [ ] **Step 2: Correr y ver fallar.** Run: `php vendor/bin/phpunit tests/N8nWorkflowTest.php`. Expected: FAIL (archivo inexistente).
- [ ] **Step 3: Escribir el workflow y el README.** Textos en voseo; el monto y el link llegan ya formateados en `datos`. Las plantillas de WhatsApp para presentar a Meta van como lista en el README (nombre, texto y variables).
- [ ] **Step 4: Correr los tests y la suite.** Expected: `OK`.
- [ ] **Step 5: Commit** `Agregar el workflow de n8n para los avisos de pago`.

---

### Task 11: Lectura de "Suscripciones mensuales" para el admin

**Files:**
- Create: `admin/includes/repos/suscripciones_mensuales.php`
- Test: `tests/SuscripcionesMensualesTest.php`
- Modify: `api/suscripciones-cliente.php` (ruta `?accion=mensuales`; regla `.htaccess` `^api/suscripciones-mensuales$ api/suscripciones-cliente.php?accion=mensuales`)

**Interfaces:**
- Consumes: Tasks 2, 4, 7 (`cobro_whatsapp_manual`).
- Produces: `suscripciones_mensuales(array $get): array{resumen: array{ingreso_mensual: string, al_dia: int, por_vencer: int, vencidas: int}, filas: array}`. Cada fila: `id`, `cliente_id`, `cliente_nombre`, `plan_nombre`, `monto`, `moneda`, `modalidad`, `mp_preapproval_estado`, `pausada`, `fecha_proximo_cobro`, `estado` (`'al_dia'|'por_vencer'|'vencido'|'pausada'|'cancelada'`), `cobro_abierto` (id, `fecha_vencimiento`, `dias`, `link_pago`) o `null`, `ultimo_aviso` (`{tipo, canal, enviado_en}` o `null`) y `whatsapp_url` (o `null`). `por_vencer` = tiene un cobro pendiente con vencimiento dentro de los próximos 5 días; `vencido` = tiene alguno vencido; `al_dia` = ningún cobro abierto atrasado.

- [ ] **Step 1: Escribir los tests que fallan.** `test_el_ingreso_mensual_suma_solo_suscripciones_activas_y_no_pausadas`; `test_clasifica_al_dia_por_vencer_y_vencido`; `test_una_suscripcion_con_dos_meses_sin_pagar_cuenta_una_sola_vez_como_vencida`; `test_filtra_por_cliente`; `test_trae_el_ultimo_aviso_y_el_link_de_whatsapp`; `test_pausadas_y_canceladas_no_cuentan_en_el_resumen`.
- [ ] **Step 2: Correr y ver fallar.** Run: `php vendor/bin/phpunit tests/SuscripcionesMensualesTest.php`. Expected: FAIL.
- [ ] **Step 3: Implementar el repo y la ruta del endpoint.**
- [ ] **Step 4: Correr los tests y la suite.** Expected: `OK`.
- [ ] **Step 5: Commit** `Agregar la lectura de suscripciones mensuales para el admin`.

---

### Task 12: Pestaña "Suscripciones mensuales" y pantalla de planes (admin)

**Files:**
- Create: `admin/planes.php`, `admin/assets/planes.js`, `admin/assets/mod-suscripciones.js`
- Modify: `admin/cobros.php`, `admin/assets/cobros.js`, `admin/includes/layout.php` (`PANEL_ASSET_V`), `.htaccess` (agregar `planes` a la lista de rutas del admin)

**Interfaces:**
- Consumes: `/api/suscripciones-mensuales`, `/api/suscripciones-cliente` (+ acciones), `/api/planes`, helpers `Panel.*` y `el` de `admin.js`, `Cobros.*` de `mod-cobros.js`.
- Produces: la pestaña **Suscripciones mensuales** como quinta pestaña de `TABS` en `cobros.js`, según el mockup `pagos-estilos-v2.html`: resumen con `.stats`; una `.item` por suscripción con insignias (`badge-ok`, `badge-warn`, `badge-danger`, `badge-accent`, ícono + texto), botón principal visible, selector "Más acciones…" con Avisar por WhatsApp (abre `whatsapp_url`), Cambiar modalidad, Ajustar precio, Pausar/Reanudar, Historial; **+ Nueva suscripción** y un acceso a **Planes**. `admin/planes.php`: CRUD de planes con el layout existente (`layout_start('Planes', 'cobros', ...)`).

- [ ] **Step 1: Agregar la ruta `planes`** al `RewriteRule` de páginas del admin y crear `admin/planes.php` + `planes.js` (lista y formulario de alta/edición con `Panel.*`).
- [ ] **Step 2: Crear `mod-suscripciones.js`** con el render de una fila, los formularios de nueva suscripción, ajustar precio (pide monto y avisa que se manda un mail) y cambiar modalidad (muestra el link de alta de débito para copiar), y las llamadas a las acciones.
- [ ] **Step 3: Agregar la pestaña** en `cobros.js` (carga `/api/suscripciones-mensuales` con el filtro de cliente existente) y cargar `mod-suscripciones.js` desde `admin/cobros.php`. Todo el texto dinámico con `el`/`textContent`.
- [ ] **Step 4: Subir `PANEL_ASSET_V`** y agregar las clases nuevas solo si hacen falta (`.monto`, ícono en insignia) en `admin.css`.
- [ ] **Step 5: Verificar a mano** con `php -S localhost:8080` y `/admin/cobros`: la pestaña muestra las cuatro filas de ejemplo (cargar datos de prueba por la UI), las acciones funcionan, y a 360 px de ancho no hay scroll horizontal ni controles menores de 44 px. Capturar una pantalla de la pestaña y revisar contra el mockup.
- [ ] **Step 6: Correr la suite completa.** Run: `php vendor/bin/phpunit`. Expected: `OK`.
- [ ] **Step 7: Commit** `Agregar la pestaña de suscripciones mensuales y la pantalla de planes`.

---

### Task 13: Datos y API de pagos del portal

**Files:**
- Create: `admin/includes/repos/portal_pagos.php`, `clientes/api/pagos.php`
- Create: `admin/includes/portal_api.php` **solo si no existe** (la fase 2 lo crea; si ya está en `main`, reutilizarlo)
- Modify: `.htaccess` (rutas `clientes/pagos` y `clientes/api/([a-z-]+)(/([0-9]+))?`; si la fase 2 ya agregó la de `clientes/api`, no duplicarla)
- Test: `tests/PortalPagosTest.php`

**Interfaces:**
- Consumes: `require_cliente_api()`, `session_boot_cliente()` (`auth_cliente.php`); `suscripcion_cliente_cambiar_modalidad` (Task 8).
- Produces:
  - `portal_api_run(callable $fn): void`: `$fn` recibe el `cliente_id` de la sesión como `int`; 401 sin sesión; CSRF en métodos no GET; mismas convenciones de JSON y errores que `api_run`. Si hay que crearlo, copiar la definición de `portal_api_run` del plan de la fase 2 (`2026-10-01-portal-clientes-fase-2-servicios.md`, Task 3).
  - `portal_pagos(int $clienteId): array{pendientes: int, cobros: array, suscripciones: array}`. Cada cobro: `id`, `concepto`, `periodo`, `monto`, `moneda`, `fecha_vencimiento`, `fecha_pago`, `metodo_pago`, `estado` (`pendiente|vencido|pagado`), `dias` (negativo si venció) y `link_pago` solo si está abierto. Cada suscripción: `id`, `plan_nombre`, `monto`, `moneda`, `modalidad`, `debito_pendiente` (bool), `link_autorizacion` (solo si `debito_pendiente`). **Nunca** `mp_*`, `cliente_id` ni ids internos de Mercado Pago.
  - `portal_pagos_cambiar_modalidad(int $clienteId, int $suscripcionId, string $modalidad): array`: verifica que la suscripción sea del cliente (404 si no) y delega en `suscripcion_cliente_cambiar_modalidad(..., 'cliente')`.
  - `GET /clientes/api/pagos`; `POST /clientes/api/pagos/modalidad` con `{suscripcion_id, modalidad}`.

- [ ] **Step 1: Escribir los tests que fallan.**
  - `test_un_cliente_solo_ve_sus_cobros_y_suscripciones`;
  - `test_la_respuesta_no_trae_campos_internos` (ninguna clave que empiece con `mp_`, ni `cliente_id`);
  - `test_el_link_de_pago_solo_aparece_en_cobros_abiertos`;
  - `test_pendientes_cuenta_cobros_pendientes_y_vencidos`;
  - `test_ordena_lo_pendiente_arriba_y_lo_pagado_abajo_por_fecha`;
  - `test_cambiar_la_modalidad_de_una_suscripcion_ajena_da_404`;
  - `test_el_cliente_no_puede_pedir_transferencia` (422);
  - `test_una_sesion_de_admin_no_sirve_en_la_api_del_portal` y `test_una_sesion_de_cliente_no_sirve_en_api_del_admin` (401, ambos sentidos).
- [ ] **Step 2: Correr y ver fallar.** Run: `php vendor/bin/phpunit tests/PortalPagosTest.php`. Expected: FAIL.
- [ ] **Step 3: Implementar** `portal_pagos.php`, el endpoint con `portal_api_run` y las reglas del `.htaccess`.
- [ ] **Step 4: Correr los tests y la suite.** Expected: `OK`.
- [ ] **Step 5: Commit** `Agregar los datos y la API de pagos del portal`.

---

### Task 14: Pantalla Pagos del portal, navegación y cartel de aviso

**Files:**
- Create: `clientes/pagos.php`
- Modify: `admin/includes/portal_layout.php` (función `portal_nav`, `PORTAL_ASSET_V`), `clientes/index.php`, `clientes/assets/portal.js`, `clientes/assets/portal.css`, `.htaccess` (`pagos` en la regla de páginas del portal)

**Interfaces:**
- Consumes: `GET /clientes/api/pagos`, `POST /clientes/api/pagos/modalidad` (Task 13); `.bottom-nav`, `.item`, `.badge-*`, `.btn*`, `.seccion` de `admin.css`.
- Produces:
  - `portal_nav(string $activo): void`: hoy el portal no tiene navegación (la fase 1 solo trae el inicio). Esta tarea introduce una barra inferior mínima con **Inicio** y **Pagos** (el `.bottom-nav` existente con `grid-template-columns` según la cantidad de ítems), pensada para que las fases 2, 3 y 4 agreguen **Estado**, **Planes** y **Tickets**. Si la fase 2 ya agregó una navegación, extenderla en vez de duplicarla.
  - Pantalla `/clientes/pagos` según el mockup `pagos-estilos-v2.html`: cartel `.aviso-pago` (variante `-warn` si faltan 3 días o menos, `-danger` si venció) con botón **Pagar** al `link_pago`; lista de meses con `.item` (pendiente/vencido arriba, pagado abajo con fecha y método); sección "Cómo pagás" con la modalidad actual y el botón para pasar de una a otra (débito → link; link → débito, que lleva a `link_autorizacion`); estado "Débito pendiente de autorización" con el botón para volver a abrir el alta.
  - El mismo cartel en `clientes/index.php`, solo cuando hay un cobro por vencer en 3 días o vencido, con un link a Pagos.
  - `.contador` sobre el ítem Pagos con `role="status"` y `aria-label` "N pagos pendientes" (singular cuando es 1), y `.aviso-pago`, `.contador`, `.monto` en `portal.css`.

- [ ] **Step 1: Agregar `portal_nav` y la regla `pagos`** del `.htaccess`; poner la barra en `index.php` y `pagos.php`; subir `PORTAL_ASSET_V`.
- [ ] **Step 2: Crear `clientes/pagos.php`** con el esqueleto de la página y el contenedor que `portal.js` llena.
- [ ] **Step 3: Escribir el JS de Pagos en `portal.js`**: carga `GET /clientes/api/pagos`, renderiza con `el`/`textContent` (nunca `innerHTML` con datos), manda el CSRF de `<meta name="csrf-token">` en el `POST`, refresca al volver a la pestaña visible y muestra errores en `.form-error` sin romper la lista.
- [ ] **Step 4: Agregar el cartel y el contador** al inicio y a la navegación; estilos nuevos en `portal.css`, solo los tres del Global Constraints.
- [ ] **Step 5: Verificar a mano** con `php -S localhost:8080` y un cliente de prueba con un cobro vencido, uno por vencer y uno pagado: cartel rojo, lista ordenada, contador "1", cambio de modalidad, y revisión a 360 px sin scroll horizontal ni controles menores de 44 px. Capturar pantalla y comparar contra el mockup.
- [ ] **Step 6: Correr la suite completa y `scripts/verificar-portal.sh`** (si existe). Expected: `OK` y rutas protegidas respondiendo como antes.
- [ ] **Step 7: Commit** `Agregar la pantalla de pagos y el aviso de pagos pendientes al portal`.

---

### Task 15: Verificación final contra Mercado Pago de prueba

**Files:**
- Modify: `DEPLOY.md` (checklist de salida a producción)

**Interfaces:**
- Consumes: todo lo anterior, credenciales y usuarios de prueba de Mercado Pago.
- Produces: la integración probada de punta a punta antes de cobrarle a un cliente real.

- [ ] **Step 1: Con credenciales de prueba** cargadas en `.env` local, crear un cliente y una suscripción de `link`: correr `php scripts/cobros-diario.php`, abrir el link generado, pagar con un usuario de prueba y comprobar que el webhook (expuesto con un túnel o desde el deploy de prueba) marca el cobro como pagado y manda la confirmación.
- [ ] **Step 2: Repetir con `debito`:** alta de la suscripción, autorización con la tarjeta de prueba, cobro, y un pago rechazado para ver el aviso `rechazo`. Anotar en `DEPLOY.md` cualquier diferencia real de comportamiento (reintentos, cambios de monto) que no estuviera en el diseño.
- [ ] **Step 3: Importar el workflow de n8n**, cargar credenciales y probar un aviso real de cada plantilla de mail.
- [ ] **Step 4: Cargar la URL del webhook en el panel de Mercado Pago de producción**, dejar `MP_*` reales solo en el `.env` del servidor y activar el Cron Job.
- [ ] **Step 5: Pago real de bajo monto** (por ejemplo `$100`) con una suscripción propia, comprobar que se refleja y recién entonces cargar a un cliente real.
- [ ] **Step 6: Commit** `Documentar la verificación y la salida a producción de los pagos`.

---

## Self-review

- **Cobertura del spec:** §1/§2 → Tasks 2, 4, 5 (modalidades y alcance); §4 datos → Task 1; §5.1 → Task 4; §5.2 → Tasks 5 y 6; §5.3 → Task 7; §5.4 y §5.5 → Task 8; §5.6 reglas de borde → Tasks 4 (acumulación), 5 (débito más antiguo, sin cobro asignado), 7 (previo omitido), 8 (pausa, cancelación, modalidad, link inválido), 1 (backfill); §6 pantallas → Tasks 11–14; §6.1 estilo → Tasks 12 y 14; §7 n8n → Task 10; §8 configuración y cron → Task 9; §9 seguridad → Tasks 3 (firma), 5, 13; §10 pruebas → repartidas en cada tarea; §11 verificación con sandbox → Task 15.
- **Consistencia de nombres:** `cobros_generar_pendientes`, `cobros_completar_links`, `cobro_registrar_pago_mp`, `cobro_de_pago_mp`, `mp_webhook_procesar`, `pagos_reconciliar`, `avisos_enviar_pendientes`, `aviso_corresponde`, `suscripcion_cliente_*` y `portal_pagos*` se definen en la tarea que los produce y se usan con la misma firma en las siguientes. Los valores de enums (`debito|link|transferencia`, `pendiente|autorizada|pausada|cancelada`, `previo|vencimiento|vencido|confirmacion|rechazo`) son los de la Task 1.
- **Diferencias deliberadas con el spec:** se agrega `mp_init_point` (para reenviar el link de alta) y el tipo de aviso `rechazo` (el spec nombra la plantilla `debito_rechazado` pero no el tipo en `avisos_pago`); el webhook responde 500 solo cuando falla la consulta a Mercado Pago, para que reintente; el portal no tiene navegación hoy, así que la Task 14 introduce una mínima.
- **Dependencia con la fase 2:** `portal_api_run` y la ruta `clientes/api/*` se reutilizan si la fase 2 ya está en `main` y se crean con el mismo contrato si no. `PORTAL_ASSET_V` y `PANEL_ASSET_V` se suben desde el valor que tengan en ese momento.
