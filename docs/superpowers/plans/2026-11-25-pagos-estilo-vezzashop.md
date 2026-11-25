# Pagos de suscripciones al estilo vezzashop — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que los clientes, las suscripciones mensuales y los cobros del admin de VEZZA funcionen como en vezzashop: débito automático de Mercado Pago más registro manual, con el estado calculado desde "pago hasta" y sin cron.

**Architecture:** La rama `pagos-suscripciones` ya tiene un motor de cobros mensuales con links, cron y reconciliación. Se lo reemplaza: se reescribe la migración `003` (nunca se publicó), se borra el camino de los links y los avisos, y se reescribe el webhook sobre `subscription_preapproval` y `subscription_authorized_payment`. Cada cobro aprobado, o registrado a mano, crea un cobro `pagado` en `cobros` y adelanta `fecha_proximo_cobro`; el estado (al día, por vencer, vencido) se calcula. Mercado Pago y los mails entran por costuras inyectables (`MercadoPago::$transporte`, `Mail::$transporte`): los tests no usan la red.

**Tech Stack:** PHP 8.1 plano, MySQL/MariaDB, JS puro, PHPUnit 10.5, API HTTP de Mercado Pago (sin SDK), n8n. Sin build. Deploy por `git push` a `main` (Hostinger).

**Spec:** `docs/superpowers/specs/2026-11-25-pagos-estilo-vezzashop-design.md` (aprobado). Referencia de comportamiento: el repo `github.com/valentinomezzavilla/vezzashop` (`src/suscripciones.js`, `src/rutas/webhooks.js`, `src/mercadopago.js`).

## Global Constraints

