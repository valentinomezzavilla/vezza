# Cobro de suscripciones al estilo vezzashop — Diseño

Fecha: 2026-11-25. Estado: aprobado por el usuario en la conversación del 2026-11-25.

**Reemplaza** las secciones de cobro del diseño `2026-10-27-pagos-suscripciones-design.md` (links mensuales, cron diario, reconciliación, avisos escalonados y pantalla Pagos del portal). De ese diseño se conserva lo que no contradice a este: planes y suscripciones por cliente con monto propio, verificación de firma del webhook, no pausar nunca un servicio por falta de pago, y el estilo visual del admin.

## 1. Intención y por qué

VEZZA cobra a sus clientes un monto mensual distinto según el servicio contratado. Hoy se marca cada cobro a mano. Se quiere que el cobro mensual sea automático cuando el cliente lo autoriza, y que el admin vea de un vistazo quién está al día, quién está por vencer y quién está vencido.

El usuario ya tiene resuelto este problema en su otro proyecto, **vezzashop** (`github.com/valentinomezzavilla/vezzashop`, Node + PostgreSQL, suscripciones de las tiendas a sus planes). Pidió que los clientes, las suscripciones mensuales y los cobros de VEZZA **funcionen igual**: más simple que lo diseñado el 27/10 y con un solo modelo mental en los dos proyectos.

El modelo de vezzashop, en cuatro líneas:
- Mercado Pago cobra solo cada mes (débito automático, `preapproval`); el sistema solo escucha.
- Cada cobro aprobado suma un mes pagado a la cuenta (`vence_en`); el estado (al día, por vencer, vencido) se **calcula** desde esa fecha. No hay cron.
- Lo que se cobra por fuera (transferencia) se registra a mano y suma meses de la misma forma.
- Los mails salen por n8n, uno por evento (cambio de precio, baja), y se mandan una sola vez.

## 2. Objetivos, no objetivos y anti-objetivos

**Objetivos**
- Débito automático de Mercado Pago como única vía de cobro automático, más registro manual para transferencias.
- Estado de cada suscripción calculado desde `fecha_proximo_cobro` ("pago hasta").
- Cada cobro aprobado o registrado crea un cobro `pagado` en `cobros`: dashboard, agenda y Clientes siguen funcionando sin cambios.
- En el admin: sección **Suscripción** en la ficha del cliente, pestaña **Suscripciones mensuales** en Cobros y pantalla de **Planes**.
- Ajuste de precio por suscripción, baja y reactivación, con los mails correspondientes.
- Sin `MP_ACCESS_TOKEN` el débito queda apagado y el resto del admin no cambia.

**No objetivos**
- Tocar la landing (`vezzadev.com`) ni el portal de clientes (`/clientes`). **Solo `/admin`.** Quedan fuera la pantalla Pagos del portal y el cambio de modalidad por el cliente.
- Link de pago mensual (Checkout Pro), cron diario, reconciliación automática y avisos escalonados antes y después del vencimiento.
- WhatsApp por API (`WHATSAPP_WEBHOOK_URL`). Solo el botón manual `wa.me`.
- Subir el precio de un plan entero con pantalla de confirmación por cliente: en VEZZA cada cliente tiene su monto, el ajuste es por suscripción.
- Pausar o cortar servicios por falta de pago, facturación electrónica, prorrateos, devoluciones, otras monedas distintas de ARS para el débito.

**Anti-objetivos (fallaría aunque funcione)**
- Dar por pagado algo que Mercado Pago no confirmó: un aviso nunca se toma como prueba, siempre se consulta la API.
- Sumar dos veces el mismo cobro (un aviso repetido, un reintento, el botón Sincronizar después del webhook).
- Que un fallo de Mercado Pago deje la suscripción local en un estado distinto del de Mercado Pago.
- Guardar datos de tarjeta, o mostrar ids o tokens de Mercado Pago fuera del admin.
- Que fallen los tests existentes del admin o del portal.

## 3. Restricciones