- PHP 8.1 (`composer.json` fija `platform.php = 8.1.0`): nada de sintaxis ni funciones de 8.2+. Sin frameworks ni SDK de Mercado Pago.
- Migración `db/migrations/003_pagos.sql` reescrita en el lugar: aditiva sobre 001 y 002, InnoDB, `utf8mb4_unicode_ci`, registrada con `INSERT IGNORE INTO migraciones`.
- Sin secretos en el repo. Variables: `MP_ACCESS_TOKEN`, `MP_WEBHOOK_SECRET`. Sin `MP_ACCESS_TOKEN` el débito queda apagado y el resto del admin no cambia.
- **Solo `/admin` y `/api`.** No se toca `index.html`, la landing ni nada de `clientes/` (el portal).
- Débito solo en pesos (`ARS`) y con cliente con mail. Una suscripción en otra moneda solo puede ser `transferencia`.
- **Nunca se pausa un servicio por falta de pago.** El vencimiento solo se muestra.
- Un cobro de Mercado Pago solo se registra después de consultarlo a la API (el cuerpo del aviso no es prueba), una sola vez (`cobros.mp_payment_id` único), con la fila de la suscripción bloqueada.
- Ante un fallo de Mercado Pago, el estado local **no cambia** (primero la llamada a Mercado Pago, después el cambio local).
- Estilo: solo tokens y clases de `admin/assets/admin.css` (`.item`, `.badge-*`, `.stats`, `.tabs`, `.btn*`, `.filtro`); el estado nunca va solo en color; usable a 360 px, controles de 44 px. Estilos nuevos solo si hacen falta (`.monto`, ícono en insignia). Subir `PANEL_ASSET_V` al tocar CSS/JS.
- El JS del admin usa `textContent` o el helper `el`, nunca `innerHTML` con datos. CSRF en métodos no GET (ya lo hace `api_run`).
- Voz: voseo en primera persona singular. Montos `$85.000`, fechas cortas ("1 nov") y largas en los mails ("25 de noviembre de 2026").
- Los tests existentes del admin y del portal siguen pasando sin modificarse (salvo los de este plan que lo indican).
- Commits en español, estilo del repo ("Agregar ...", "Hacer que ..."), cada uno con el trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` como segundo `-m`.

## Review Focus

Entradas y condiciones que el spec implica y que podrían romper el sistema. Cada línea tiene su test en la tarea dueña del código.

1. **El mismo cobro de Mercado Pago llegando dos veces, fuera de orden o por dos caminos (webhook y Sincronizar):** suma un solo mes y crea un solo cobro. → Task 4 y Task 6.
2. **Cobro aprobado de una suscripción cancelada o pausada, o de un preapproval que no conocemos, o en otra moneda:** el dinero entró, así que no se descarta; el conocido se registra y el desconocido queda en "sin cobro asignado" para revisar. → Task 6.
3. **Fin de mes y pagos atrasados:** 31 ene + 1 mes cae el 28 feb y 31 ene + 2 meses cae el 31 mar; un débito que llega tarde no corre la fecha. → Task 4.
4. **Mercado Pago o n8n caídos a mitad de una acción (pasar a débito, pausar, cancelar, ajustar precio):** la acción falla con un error claro y no deja estados a medias; un mail que no sale no tumba la acción. → Task 5 y Task 7.
5. **Cliente sin mail o sin teléfono válido:** el débito se rechaza con un mensaje claro, el mail se omite sin fallar y el botón de WhatsApp no aparece. → Task 2, Task 5 y Task 9.

## Estructura de archivos

**Crear**
- `admin/includes/repos/suscripciones_pagos.php`: registrar un cobro (manual o de Mercado Pago) y adelantar la fecha.
- `admin/includes/repos/suscripciones_ciclo.php`: pasar a débito, pausar, cancelar, reactivar, ajustar precio y los mails.
- `admin/includes/repos/suscripciones_mensuales.php`: lectura de la pestaña, teléfono y link de WhatsApp.
- `admin/planes.php`, `admin/assets/planes.js`, `admin/assets/mod-suscripciones.js`.
- `docs/n8n/vezza-avisos-de-pago.json`, `docs/n8n/README.md`.
- Tests: `SuscripcionesPagosTest`, `SuscripcionesCicloTest`, `SuscripcionesMensualesTest`, `N8nWorkflowTest`.

**Modificar**
- `db/migrations/003_pagos.sql`, `admin/includes/mercadopago.php`, `admin/includes/repos/suscripciones_cliente.php`, `admin/includes/repos/pagos_mp.php` (reescrito), `api/suscripciones-cliente.php`, `api/mp-webhook.php`, `.htaccess`.
- `admin/cobros.php`, `admin/assets/cobros.js`, `admin/cliente.php`, `admin/assets/cliente.js`, `admin/assets/admin.js`, `admin/includes/layout.php` (`PANEL_ASSET_V`), `.env.example`, `DEPLOY.md`, `scripts/verificar-panel.sh`.
- Tests: `MigracionPagosTest`, `SuscripcionesClienteTest`, `MercadoPagoTest`, `PagosMpTest` (reescrito).

**Borrar**
- `admin/includes/repos/cobros_suscripciones.php`, `admin/includes/repos/avisos_pago.php`, `tests/CobrosSuscripcionesTest.php`, `tests/AvisosPagoTest.php`.

## Prerrequisitos de entorno (una sola vez)

- **PHP:** está en `C:\xampp\php\windowsXamppPhp\php.exe` (8.2.12) y no está en el `PATH`. Agregá esa carpeta al `PATH` de la sesión o usá la ruta completa donde dice `php`.
- **MariaDB/MySQL:** hoy **no hay ninguno**: `C:\xampp\mysql\bin` está vacío y el puerto 3306 está cerrado. Hay que reinstalar XAMPP o instalar MariaDB y crear las bases `vezza_admin` y `vezza_admin_test` (el usuario y la clave van en `.env.testing`, que ya existe). Sin eso PHPUnit falla en el bootstrap.
- Línea base: `php vendor/bin/phpunit` da `OK` en la rama actual (311 tests según el registro anterior); anotar la cantidad. Correr siempre desde la raíz.
- Si tu base de desarrollo ya aplicó la `003` vieja, hay que recrearla: la `003` cambia en el lugar. La base de tests se recrea sola en cada corrida.

---

### Task 1: Migración reescrita y limpieza del camino de los links

**Files:**
- Modify: `db/migrations/003_pagos.sql`, `tests/MigracionPagosTest.php`, `tests/SuscripcionesClienteTest.php` (solo lo mínimo, ver Step 5)
- Delete: `admin/includes/repos/cobros_suscripciones.php`, `admin/includes/repos/avisos_pago.php`, `tests/CobrosSuscripcionesTest.php`, `tests/AvisosPagoTest.php`, `tests/PagosMpTest.php`
- Modify: encabezado de `docs/superpowers/specs/2026-10-27-pagos-suscripciones-design.md` y de `docs/superpowers/plans/2026-10-27-pagos-suscripciones.md`; `docs/superpowers/specs/2026-11-25-pagos-estilo-vezzashop-design.md` §6.5

**Interfaces:**
- Produces (el esquema que usan todas las tareas):
  - `suscripciones_cliente` + `monto DECIMAL(12,2) NOT NULL DEFAULT 0`, `moneda CHAR(3) NOT NULL DEFAULT 'ARS'`, `modalidad ENUM('debito','transferencia') NOT NULL DEFAULT 'transferencia'`, `mp_preapproval_id VARCHAR(100) NULL` con clave única, `mp_preapproval_estado ENUM('pendiente','autorizada','pausada','cancelada') NULL`, `mp_init_point VARCHAR(500) NULL`, `pausada TINYINT(1) NOT NULL DEFAULT 0`, `aviso_baja_enviado_en DATETIME NULL`. Las filas existentes copian `monto` y `moneda` de su plan.
  - `cobros` + `suscripcion_cliente_id INT UNSIGNED NULL` (FK `ON DELETE SET NULL`) y `mp_payment_id VARCHAR(100) NULL` con clave única. Sin `periodo`, `mp_preference_id` ni `link_pago`.
  - `mp_notificaciones(id, tipo VARCHAR(60), recurso_id VARCHAR(100), payload TEXT, recibida_en, procesada_en NULL, sin_cobro TINYINT(1) DEFAULT 0, error VARCHAR(255) NULL)`.
  - `suscripciones_precio_historial` igual que hoy. No existe `avisos_pago`.

- [ ] **Step 1: Preparar la rama.** Apartar el trabajo sin commitear: `git stash push -m "reconciliacion-descartada" -- admin/includes/repos/pagos_mp.php tests/PagosMpTest.php`. Agregar al inicio de los dos documentos del 27/10 la línea `> Reemplazado en lo que respecta al cobro por 2026-11-25-pagos-estilo-vezzashop-design.md`. En el spec nuevo §6.5, cambiar la regla: el pago manual avanza desde `max(fecha_proximo_cobro, hoy)` y el de débito siempre un mes desde `fecha_proximo_cobro` (así un aviso tardío no corre la fecha). Commit `git add docs/superpowers/specs/2026-11-25-pagos-estilo-vezzashop-design.md docs/superpowers/plans/2026-11-25-pagos-estilo-vezzashop.md docs/superpowers/specs/2026-10-27-pagos-suscripciones-design.md docs/superpowers/plans/2026-10-27-pagos-suscripciones.md` con el mensaje `Agregar el diseño y el plan de pagos al estilo vezzashop`. **No** agregar `docs/superpowers/plans/2026-10-01-portal-clientes-fase-2-servicios.md` ni `.superpowers/`, `TIENDAS/`, `videos/`.
- [ ] **Step 2: Escribir los tests que fallan** en `MigracionPagosTest` (conservar los que siguen valiendo, reescribir el resto):
  - `test_las_columnas_nuevas_existen`: consulta `information_schema` por cada columna del bloque Interfaces.
  - `test_ya_no_existen_los_links_ni_los_avisos`: `cobros` sin `periodo`/`mp_preference_id`/`link_pago`; sin tabla `avisos_pago`; `mp_notificaciones` sin `cobro_id`/`estado_pago`.
  - `test_la_modalidad_solo_admite_debito_y_transferencia`: `COLUMN_TYPE` de `modalidad` es exactamente `enum('debito','transferencia')`.
  - `test_un_pago_de_mercado_pago_no_se_registra_dos_veces`: dos cobros con el mismo `mp_payment_id` lanzan `PDOException`; dos con `mp_payment_id` nulo entran.
  - `test_un_preapproval_no_se_repite`: dos suscripciones con el mismo `mp_preapproval_id` lanzan `PDOException`.
  - `test_las_suscripciones_existentes_copian_monto_y_moneda_del_plan`: se ejecuta el `UPDATE` de la migración sobre una fila nueva y `monto`/`moneda` quedan los del plan.
  - `test_borrar_una_suscripcion_deja_el_cobro`: el cobro queda con `suscripcion_cliente_id` nulo.
- [ ] **Step 3: Correr y ver fallar.** Run: `php vendor/bin/phpunit tests/MigracionPagosTest.php`. Expected: FAIL (`link_pago` y `periodo` siguen existiendo, `modalidad` tiene `link`).
- [ ] **Step 4: Reescribir `003_pagos.sql`** según Interfaces, con el mismo estilo de comentarios que tiene hoy. Borrar los cinco archivos de la lista **Delete**. Nota: `api/mp-webhook.php` y `pagos_mp.php` quedan apuntando a funciones borradas hasta la Task 6 (no hay tests de ellos en el medio); la rama no se publica hasta el final.
- [ ] **Step 5: Dejar verde `SuscripcionesClienteTest`** con los cambios mínimos que exige el esquema nuevo (la Task 2 agrega los tests del comportamiento nuevo): borrar `test_debito_y_link_exigen_moneda_ars`, `test_debito_y_link_exigen_que_el_cliente_tenga_mail` y `test_cambiar_a_un_plan_en_otra_moneda_respeta_la_modalidad_de_cobro`; en `test_validar_modalidad_directo` dejar solo `'debito'` en los arreglos `['debito', 'link']`; en `test_actualizar_no_cambia_cliente_modalidad_pausa_ni_estado` usar `'debito'` donde hoy dice `'link'`; en `test_borrar_una_suscripcion_deja_sus_cobros` insertar el cobro sin `periodo`.
- [ ] **Step 6: Correr la suite completa.** Run: `php vendor/bin/phpunit`. Expected: `OK` (con menos tests que la línea base, porque se borraron cinco archivos).
- [ ] **Step 7: Commit** `Simplificar la migración de pagos y quitar el camino de los links`.

---

### Task 2: Suscripciones del cliente — modalidades, estado calculado y reglas de edición

**Files:**
- Modify: `admin/includes/repos/suscripciones_cliente.php`, `tests/SuscripcionesClienteTest.php`

**Interfaces:**
- Consumes: `hoy()`, `dias_entre()` (`clock.php`), `HttpError`, `crud_*`, `q_*`.
- Produces:
  - `const MODALIDADES = ['debito', 'transferencia']`, `const COBRO_DIAS_POR_VENCER = 7`.
  - `suscripcion_estado_cobro(?string $fechaProximoCobro, ?string $hoy = null): array{estado: 'al_dia'|'por_vencer'|'vencido'|'sin_fecha', dias: ?int}` (`dias = dias_entre(hoy, fecha)`; `<0` vencido, `0..7` por vencer, `>7` al día, fecha nula `sin_fecha`). Función pura.
  - `suscripciones_cliente_list` y `suscripciones_cliente_get` agregan a cada fila `cobro_estado` y `cobro_dias` (de la función anterior).
  - `suscripcion_validar_modalidad(array $suscripcion, string $modalidad): void`: `debito` exige moneda `ARS` y mail del cliente; `transferencia` siempre pasa; otro valor da 422 en `modalidad`.
  - `suscripciones_cliente_update`: el monto **no** se edita por acá (solo por `suscripcion_cliente_ajustar_precio`, Task 7); cambiar de plan conserva `monto` y `moneda` de la suscripción, y un plan de otra moneda da 422 en `plan_id`.
  - `suscripciones_cliente_delete`: 409 si `mp_preapproval_id` no es nulo y `mp_preapproval_estado` no es `cancelada`.

- [ ] **Step 1: Escribir los tests que fallan** (en `SuscripcionesClienteTest`; los casos de `link` ya los sacó la Task 1):
  - `test_estado_cobro_en_los_bordes`: `dias` -1 `vencido`, 0 `por_vencer`, 7 `por_vencer`, 8 `al_dia`, fecha nula `sin_fecha` (con `$hoy` fijo).
  - `test_listar_trae_el_estado_calculado`: una suscripción con vencimiento ayer trae `cobro_estado = 'vencido'` y `cobro_dias = -1`.
  - `test_el_debito_exige_ars_y_mail`: plan USD o cliente sin mail → 422 en `modalidad` al llamar `suscripcion_validar_modalidad($s, 'debito')`.
  - `test_la_modalidad_link_ya_no_existe`: crear con `modalidad = 'link'` → 422 en `modalidad`.
  - `test_editar_no_cambia_el_monto`: `update` con `monto` distinto deja el monto original.
  - `test_cambiar_de_plan_conserva_monto_y_moneda` y `test_cambiar_a_un_plan_de_otra_moneda_da_422`.
  - `test_no_se_borra_con_el_debito_en_curso`: 409 con estado de preapproval `pendiente`, `autorizada` y `pausada`; borra bien con `cancelada` o sin preapproval.
  - `test_listar_filtra_por_estado_cancelada` (caso positivo) y `test_borrar_una_suscripcion_deja_sus_cobros` (sin `periodo`).
- [ ] **Step 2: Correr y ver fallar.** Run: `php vendor/bin/phpunit tests/SuscripcionesClienteTest.php`. Expected: FAIL.
- [ ] **Step 3: Implementar** lo de Interfaces en `suscripciones_cliente.php`. El schema de update sale de `suscripciones_cliente_schema()` quitando `cliente_id`, `modalidad` y `monto`.
- [ ] **Step 4: Correr los tests y la suite.** Run: `php vendor/bin/phpunit`. Expected: `OK`.
- [ ] **Step 5: Commit** `Calcular el estado de cobro de las suscripciones y simplificar sus modalidades`.

---

### Task 3: Cliente de Mercado Pago sin preferencias

**Files:**
- Modify: `admin/includes/mercadopago.php`, `tests/MercadoPagoTest.php`

**Interfaces:**
- Produces:
  - Se **quitan** `mp_crear_preferencia` y `mp_invalidar_preferencia` y sus tests. Se conservan `mp_request`, `mp_obtener_pago`, `mp_buscar_pagos`, `mp_obtener_preapproval`, `mp_actualizar_preapproval`, `mp_pausar_preapproval`, `mp_reanudar_preapproval`, `mp_cancelar_preapproval`, `mp_firma_valida`, `mp_obtener_cobro_recurrente`.
  - `mp_crear_preapproval(array $suscripcion, string $emailCliente, ?string $inicio = null): array{id: string, init_point: string}`: sin `$inicio` no manda `start_date` (Mercado Pago cobra al autorizar); `back_url` es `portal_url('/')`; `external_reference` es `'susc-{id}'`.
  - `mp_inicio_iso(string $fecha): string`: `'2026-12-05'` → `'2026-12-05T12:00:00.000-03:00'`.
  - `mp_configurado(): bool`: `true` si hay `MP_ACCESS_TOKEN` o un transporte de prueba. Es lo que enciende o apaga el débito.

- [ ] **Step 1: Escribir los tests que fallan:** `test_el_preapproval_sin_inicio_no_manda_start_date`; `test_el_preapproval_con_inicio_lo_manda_tal_cual`; `test_la_back_url_es_la_home` (`$body['back_url'] === portal_url('/')`); `test_mp_inicio_iso`; `test_mp_configurado` (falso sin token ni transporte, verdadero con cualquiera de los dos). Quitar los tests de preferencias.
- [ ] **Step 2: Correr y ver fallar.** Run: `php vendor/bin/phpunit tests/MercadoPagoTest.php`. Expected: FAIL.
- [ ] **Step 3: Implementar** los cambios de Interfaces. Mantener los comentarios "CONFIRMADO / UNCONFIRMED" de las funciones que quedan.
- [ ] **Step 4: Correr los tests y la suite.** Expected: `OK`.
- [ ] **Step 5: Commit** `Quitar las preferencias de pago del cliente de Mercado Pago`.

---

### Task 4: Registrar pagos y adelantar el "pago hasta"

**Files:**
- Create: `admin/includes/repos/suscripciones_pagos.php`, `tests/SuscripcionesPagosTest.php`
- Modify: `api/suscripciones-cliente.php`, `.htaccess`

**Interfaces:**
- Consumes: `suscripciones_cliente_get` (Task 2), `cobros_get`, `crud_*`, `q_*`, `tx`, `validate`, `hoy()`.
- Produces:
  - `sumar_meses(string $fecha, int $meses): string`: suma meses calendario recortando el día **una sola vez** al último del mes destino (31 ene + 1 = 28 feb; 31 ene + 2 = 31 mar).
  - `cobro_concepto_pago(string $plan, string $fecha, int $meses): string`: `"{plan} · noviembre 2026"` con un mes; `"{plan} · 3 meses desde noviembre 2026"` con más. Mes en minúscula y en español.
  - `suscripcion_registrar_pago(int $suscripcionId, array $pago): array{resultado: 'registrado'|'repetido', cobro_id: int, fecha_proximo_cobro: string}`. `$pago`: `meses` (int), `monto` (string), `moneda` (string), `metodo_pago` (string), `mp_payment_id` (?string), `avance` (`'max_hoy'` o `'fecha'`). Dentro de `tx`: **primera sentencia** `SELECT ... FROM suscripciones_cliente WHERE id = ? FOR UPDATE`; si hay `mp_payment_id`, una lectura **común** (sin `FOR UPDATE` ni `LOCK IN SHARE MODE`, que tomarían un candado de intervalo sobre la clave única y dos pagos distintos podrían bloquearse) del cobro con ese id: si existe, `'repetido'` sin tocar nada. Si no, inserta el cobro `pagado` (`cliente_id`, `concepto`, `monto`, `moneda`, `fecha_vencimiento` = la `fecha_proximo_cobro` de antes o hoy si es nula, `fecha_pago` = hoy, `metodo_pago`, `suscripcion_cliente_id`, `mp_payment_id`) y avanza la fecha: `'max_hoy'` → `sumar_meses(max(fecha, hoy), meses)`; `'fecha'` → `sumar_meses(fecha, meses)` (fecha nula usa hoy).
  - `suscripcion_pago_manual(int $suscripcionId, array $input): array{cobro: array, suscripcion: array}`: valida `meses` (1 a 24, por defecto 1), `monto` (por defecto `monto × meses`), `metodo_pago` (máx. 100, por defecto `Transferencia`); 409 si la suscripción está cancelada; llama con `avance = 'max_hoy'`.
  - API: `POST /api/suscripciones-cliente/{id}/pagar` responde 201 con `data`. `api/suscripciones-cliente.php` pasa a despachar `?accion=` con un mapa `nombre => [función, status]` (otras tareas agregan entradas) y 405 si no es POST; sin `accion` sigue con `api_resource`. `.htaccess`: regla `RewriteRule ^api/suscripciones-cliente/([0-9]+)/([a-z-]+)$ api/suscripciones-cliente.php?id=$1&accion=$2 [L,QSA]` **antes** de `^api/([a-z-]+)/([0-9]+)$`.

- [ ] **Step 1: Escribir los tests que fallan** (`SuscripcionesPagosTest`, con `clock_set` para fijar la fecha):
  - `test_registrar_un_pago_crea_un_cobro_pagado_y_adelanta_un_mes`: cobro con `estado = 'pagado'`, `fecha_pago = hoy()`, `suscripcion_cliente_id`, concepto con plan y mes; `fecha_proximo_cobro` = +1 mes.
  - `test_el_monto_por_defecto_es_monto_por_meses` y `test_se_pueden_registrar_varios_meses` (3 meses → +3 y concepto "3 meses desde …").
  - `test_el_pago_manual_avanza_desde_la_fecha_si_no_vencio_y_desde_hoy_si_vencio`.
  - `test_el_pago_de_debito_avanza_siempre_un_mes_desde_la_fecha`: con la fecha 3 días vencida, la nueva es fecha + 1 mes (no hoy + 1 mes).
  - `test_fin_de_mes`: 31 ene → 28 feb, 31 ene + 2 → 31 mar, 31 mar + 1 → 30 abr.
  - `test_el_mismo_mp_payment_id_no_suma_dos_veces`: la segunda llamada devuelve `'repetido'`, un solo cobro, la fecha avanzó una sola vez.
  - `test_no_se_registra_a_mano_en_una_suscripcion_cancelada` (409) y `test_los_meses_y_el_monto_se_validan` (0 y 25 meses, monto negativo → 422).
  - `test_el_cobro_registrado_aparece_en_la_lista_de_pagados`: `cobros_list(['estado' => 'pagado'])` lo trae con `cliente_nombre`.
- [ ] **Step 2: Correr y ver fallar.** Run: `php vendor/bin/phpunit tests/SuscripcionesPagosTest.php`. Expected: FAIL (funciones inexistentes).
- [ ] **Step 3: Implementar** `suscripciones_pagos.php`, el despachador de acciones y la regla del `.htaccess`.
- [ ] **Step 4: Correr los tests y la suite.** Expected: `OK`.
- [ ] **Step 5: Commit** `Registrar los pagos de las suscripciones y adelantar el pago hasta`.

---

### Task 5: Ciclo de vida — pasar a débito, pausar, cancelar y reactivar

**Files:**
- Create: `admin/includes/repos/suscripciones_ciclo.php`, `tests/SuscripcionesCicloTest.php`
- Modify: `api/suscripciones-cliente.php`

**Interfaces:**
- Consumes: `mp_*` y `mp_configurado` (Task 3), `suscripcion_validar_modalidad` (Task 2), `mail_enviar`, `portal_url`.
- Produces:
  - `fecha_larga_es(string $fecha): string` (`'2026-11-25'` → `'25 de noviembre de 2026'`).
  - `suscripcion_pasar_a_debito(int $id, ?string $inicio = null): array`: 409 si `!mp_configurado()` ("El débito automático no está configurado") o si ya hay un débito `pendiente`/`autorizada`/`pausada`; valida con `suscripcion_validar_modalidad($s, 'debito')`. `$inicio` nulo usa `fecha_proximo_cobro` si es posterior a hoy; si no, sin fecha de inicio. **Primero** `mp_crear_preapproval` (cualquier `RuntimeException` → `HttpError 502` "No pudimos hablar con Mercado Pago", sin cambios locales); **después** guarda `mp_preapproval_id`, `mp_init_point` y `mp_preapproval_estado = 'pendiente'`. La `modalidad` **no cambia** hasta la autorización.
  - `suscripcion_cliente_pausar(int $id, bool $pausada): array`: con débito `autorizada` o `pausada` llama primero a `mp_pausar_preapproval` / `mp_reanudar_preapproval` y recién después guarda `pausada` y `mp_preapproval_estado`; sin débito solo cambia `pausada`.
  - `suscripcion_cliente_cancelar(int $id): array{suscripcion: array, aviso: string}`: con débito en curso llama primero a `mp_cancelar_preapproval`; luego `estado = 'cancelada'`, `fecha_baja = hoy()`, `pausada = 0`, `modalidad = 'transferencia'` y `mp_preapproval_estado = 'cancelada'` si había débito; luego `suscripcion_avisar_baja($id, 'baja')`. 409 si ya está cancelada.
  - `suscripcion_cliente_reactivar(int $id): array`: 409 si no está cancelada; deja `estado = 'activa'`, `fecha_baja` nula, `aviso_baja_enviado_en` nulo; si tenía débito (`mp_preapproval_id` no nulo) crea uno nuevo con `suscripcion_pasar_a_debito` (que reemplaza `mp_preapproval_id` y deja el link nuevo en `mp_init_point`), y si Mercado Pago falla no se reactiva.
  - `suscripcion_avisar_baja(int $id, string $motivo): string` (`$motivo` es `'baja'` o `'debito'`): reclama `aviso_baja_enviado_en` con `UPDATE ... WHERE aviso_baja_enviado_en IS NULL` (si no actualiza fila → `'ya_enviado'`); sin mail del cliente → `'sin_mail'` (sin reclamar); manda `mail_enviar('suscripcion_cancelada', $email, ['nombre', 'plan', 'motivo', 'acceso_hasta', 'vencida'])` donde `acceso_hasta` es `fecha_larga_es(fecha_proximo_cobro)` si esa fecha es hoy o futura (si no, `''` y `vencida = true`); si el mail falla **libera** el reclamo y devuelve `'no_enviado'`; si sale, `'enviado'`.
  - API (entradas del mapa de acciones): `debito` (cuerpo opcional `{inicio}`), `pausar`, `reanudar`, `cancelar`, `reactivar`; todas POST y 200.

- [ ] **Step 1: Escribir los tests que fallan** (`SuscripcionesCicloTest`, con `MercadoPago::$transporte` que registra `(método, ruta, cuerpo)` y `Mail::$transporte` que registra el payload; `MP_ACCESS_TOKEN` no hace falta con transporte):
  - `test_pasar_a_debito_crea_el_preapproval_con_inicio_y_no_cambia_la_modalidad`: `POST /preapproval` con `start_date` si la fecha es futura y sin `start_date` si ya pasó; se guardan id, link y estado `pendiente`; `modalidad` sigue `transferencia`.
  - `test_pasar_a_debito_en_usd_o_sin_mail_da_422`, `test_con_un_debito_en_curso_da_409`, `test_sin_mercado_pago_configurado_da_409`.
  - `test_si_mercado_pago_falla_al_pasar_a_debito_no_se_guarda_nada` (502 y la fila igual que antes).
  - `test_pausar_y_reanudar_sincronizan_el_preapproval` (`PUT` con `paused` y `authorized`) y `test_si_mercado_pago_falla_al_pausar_no_cambia_el_estado_local`.
  - `test_cancelar_cancela_en_mercado_pago_marca_baja_y_vuelve_a_transferencia`: `PUT` con `cancelled`, `estado = 'cancelada'`, `fecha_baja = hoy()`, `modalidad = 'transferencia'`, un mail `suscripcion_cancelada` con `acceso_hasta` en texto largo.
  - `test_si_mercado_pago_falla_al_cancelar_no_cambia_nada`.
  - `test_el_aviso_de_baja_sale_una_sola_vez`: segunda llamada a `suscripcion_avisar_baja` → `'ya_enviado'` y un solo mail.
  - `test_si_el_mail_falla_se_libera_el_reclamo_y_se_puede_reintentar` y `test_cliente_sin_mail_devuelve_sin_mail_y_cancela_igual`.
  - `test_cancelar_con_fecha_vencida_manda_vencida_true_y_acceso_hasta_vacio`.
  - `test_reactivar_sin_debito_vuelve_a_activa_y_limpia_el_aviso`; `test_reactivar_con_debito_crea_un_preapproval_nuevo`; `test_reactivar_una_suscripcion_activa_da_409`.
- [ ] **Step 2: Correr y ver fallar.** Run: `php vendor/bin/phpunit tests/SuscripcionesCicloTest.php`. Expected: FAIL.
- [ ] **Step 3: Implementar** `suscripciones_ciclo.php` y las entradas de acciones en el endpoint.
- [ ] **Step 4: Correr los tests y la suite.** Expected: `OK`.
- [ ] **Step 5: Commit** `Agregar el débito, la pausa, la baja y la reactivación de suscripciones`.

---

### Task 6: Webhook, registro de cobros de Mercado Pago y Sincronizar

**Files:**
- Modify (reescribir): `admin/includes/repos/pagos_mp.php`, `tests/PagosMpTest.php`, `api/mp-webhook.php`, `api/suscripciones-cliente.php`

**Interfaces:**
- Consumes: `mp_firma_valida`, `mp_obtener_pago`, `mp_obtener_cobro_recurrente`, `mp_obtener_preapproval`, `mp_buscar_pagos` (Task 3), `suscripcion_registrar_pago` (Task 4), `suscripcion_avisar_baja` (Task 5).
- Produces:
  - Se conservan `MpConsultaFallida`, `mp_consultar`, `mp_pago_normalizar`, `mp_recurrente_normalizar`, `mp_preapproval_normalizar`, `mp_consultar_pago`, `mp_notificacion_registrar_error`. Se **quitan** `mp_referencia_interpretar` para `cobro-`, `cobro_de_pago_mp`, `cobro_con_pago_mp`, `cobro_registrar_pago_mp*`, `pagos_reconciliar` y `suscripcion_confirmar_preapproval`.
  - `mp_estado_preapproval(string $status): ?string`: `pending`→`pendiente`, `authorized`→`autorizada`, `paused`→`pausada`, `cancelled` y `canceled`→`cancelada`, otro → `null`.
  - `mp_webhook_procesar(array $headers, array $query, string $cuerpoCrudo): array{http: int, resultado: string}` (mismo contrato que hoy): 401 sin firma válida y sin tocar nada; `subscription_preapproval` y `preapproval` → `mp_procesar_preapproval`; `subscription_authorized_payment` → `mp_procesar_cobro_recurrente`; **cualquier otro tema** (incluido `payment`) → 200 `'ignorado'`; 500 solo si falla la consulta a Mercado Pago. Guarda cada aviso en `mp_notificaciones` y actualiza `sin_cobro`, `error`, `procesada_en`.
  - `mp_pago_registrar(array $pago): array{resultado: 'registrado'|'repetido'|'no_aprobado'|'sin_cobro', cobro_id: ?int, sin_cobro: bool, error: ?string}`: `$pago` ya normalizado. Solo `status = 'approved'` avanza. Resuelve la suscripción por `preapproval_id` (`mp_preapproval_id`) o, si no, por la referencia `susc-{id}`; sin suscripción conocida, sin monto o con moneda distinta de la de la suscripción → `'sin_cobro'` con un `error` descriptivo. Una suscripción cancelada o pausada **igual se registra** (el dinero entró). Llama a `suscripcion_registrar_pago` con `avance = 'fecha'`, `meses = 1`, `metodo_pago = 'Mercado Pago · débito'`, `monto` y `mp_payment_id` del pago.
  - `mp_procesar_preapproval(string $id): array`: sin suscripción con ese preapproval → `sin_cobro = true` con error; si no, aplica `mp_estado_preapproval`: `autorizada` → `mp_preapproval_estado = 'autorizada'`, `modalidad = 'debito'`, `pausada = 0`; `pausada` → `pausada = 1`; `cancelada` estando antes `autorizada`/`pausada` → `modalidad = 'transferencia'`, `mp_preapproval_estado = 'cancelada'`, `pausada = 0` y `suscripcion_avisar_baja($id, 'debito')` (la suscripción **sigue activa**); `cancelada` estando `pendiente` → solo el estado, sin mail; `pendiente` nunca retrocede un estado posterior.
  - `suscripcion_sincronizar(int $id): array{estado: ?string, pagos: int}`: 409 si no tiene `mp_preapproval_id`; lee el preapproval y aplica lo de arriba; busca con `mp_buscar_pagos('susc-' . $id)` y registra los aprobados del más viejo al más nuevo con `mp_pago_registrar`; `pagos` cuenta los `'registrado'`. Cualquier `RuntimeException` de Mercado Pago → `HttpError 502`. API: acción `sincronizar` (POST, 200).
  - `api/mp-webhook.php`: queda solo la verificación de método y la llamada a `mp_webhook_procesar`; se quita el despacho de avisos y `responder_y_seguir()`.

- [ ] **Step 1: Escribir los tests que fallan** reescribiendo `PagosMpTest.php`. Conservar de la versión anterior (`git show 1faf5db:tests/PagosMpTest.php`) el `setUp`/`tearDown` y los helpers de firma y de notificación (`firmar`, `notificar`, `notificaciones`, `ofrecerPago`); el resto se escribe de nuevo:
  - `test_firma_invalida_responde_401_y_no_cambia_nada`.
  - `test_el_cobro_aprobado_de_un_debito_crea_el_cobro_pagado_y_adelanta_un_mes`: webhook `subscription_authorized_payment`, `cobros.metodo_pago = 'Mercado Pago · débito'`, `mp_payment_id` guardado, `fecha_proximo_cobro` + 1 mes desde la fecha.
  - `test_el_mismo_aviso_cinco_veces_suma_una_sola_vez` (un cobro, un mes).
  - `test_un_pago_ya_registrado_por_otro_camino_no_se_suma_de_nuevo`: se registra con `mp_pago_registrar` y se notifica después por webhook → `'repetido'`.
  - `test_un_pago_de_otra_moneda_o_de_un_preapproval_desconocido_queda_sin_cobro` (200, `sin_cobro = 1`, `error` descriptivo, ningún cobro nuevo).
  - `test_un_pago_rechazado_o_pendiente_no_cambia_nada`.
  - `test_un_cobro_aprobado_de_una_suscripcion_cancelada_igual_se_registra`.
  - `test_si_mercado_pago_no_responde_devuelve_500_y_guarda_el_error`.
  - `test_la_autorizacion_pasa_la_suscripcion_a_debito` y `test_un_aviso_viejo_de_pendiente_no_retrocede_una_suscripcion_autorizada`.
  - `test_la_cancelacion_externa_vuelve_a_transferencia_manda_un_solo_mail_y_deja_la_suscripcion_activa` (el webhook dos veces → un mail).
  - `test_la_pausa_externa_marca_pausada`; `test_los_demas_temas_se_ignoran_con_200` (incluye `payment`).
  - `test_sincronizar_registra_los_pagos_que_faltan_y_es_idempotente` (dos corridas, un solo cobro por pago); `test_sincronizar_sin_debito_da_409`; `test_sincronizar_con_mercado_pago_caido_da_502`.
- [ ] **Step 2: Correr y ver fallar.** Run: `php vendor/bin/phpunit tests/PagosMpTest.php`. Expected: FAIL.
- [ ] **Step 3: Reescribir `pagos_mp.php`** según Interfaces (recuperar de `git show 1faf5db:admin/includes/repos/pagos_mp.php` lo que se conserva), simplificar `api/mp-webhook.php` y agregar la acción `sincronizar`.
- [ ] **Step 4: Correr los tests y la suite.** Expected: `OK`.
- [ ] **Step 5: Commit** `Validar los pagos de Mercado Pago con el webhook de suscripciones y sincronizar a pedido`.

---

### Task 7: Ajustar precio

**Files:**
- Modify: `admin/includes/repos/suscripciones_ciclo.php`, `tests/SuscripcionesCicloTest.php`, `api/suscripciones-cliente.php`

**Interfaces:**
- Consumes: `mp_actualizar_preapproval` (Task 3), `suscripcion_avisar_baja`-style envío de mails, `fecha_larga_es` (Task 5).
- Produces:
  - `monto_formato_ar(string|float $monto): string` (`85000` → `$85.000`; con centavos distintos de cero, `$85.000,50`). Recuperar la versión anterior de `git show 1faf5db:admin/includes/repos/avisos_pago.php`.
  - `suscripcion_cliente_ajustar_precio(int $id, array $input): array{suscripcion: array, aviso: string}`: `input.monto` obligatorio, mayor que cero y distinto del actual (422 en `monto`); 409 si está cancelada. Con débito `autorizada` o `pausada`, **primero** `mp_actualizar_preapproval($mpId, ['auto_recurring' => ['transaction_amount' => float, 'currency_id' => moneda]])` y, si la respuesta trae otro `transaction_amount`, `HttpError 502` "Mercado Pago no aplicó el monto nuevo" sin cambiar nada; si la llamada falla, 502. Después, en una transacción: guarda el monto y agrega la fila a `suscripciones_precio_historial`. Fuera de la transacción manda `mail_enviar('precio_cambiado', $email, ['nombre', 'plan', 'monto_anterior', 'monto_nuevo', 'desde'])` (montos con `monto_formato_ar`, `desde` = `fecha_larga_es(fecha_proximo_cobro)`) y marca `avisado_en` si salió. `aviso` es `'enviado'`, `'no_enviado'` o `'sin_mail'`; el mail que no sale no deshace el cambio.
  - API: acción `precio` (POST, 200).

- [ ] **Step 1: Escribir los tests que fallan** (en `SuscripcionesCicloTest`): `test_ajustar_el_precio_guarda_el_monto_el_historial_y_manda_el_mail` (payload con `$85.000` → `$95.000`); `test_con_debito_autorizado_actualiza_primero_el_monto_en_mercado_pago` (el `PUT` lleva monto y moneda); `test_si_mercado_pago_devuelve_otro_monto_da_502_y_no_cambia_nada`; `test_con_mercado_pago_caido_da_502_y_no_cambia_nada`; `test_monto_cero_negativo_o_igual_da_422`; `test_los_cobros_ya_registrados_no_cambian`; `test_si_el_mail_falla_el_cambio_igual_queda_y_avisado_en_sigue_nulo`; `test_sin_mail_cambia_igual_y_devuelve_sin_mail`; `test_ajustar_una_cancelada_da_409`.
- [ ] **Step 2: Correr y ver fallar.** Run: `php vendor/bin/phpunit tests/SuscripcionesCicloTest.php`. Expected: FAIL en los casos nuevos.
- [ ] **Step 3: Implementar** `suscripcion_cliente_ajustar_precio`, `monto_formato_ar` y la acción del endpoint.
- [ ] **Step 4: Correr los tests y la suite.** Expected: `OK`.
- [ ] **Step 5: Commit** `Agregar el ajuste de precio de las suscripciones con aviso al cliente`.

---

### Task 8: Workflow de n8n para los dos mails

**Files:**
- Create: `docs/n8n/vezza-avisos-de-pago.json`, `docs/n8n/README.md`, `tests/N8nWorkflowTest.php`

**Interfaces:**
- Consumes: el contrato `{plantilla, destino, datos}` y el header `X-Webhook-Secret` de `mail_enviar`; las dos plantillas `precio_cambiado` y `suscripcion_cancelada` con los `datos` de las Tasks 5 y 7.
- Produces: un workflow importable "VEZZA · Avisos de pago": Webhook que valida el secreto → Switch por `plantilla` con **dos** salidas → un nodo de mail SMTP de `info@vezzadev.com` por plantilla → Respond to Webhook 200. `suscripcion_cancelada` cambia el texto según `datos.motivo` (`baja` o `debito`) y según `datos.vencida`. Textos en voseo, primera persona singular. No se crea nada en la instancia de n8n: solo el JSON y el README (cómo importar y qué credencial cargar).

- [ ] **Step 1: Escribir el test que falla** (`N8nWorkflowTest`): `test_el_json_es_valido`; `test_el_switch_cubre_las_dos_plantillas_y_nada_mas`; `test_hay_un_nodo_de_mail_por_plantilla`; `test_no_contiene_secretos` (ningún valor que parezca token; solo referencias a credenciales).
- [ ] **Step 2: Correr y ver fallar.** Run: `php vendor/bin/phpunit tests/N8nWorkflowTest.php`. Expected: FAIL (archivo inexistente).
- [ ] **Step 3: Escribir el JSON y el README.** Tomar como base el workflow de mails del portal que ya usa el mismo secreto y la misma credencial SMTP.
- [ ] **Step 4: Correr los tests y la suite.** Expected: `OK`.
- [ ] **Step 5: Commit** `Agregar el workflow de n8n para los avisos de pago`.

---

### Task 9: Lectura de "Suscripciones mensuales"

**Files:**
- Create: `admin/includes/repos/suscripciones_mensuales.php`, `tests/SuscripcionesMensualesTest.php`
- Modify: `api/suscripciones-cliente.php`, `.htaccess`

**Interfaces:**
- Consumes: `suscripciones_cliente_list` y `suscripcion_estado_cobro` (Task 2), `mp_configurado` (Task 3), `monto_formato_ar` (Task 7).
- Produces:
  - `whatsapp_telefono_normalizar(string $telefono): ?string`: deja solo dígitos con prefijo `54` y `9` para móviles argentinos (`'11 5555-1234'` → `'5491155551234'`, `'+54 9 11 5555 1234'` → `'5491155551234'`); `null` si no parece un teléfono (letras o muy corto).
  - `suscripcion_whatsapp_url(array $suscripcion, array $cliente): ?string`: `https://wa.me/{telefono}?text={mensaje con rawurlencode}`; mensaje en voseo, primera persona singular ("Hola {nombre}, te escribo por tu pago mensual de {plan} ({monto}), que venció el {fecha corta}. ¿Lo pudiste hacer? Gracias."); `null` sin teléfono válido.
  - `suscripciones_mensuales(array $get): array{resumen: array{ingreso_mensual: string, otras_monedas: bool, al_dia: int, por_vencer: int, vencidas: int}, debito_disponible: bool, filas: list<array>}`. Filtro `cliente_id`. Cada fila: todo lo de `suscripciones_cliente_list` más `ultimo_pago` (`{fecha_pago, monto, moneda, metodo_pago}` o `null`), `debito_pendiente` (bool: `mp_preapproval_estado = 'pendiente'`) y `whatsapp_url` (solo si `cobro_estado = 'vencido'`, la suscripción está activa y no pausada, y hay teléfono válido). El resumen cuenta **solo** suscripciones `activa` y no pausadas; `ingreso_mensual` suma solo las de `ARS` (formateado) y `otras_monedas` avisa si hay otras. Orden: vencidas primero, después por fecha.
  - Rutas: `GET /api/suscripciones-mensuales` (regla `RewriteRule ^api/suscripciones-mensuales$ api/suscripciones-cliente.php?accion=mensuales [L,QSA]` antes de las genéricas; `accion=mensuales` por GET devuelve `data`).

- [ ] **Step 1: Escribir los tests que fallan:** `test_el_ingreso_mensual_suma_solo_las_activas_no_pausadas_en_ars` (una en USD no suma y `otras_monedas = true`); `test_clasifica_al_dia_por_vencer_y_vencido`; `test_pausadas_y_canceladas_no_cuentan_en_el_resumen_pero_aparecen_en_las_filas`; `test_las_vencidas_van_primero`; `test_filtra_por_cliente`; `test_trae_el_ultimo_pago_y_el_debito_pendiente`; `test_el_link_de_whatsapp_solo_en_vencidas_con_telefono_valido` (mensaje codificado, sin teléfono → `null`); `test_normalizar_telefonos` (los dos ejemplos y un valor con letras → `null`); `test_debito_disponible_sigue_a_mp_configurado`.
- [ ] **Step 2: Correr y ver fallar.** Run: `php vendor/bin/phpunit tests/SuscripcionesMensualesTest.php`. Expected: FAIL.
- [ ] **Step 3: Implementar** el repo, la ruta del endpoint y la regla del `.htaccess`.
- [ ] **Step 4: Correr los tests y la suite.** Expected: `OK`.
- [ ] **Step 5: Commit** `Agregar la lectura de suscripciones mensuales para el admin`.

---