- PHP 8.1 plano (`platform.php = 8.1.0`), MySQL/MariaDB, JS puro, PHPUnit 10.5, sin frameworks ni SDK. Deploy por `git push` a `main` (Hostinger).
- La rama `pagos-suscripciones` nunca se publicó: la migración `003_pagos` no está en producción y **se reescribe en el lugar** (no se agrega una 004).
- Sin secretos en el repo. Variables: `MP_ACCESS_TOKEN`, `MP_WEBHOOK_SECRET` (y las de n8n que ya existen).
- Estilo del admin: solo tokens y clases de `admin/assets/admin.css`; el estado nunca va solo en color; usable a 360 px; voseo en primera persona singular; montos `$85.000`; fechas cortas.
- El JS del admin usa `textContent` o el helper `el`, nunca `innerHTML` con datos. Subir `PANEL_ASSET_V` al tocar CSS/JS.
- Los mails salen por `mail_enviar` y el webhook de n8n existente. Mercado Pago por su API HTTP; los nombres de campo se confirman contra la documentación vigente.
- Commits en español, estilo del repo, con el trailer de coautoría.

## 4. Enfoques considerados

| | Qué es | A favor | En contra |
|---|---|---|---|
| **A. Modelo de vezzashop (elegido)** | Débito más registro manual, estado calculado, sin cron ni links | Mucho más simple, un solo modelo mental, menos superficie de error con dinero | Se pierden los avisos automáticos de vencimiento y el link mensual para quien no quiere débito |
| B. Solo pantallas y textos | Dejar el motor de cobros mensuales, links y avisos; copiar solo la interfaz | No se tira trabajo hecho | Dos modelos distintos en dos proyectos; cron, reconciliación y links que mantener |
| C. Mezcla | Débito y registro manual como vezzashop, más avisos antes del vencimiento | Conserva los avisos | Obliga a mantener un cron solo para eso |

Se eligió A porque el usuario pidió explícitamente que se parezca a vezzashop, y porque el trabajo descartado (generación mensual de cobros, links, reconciliación) existe solo para cubrir una vía de pago que ya no se ofrece.

## 5. Modelo de datos (migración `003_pagos`, reescrita)

Sobre `planes`, `suscripciones_cliente` (migración 002) y `cobros` (001).

**`suscripciones_cliente` (ALTER)**
- `monto DECIMAL(12,2)` y `moneda CHAR(3)`: precio propio de la suscripción, copiado del plan al crearla y ajustable por el admin. Las existentes copian el de su plan.
- `modalidad ENUM('debito','transferencia') DEFAULT 'transferencia'`. (Sin `link`.)
- `mp_preapproval_id VARCHAR(100) NULL UNIQUE`, `mp_preapproval_estado ENUM('pendiente','autorizada','pausada','cancelada') NULL`, `mp_init_point VARCHAR(500) NULL` (para volver a mostrar el link de alta).
- `pausada TINYINT(1) DEFAULT 0`: el admin pausa el cobro (pausa también el preapproval). No tiene relación con los servicios.
- `aviso_baja_enviado_en DATETIME NULL`: garantiza que el mail de baja sale una sola vez.

**`cobros` (ALTER)**
- `suscripcion_cliente_id INT UNSIGNED NULL` (FK, `ON DELETE SET NULL`) y `mp_payment_id VARCHAR(100) NULL UNIQUE`: el cobro `pagado` que deja cada pago de Mercado Pago; la clave única es la idempotencia. Los cobros manuales existentes no cambian.
- Se **quitan** `periodo`, `mp_preference_id`, `link_pago` y la clave única por período.

**`mp_notificaciones`**: auditoría de cada aviso de Mercado Pago (`tipo`, `recurso_id`, `payload`, `recibida_en`, `procesada_en`, `sin_cobro`, `error`). Se quitan `cobro_id` y `estado_pago`, que servían al bloqueo de cambio de modalidad.

**`suscripciones_precio_historial`**: se conserva (cada ajuste, con `avisado_en`).