### Task 10: Pestaña "Suscripciones mensuales" y pantalla de Planes

**Files:**
- Create: `admin/planes.php`, `admin/assets/planes.js`, `admin/assets/mod-suscripciones.js`
- Modify: `admin/cobros.php`, `admin/assets/cobros.js`, `admin/assets/admin.js`, `admin/assets/admin.css` (solo si hace falta), `admin/includes/layout.php` (`PANEL_ASSET_V` de `'4'` a `'5'`), `.htaccess` (agregar `planes` a la lista del `RewriteRule ^admin/(clientes|procesos|cobros|...)$`), `scripts/verificar-panel.sh` (agregar `esperar 302 /admin/planes`)

**Interfaces:**
- Consumes: `/api/suscripciones-mensuales` (Task 9), `/api/suscripciones-cliente` y sus acciones (Tasks 4 a 7), `/api/planes`, y los helpers `Panel.*` y `el` de `admin.js`.
- Produces (en `mod-suscripciones.js`, objeto global `Suscripciones`, mismo estilo que `Cobros`):
  - `Suscripciones.item(s, recargar, debitoDisponible): HTMLElement`: un `.item` con título `Panel.fmtMonto(s.monto, s.moneda)`, insignias (estado de cobro, modalidad, "Débito pendiente" si `debito_pendiente`, "Pausada" o "Cancelada"; todas con ícono y texto), una línea con cliente, plan y "Pago hasta {fecha}", otra con el último pago, el botón principal visible (**Registrar pago** si está al día o por vencer, **Avisar por WhatsApp** si hay `whatsapp_url`, **Reactivar** si está cancelada) y un `<select class="filtro">` "Más acciones…".
  - `Suscripciones.acciones(s, debitoDisponible): Array<[valor, texto]>`: Registrar pago, Pasar a débito (solo con `debitoDisponible`), Ajustar precio, Pausar/Reanudar, Sincronizar (solo con débito), Cancelar, Editar fechas.
  - `Suscripciones.ejecutar(accion, s, recargar)`: abre el formulario o la confirmación que corresponda con `Panel.modalForm`/`Panel.confirmar` y llama a `Panel.post('/api/suscripciones-cliente/{id}/{accion}', …)`; al volver de `debito` muestra el link de alta en un modal con botón **Copiar** (`navigator.clipboard.writeText`); al volver de `cancelar` y `precio` informa el `aviso` (`no_enviado` y `sin_mail` se muestran como advertencia, no como error); los errores van a `Panel.manejarError`.
  - `Suscripciones.nueva(valores)`: formulario (cliente, plan, monto opcional, fecha de inicio, próximo cobro) con `POST /api/suscripciones-cliente`.
  - En `admin.js`: `ETQ.estadoSuscripcion = {al_dia: 'Al día', por_vencer: 'Por vencer', vencido: 'Vencido', sin_fecha: 'Sin fecha'}` y `VARIANTE`: `al_dia: 'ok'`, `por_vencer: 'warn'`.
  - `cobros.js`: quinta entrada de `TABS` (`id: 'suscripciones'`, "Suscripciones mensuales") con el resumen en `.stats` (ingreso mensual, al día, por vencer, vencidas; una nota si `otras_monedas`), la lista, **+ Nueva suscripción** y un enlace a **Planes**. Respeta el filtro de cliente existente.
  - `planes.php`/`planes.js`: `layout_start('Planes', 'cobros', ['planes.js'])`; lista de planes con **+ Nuevo plan** y edición con `Panel.modalForm` (nombre, descripción, monto, moneda, frecuencia, activo, orden); borrar un plan con suscripciones muestra el 409 del servidor.

- [ ] **Step 1: Crear `planes.php` y `planes.js`**, la ruta del `.htaccess` y la línea de `verificar-panel.sh`.
- [ ] **Step 2: Escribir `mod-suscripciones.js`** con las cuatro funciones de Interfaces. Todo texto dinámico con `el`/`textContent`.
- [ ] **Step 3: Agregar la pestaña** en `cobros.js`, cargar `mod-suscripciones.js` desde `admin/cobros.php` y las etiquetas en `admin.js`.
- [ ] **Step 4: Subir `PANEL_ASSET_V` a `'5'`** y agregar `.monto` (Sora, cifras tabulares) o el estilo del ícono en insignia **solo si** hacen falta.
- [ ] **Step 5: Verificar.** Run: `node --check admin/assets/mod-suscripciones.js` y lo mismo con `planes.js` y `cobros.js`; `php -l admin/planes.php admin/cobros.php`; `php vendor/bin/phpunit`. Expected: sin errores y `OK`. La revisión visual a 360 px queda para el final (no hay navegador garantizado): en la entrega se deja anotado.
- [ ] **Step 6: Commit** `Agregar la pestaña de suscripciones mensuales y la pantalla de planes`.

---

### Task 11: Sección "Suscripción" en la ficha del cliente

**Files:**
- Modify: `admin/cliente.php`, `admin/assets/cliente.js`, `admin/assets/mod-suscripciones.js`, `admin/includes/layout.php` (`PANEL_ASSET_V` de `'5'` a `'6'`)