**`avisos_pago`**: se **elimina**. Ya no hay avisos por cron; el "una sola vez" de la baja vive en `aviso_baja_enviado_en`.

## 6. Comportamiento

### 6.1 Estado calculado
`dias = dias_entre(hoy, fecha_proximo_cobro)`. **Vencido** si `dias < 0`; **por vencer** si `0 <= dias <= 7`; **al día** si `dias > 7`. Además la suscripción puede estar **pausada**, **cancelada** o con el **débito pendiente de autorización** (se muestra como insignia aparte). Es una función pura sin acceso a la base. Un vencimiento solo avisa en pantalla: nunca pausa nada.

### 6.2 Alta del débito
El admin crea la suscripción (arranca en `transferencia`) y desde la ficha elige **Pasar a débito**: se crea el preapproval en Mercado Pago (monto y moneda de la suscripción, `start_date` = `fecha_proximo_cobro` si es futura, si no cobra al autorizar), se guarda `mp_preapproval_id`, `mp_init_point` y `mp_preapproval_estado = 'pendiente'`, y el admin copia el link para mandárselo al cliente. La modalidad **sigue siendo la anterior hasta que Mercado Pago confirma la autorización**; ahí pasa a `debito`. La `back_url` de Mercado Pago es la home `/` (sin tocarla). Exige moneda ARS y un cliente con mail.

### 6.3 Webhook (`/api/mp-webhook`)
Endpoint público, sin sesión ni CSRF. Verifica la firma (`x-signature`), responde y procesa; **toda decisión sale de consultar la API**, no del cuerpo del aviso.
- `subscription_preapproval` → sincroniza el estado de la suscripción (autorizada, pausada, cancelada), el monto y el próximo cobro. Si pasó a `cancelada` por afuera del admin y estaba autorizada, manda el mail de baja (una vez).
- `subscription_authorized_payment` → consulta el cobro (`authorized_payments`); si está aprobado, resuelve la suscripción por su `preapproval_id`, crea el cobro `pagado` (concepto "{plan} · {mes} {año}", monto lo que Mercado Pago cobró, `metodo_pago` "Mercado Pago · débito", `mp_payment_id`) y adelanta `fecha_proximo_cobro` un mes. Todo en una transacción con la fila de la suscripción bloqueada.
- Cualquier otro tema: 200 sin hacer nada. Firma inválida: 401 sin tocar nada. Si falla la consulta a Mercado Pago: 500 para que reintente (cada ~15 minutos).
- Un pago que no corresponde a ninguna suscripción conocida, o con otra moneda, **no se pierde**: queda en `mp_notificaciones` con `sin_cobro = 1` y un error descriptivo.

### 6.4 Registrar pago (transferencia u otro medio)
Acción de la ficha y de la fila: **meses** (1 a 24), **monto** (por defecto `monto × meses`) y **nota**. Crea un cobro `pagado` (`metodo_pago` "Transferencia" o el que se indique) y adelanta `fecha_proximo_cobro` los meses indicados desde la fecha en que vence (si todavía no venció) o desde hoy.

### 6.5 Avance de la fecha
Un pago registrado a mano suma sus meses a partir de `max(fecha_proximo_cobro, hoy)`. Un cobro aprobado de Mercado Pago suma siempre un mes a partir de `fecha_proximo_cobro`, sin mirar la fecha de hoy: así un aviso tardío no corre la fecha respecto del calendario de Mercado Pago. La excepción es un cliente atrasado por meses (si ni sumando el mes la fecha llega a hoy): ahí se cuenta desde hoy, para que no siga "vencido" después de haber pagado. El débito automático solo se ofrece para planes mensuales (con uno anual cobraría el precio del año todos los meses). En ambos casos, si el día no existe en el mes destino se usa el último, y el mismo `mp_payment_id` nunca suma dos veces.

### 6.6 Ajustar precio
Por suscripción. Guarda el monto, registra el historial y manda el mail `precio_cambiado` ("desde tu próximo cobro (fecha)"). Con débito autorizado, **primero** actualiza el monto en Mercado Pago (`PUT /preapproval`) y recién después el local; si Mercado Pago no lo aplica, no se cambia nada y el admin ve el error. Los cobros ya registrados no cambian.

### 6.7 Cancelar y reactivar
- **Cancelar** (admin): cancela el preapproval en Mercado Pago, y recién entonces marca la suscripción `cancelada` con `fecha_baja`. Manda el mail `suscripcion_cancelada` una sola vez: "tu servicio sigue activo hasta {fecha_proximo_cobro}, podés reactivarlo cuando quieras". Si Mercado Pago falla, no cambia nada local.
- **Reactivar**: si al darla de baja tenía un débito vivo (`debito_al_cancelar`), crea un preapproval nuevo con `start_date` = `fecha_proximo_cobro` si todavía es futura (no se cobra de más); si ya venció, cobra al autorizar. Vuelve a pasar por el link de alta. Si no tenía un débito vivo (por ejemplo, se había caído antes), solo la reactiva.
- **Pausar y reanudar**: sincronizan el preapproval. No se puede pausar un débito pendiente de autorización ni pasar a débito con el cobro pausado (Mercado Pago cobraría igual cuando el cliente autorice).
- **Avisos de baja**: el mail del débito caído y el de la baja del admin son hechos distintos: una baja del admin siempre avisa (una sola vez, porque una segunda da 409), y un débito nuevo, al pasar a débito o al autorizarse, vuelve a habilitar el aviso si se cae otra vez.

### 6.8 Sincronizar
Botón en la ficha: lee el preapproval y sus cobros recientes en Mercado Pago y aplica lo que falte por el mismo camino que el webhook. Reemplaza a la reconciliación por cron; es idempotente.

### 6.9 Borrado
No se puede borrar una suscripción con `mp_preapproval_id` activo sin cancelarla antes (409). Borrar una suscripción deja sus cobros (`ON DELETE SET NULL`).

## 7. Pantallas (solo admin)

- **Ficha del cliente (`admin/cliente.php`)**: sección **Suscripción** con plan, monto, modalidad, estado calculado, "pago hasta", estado del débito y el último pago; acciones: Pasar a débito (con el link para copiar), Registrar pago, Ajustar precio, Pausar/Reanudar, Sincronizar, Cancelar, Reactivar, Avisar por WhatsApp (botón manual `wa.me`, solo en suscripciones vencidas con teléfono válido).
- **Cobros → pestaña "Suscripciones mensuales"** (quinta pestaña): resumen con `.stats` (ingreso mensual, al día, por vencer, vencidas) y una `.item` por suscripción con insignias de estado y modalidad (ícono y texto), botón principal visible y selector "Más acciones…". **+ Nueva suscripción** y acceso a **Planes**.
- **Planes (`admin/planes.php`)**: alta, edición y borrado (409 si hay suscripciones que lo usan).
- Estilos nuevos solo si hacen falta: `.monto` y el ícono dentro de las insignias. Referencia visual: `.superpowers/brainstorm/*/content/pagos-estilos-v2.html`, adaptada (sin Pagar ahora ni contador de portal).

## 8. n8n y mails

Workflow importable "VEZZA · Avisos de pago" con dos plantillas, `precio_cambiado` y `suscripcion_cancelada`, por SMTP de `info@vezzadev.com`, con el contrato `{plantilla, destino, datos}` y el header de secreto de `mail_enviar`. Textos en voseo. n8n no decide nada: solo entrega. La app nunca falla porque un mail no salió (se registra y sigue).

## 9. Configuración y despliegue