**Interfaces:**
- Consumes: `/api/suscripciones-mensuales?cliente_id={id}` (Task 9), `Suscripciones.acciones` y `Suscripciones.ejecutar` (Task 10).
- Produces: `Suscripciones.ficha(s, recargar, debitoDisponible): HTMLElement` en `mod-suscripciones.js`: un bloque con plan, monto, modalidad, estado de cobro, "Pago hasta", estado del débito (con el link de alta para copiar cuando está `pendiente`, usando `mp_init_point`), último pago y **todas** las acciones de `Suscripciones.acciones` como botones (no en selector). `cliente.php` agrega `<section id="suscripcion" class="card seccion" aria-live="polite"></section>` entre `acceso` y `pestanas` y carga `mod-suscripciones.js`; `cliente.js` agrega `cargarSuscripcion()` (una ficha por suscripción del cliente y **+ Nueva suscripción** con el cliente ya elegido, o un vacío "Este cliente no tiene suscripción") y la llama junto a `cargarAcceso()`.

- [ ] **Step 1: Agregar la sección y el script** en `cliente.php`.
- [ ] **Step 2: Escribir `Suscripciones.ficha`** y `cargarSuscripcion()` en `cliente.js`; las acciones recargan solo esa sección.
- [ ] **Step 3: Subir `PANEL_ASSET_V` a `'6'`.**
- [ ] **Step 4: Verificar.** Run: `node --check admin/assets/cliente.js admin/assets/mod-suscripciones.js`; `php -l admin/cliente.php`; `php vendor/bin/phpunit`. Expected: sin errores y `OK`.
- [ ] **Step 5: Commit** `Agregar la sección de suscripción a la ficha del cliente`.