- `.env.example` y `DEPLOY.md`: `MP_ACCESS_TOKEN`, `MP_WEBHOOK_SECRET`; la URL del webhook para cargar en Mercado Pago (`https://vezzadev.com/api/mp-webhook`) con los eventos **Planes y suscripciones** (`subscription_preapproval` y `subscription_authorized_payment`); solo webhooks firmados. **Sin cron.**
- Una aplicación de Mercado Pago tiene una sola URL de webhook por modo (prueba y producción): VEZZA necesita **su propia aplicación**, distinta de la de vezzashop. *(A confirmar con Mercado Pago.)*
- Orden de deploy: aplicar la migración `003` desde `/admin/migraciones` antes de cargar las variables.

## 10. Pruebas

PHPUnit sobre `DbTestCase`, con el transporte de Mercado Pago y de mails inyectables (sin red). Tests antes del código. Cubren: el estado calculado en los bordes (`dias` = -1, 0, 7, 8); el webhook (firma inválida, aviso repetido cinco veces suma una vez, pago con otra moneda o sin suscripción va a `sin_cobro`, fallo de la API devuelve 500); registrar pago (meses, monto por defecto, avance desde la fecha o desde hoy, fin de mes); ajustar precio y cancelar con Mercado Pago caído (el local no cambia); el mail de baja una sola vez; alta de débito que no cambia la modalidad hasta la autorización; borrado bloqueado con preapproval activo; y regresión: los tests del admin y del portal pasan sin modificarse.

## 11. Estado de lo ya construido (rama `pagos-suscripciones`)

| Tarea del plan anterior | Qué pasa |
|---|---|
| 1 Migración | Se reescribe `003` según la sección 5 |
| 2 Planes y suscripciones (repo y API) | Se conserva; `MODALIDADES` pasa a `['debito','transferencia']` y se agregan el bloqueo de borrado y el filtro `estado=cancelada` pendientes |
| 3 Cliente de Mercado Pago y firma | Se conserva; se **quitan** las preferencias (`mp_crear_preferencia`, `mp_invalidar_preferencia`) y lo que quede sin uso |
| 4 Generación de cobros mensuales | Se **elimina** (`cobros_suscripciones.php` y su test) |
| 5 Webhook y registro de pagos | Se **reescribe** sobre el cobro recurrente y el preapproval; se quita la resolución por `cobro-{id}` y `susc-{id}` y el despacho de avisos |
| 6 Reconciliación (sin commitear) | Se descarta; queda apartada en un stash por si hace falta consultarla |
| 7 a 14 (avisos, ciclo, cron, n8n, admin, portal) | Se reemplazan por las secciones 6 a 9 de este diseño; el portal queda fuera |

## 12. Riesgos sin confirmar contra Mercado Pago real

Se comprueban con cuentas de prueba antes de cobrarle a un cliente: que la creación del preapproval devuelva `init_point`; la ortografía del estado de cancelación (`cancelled` o `canceled`); el valor de reactivación; que `PUT /preapproval` acepte un cambio de monto (puede tener límites); que `start_date` en el futuro difiera de verdad el primer cobro; la fórmula exacta de la firma de los webhooks de suscripciones; y cómo reintenta Mercado Pago ante una tarjeta rechazada. Todo vive en `admin/includes/mercadopago.php`.

## 13. Qué queda a criterio del builder

Estructura de archivos y nombres dentro de las convenciones del repo; cómo se presenta el ingreso mensual si hay suscripciones en distintas monedas (propuesta: solo se suman las ARS y se aclara); qué fecha manda en un débito (la propia o el `next_payment_date` de Mercado Pago, con la regla de que cada cobro aprobado adelante un mes y ninguno lo haga dos veces); el texto exacto de los mails y del mensaje de WhatsApp; cómo se reparte el trabajo en tareas.

## 14. Decisiones mías, a revisar

1. `back_url` del alta de débito: la home `/`, sin tocarla.
2. Botón manual **Avisar por WhatsApp** (solo `wa.me`, sin API) en las suscripciones vencidas.
3. La reconciliación sin commitear queda apartada, no borrada.
4. Se descarta el mail de confirmación de cada pago: Mercado Pago ya le avisa al pagador y el admin lo ve en la ficha.