---

### Task 12: Configuración, documentación de despliegue y verificación final

**Files:**
- Modify: `.env.example`, `DEPLOY.md`, `scripts/verificar-panel.sh`

**Interfaces:**
- Produces: el despliegue documentado y la lista de pruebas que solo el usuario puede hacer (necesitan credenciales de Mercado Pago).

- [ ] **Step 1: `.env.example`:** agregar `MP_ACCESS_TOKEN=` y `MP_WEBHOOK_SECRET=` con un comentario ("vacío = débito automático apagado").
- [ ] **Step 2: `DEPLOY.md`:** sección "Cobro de suscripciones (Mercado Pago)" con: crear **una aplicación propia de Mercado Pago para VEZZA** (distinta de la de vezzashop: una aplicación tiene una sola URL de webhook por modo); cargar `https://vezzadev.com/api/mp-webhook` con los eventos **Planes y suscripciones** (`subscription_preapproval` y `subscription_authorized_payment`), solo webhooks firmados; variables en el `.env` del servidor; **no hay cron**; orden de deploy: aplicar la migración `003` desde `/admin/migraciones` antes de cargar las variables; importar el workflow de n8n.
- [ ] **Step 3: `scripts/verificar-panel.sh`:** agregar `esperar 405 /api/mp-webhook` y `esperar 401 /api/suscripciones-mensuales`.
- [ ] **Step 4: Correr la suite completa y buscar restos.** Run: `php vendor/bin/phpunit` (Expected: `OK`) y `git grep -nE "link_pago|mp_preference_id|avisos_pago|'link'|pagos_reconciliar|cobros_generar" -- admin api tests db` (Expected: sin resultados fuera de comentarios históricos). Confirmar con `git diff --stat main -- index.html clientes` que **no** se tocó la landing ni el portal.
- [ ] **Step 5: Anotar en `DEPLOY.md`, bajo "Antes de cobrarle a un cliente real" (lo hace el usuario, no el builder):**
  1. Con credenciales de prueba y usuarios de prueba de Mercado Pago: crear una suscripción en ARS, **Pasar a débito**, autorizar con la tarjeta de prueba y comprobar que la suscripción pasa a débito, que llega el cobro y que "pago hasta" avanza un mes.
  2. Probar un pago rechazado y una baja desde la cuenta de Mercado Pago (llega el mail `suscripcion_cancelada` con `motivo = debito`).
  3. Confirmar lo que sigue sin verificar: `init_point` en la respuesta de creación, `cancelled` o `canceled`, el valor de reactivación, que `PUT /preapproval` acepte un cambio de monto, que `start_date` futuro difiera de verdad el primer cobro, la fórmula de la firma de suscripciones y que los pagos de un preapproval traigan `external_reference` `susc-{id}` (de eso depende **Sincronizar**).
  4. Un pago real de bajo monto (por ejemplo `$100`) con una suscripción propia antes de cargar a un cliente real; revisar a mano el admin a 360 px.
- [ ] **Step 6: Commit** `Documentar el despliegue de los pagos y la verificación de salida`.

---

## Self-review

- **Cobertura del spec:** §1 y §2 → todo el plan; §3 → Global Constraints; §4 → decidido en el spec; §5 datos → Task 1; §6.1 estado → Task 2; §6.2 alta del débito → Task 5; §6.3 webhook → Task 6; §6.4 registrar pago y §6.5 avance → Task 4; §6.6 precio → Task 7; §6.7 cancelar, reactivar y pausar → Task 5; §6.8 Sincronizar → Task 6; §6.9 borrado → Task 2; §7 pantallas → Tasks 9 a 11 (botón de WhatsApp en 9, 10 y 11); §8 n8n → Task 8; §9 configuración → Task 12; §10 pruebas → repartidas en cada tarea; §11 estado de lo construido → Tasks 1, 3 y 6; §12 riesgos → Task 12 paso 5.
- **Consistencia de nombres:** `suscripcion_estado_cobro`, `suscripcion_registrar_pago`, `suscripcion_pago_manual`, `suscripcion_pasar_a_debito`, `suscripcion_cliente_pausar|cancelar|reactivar|ajustar_precio`, `suscripcion_avisar_baja`, `suscripcion_sincronizar`, `mp_pago_registrar`, `mp_procesar_preapproval`, `mp_configurado`, `monto_formato_ar` y `fecha_larga_es` se definen en la tarea que los produce y se usan con la misma firma después. Las acciones del endpoint (`pagar`, `debito`, `pausar`, `reanudar`, `cancelar`, `reactivar`, `sincronizar`, `precio`, `mensuales`) coinciden entre las Tasks 4 a 11. Estados de cobro `al_dia|por_vencer|vencido|sin_fecha`, modalidades `debito|transferencia` y estados de preapproval `pendiente|autorizada|pausada|cancelada` coinciden con la Task 1.
- **Decisiones del plan que el spec dejaba abiertas** (para revisar): el débito avanza siempre un mes desde `fecha_proximo_cobro` y el pago manual desde `max(fecha, hoy)`; cambiar de plan conserva el monto y exige la misma moneda; una baja externa del débito deja la suscripción **activa** en transferencia (el mail es el mismo `suscripcion_cancelada`, con `motivo = debito`); el reclamo del mail de baja se libera si el mail no sale; Sincronizar busca los pagos por `external_reference = susc-{id}`.
- **Proporción:** el plan es algo más largo que el spec (cerca de 1,6 veces) porque lleva las firmas y los nombres de los tests, pero no repite ningún cuerpo de función.
- **Dependencia de entorno:** nada de lo anterior se puede correr sin MariaDB. Es el primer bloqueo real.
