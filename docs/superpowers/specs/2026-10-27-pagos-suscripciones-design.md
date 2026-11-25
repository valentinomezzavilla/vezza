# Cobro de suscripciones mensuales con Mercado Pago — Diseño

> Reemplazado en lo que respecta al cobro por `2026-11-25-pagos-estilo-vezzashop-design.md` (spec) y `2026-11-25-pagos-estilo-vezzashop.md` (plan).

Fecha: 2026-10-27. Estado: borrador para aprobación.

## 1. Intención y por qué

Hoy VEZZA cobra a sus clientes por transferencia y marca cada cobro a mano en el módulo **Cobros**. Cada cliente paga un monto distinto según el servicio que tiene contratado. Se quiere que el cobro mensual se haga solo, que el sistema se entere solo de si el cliente pagó, y que avise cuando no pagó, sin pausar nunca el servicio.

Cada cliente elige cómo pagar:

- **Débito automático:** autoriza una vez con su tarjeta en Mercado Pago y Mercado Pago le cobra cada mes.
- **Link mensual:** cada mes recibe un link de pago y paga cuando quiere.
- **Transferencia:** la modalidad actual. Solo la asigna el admin; el cliente no la elige. Se sigue marcando como pagado a mano.

El éxito es dejar de revisar transferencias una por una: abrir **Cobros → Suscripciones mensuales** y ver quién está al día, quién tiene algo pendiente y quién está vencido.

## 2. Objetivos, no objetivos y anti-objetivos

**Objetivos**
- Cobro mensual recurrente por Mercado Pago en las dos modalidades.
- Validar automáticamente cada pago (webhook verificado contra la API de Mercado Pago, más un chequeo de respaldo).
- Un cobro por suscripción y por mes en el módulo `cobros`, creado automáticamente, para que dashboard y reportes sigan funcionando sin cambios.
- Avisos escalonados por mail (obligatorio) y por WhatsApp (canal que se activa cuando el número esté conectado), enviados desde n8n.
- Pantalla **Pagos** en el portal del cliente y pestaña **Suscripciones mensuales** en Cobros del admin.
- Ajuste de precios por suscripción, con mail de aviso al cliente.

**No objetivos (esta versión)**
- Pausar o cortar servicios por falta de pago.
- Facturación electrónica, prorrateos, devoluciones automáticas, descuentos o cupones.
- Planes anuales: solo se cobra mensual, aunque la tabla `planes` admita `anual`.
- Otra moneda que no sea pesos argentinos (ARS): la cuenta de Mercado Pago es argentina y todos los planes van en pesos. No se hace conversión de monedas. Las modalidades `debito` y `link` se rechazan si la moneda de la suscripción no es ARS; una suscripción en otra moneda solo puede ser `transferencia`.
- El flujo de solicitudes de alta, cambio y baja del cliente (fase 4 del spec del portal). El admin asigna y cambia planes él mismo.
- Reintentos propios de un débito rechazado: se confía en los reintentos de Mercado Pago y se avisa al cliente.
- WhatsApp entrante (respuestas de clientes).

**Anti-objetivos (fallaría aunque funcione)**
- Marcar un cobro como pagado sin verificarlo contra Mercado Pago.
- Mandar el mismo aviso dos veces, o seguir avisando a quien ya pagó.
- Cobrar dos veces el mismo mes (por ejemplo, un link y un débito a la vez).
- Guardar datos de tarjeta: la tarjeta se carga siempre en Mercado Pago.
- Cualquier cambio que haga fallar los tests existentes del admin o del portal.

## 3. Restricciones

- Stack del repo: PHP 8.1 plano, MySQL/MariaDB, JS puro, PHPUnit 10.5, sin frameworks ni build. Deploy por `git push` a `main` (Hostinger Business). Compatibilidad PHP 8.1 (`platform.php = 8.1.0`).
- Migración nueva `003_pagos.sql`, aditiva, con las convenciones de las anteriores (InnoDB, `utf8mb4_unicode_ci`, `created_at` y `updated_at`), aplicada desde `/admin/migraciones`.
- Sin secretos en el repo. Variables nuevas en `.env` y `.env.example` (ver sección 8).
- Tema y voz del portal: claro, `#F5F3EE` / `#15131C` / `#3D2FE0`, Manrope y Sora, voseo en primera persona. Todo se usa en un celular de 360 px.
- El JS del portal usa `textContent` o el helper `el`, nunca `innerHTML` con datos del usuario. Aislamiento por cliente con `cliente_id` de la sesión y 404 uniforme.
- Los mails y WhatsApp salen por n8n con el patrón actual (`mail_enviar`, webhook con secreto compartido). No se instalan librerías de mail en Hostinger.
- El servidor MCP de n8n no estaba conectado cuando se escribió este diseño: el workflow se entrega como JSON importable (ver sección 7).
- Mercado Pago se integra por su API HTTP con `file_get_contents`/cURL, sin SDK, salvo que el builder justifique lo contrario. Los detalles exactos de la API (endpoints, campos, firma del webhook) se **deben confirmar contra la documentación vigente de Mercado Pago**: este diseño fija el comportamiento, no los nombres de los campos.

## 4. Modelo de datos (migración `003_pagos`)

Sobre las tablas de la migración 002 (`planes`, `suscripciones_cliente`) y la 001 (`cobros`).

**`suscripciones_cliente` (ALTER)**
- `monto DECIMAL(12,2) NOT NULL` y `moneda CHAR(3) NOT NULL`: copia del plan al crearla. Es el precio real de esa suscripción y es lo que ajusta el admin. *(Decisión mía: el ajuste de precio es por suscripción, no por plan, porque así es el mockup aprobado y cada cliente puede tener un monto distinto. El `monto` del plan queda como valor por defecto para altas nuevas.)*
- `modalidad ENUM('debito','link','transferencia') NOT NULL DEFAULT 'transferencia'`.
- `mp_preapproval_id VARCHAR(100) NULL` y `mp_preapproval_estado ENUM('pendiente','autorizada','pausada','cancelada') NULL`: la suscripción recurrente en Mercado Pago y su estado, cuando se usa o se está por usar el débito automático. `pendiente` significa que el alta se inició pero el cliente todavía no autorizó.
- `pausada TINYINT(1) NOT NULL DEFAULT 0`: el admin puede pausar el cobro (no genera cobros nuevos). No tiene relación con el estado de los servicios.

**`cobros` (ALTER)**
- `suscripcion_cliente_id INT UNSIGNED NULL` (FK, ON DELETE SET NULL) y `periodo CHAR(7) NULL` (`YYYY-MM`), con clave única `(suscripcion_cliente_id, periodo)`: garantiza un solo cobro por suscripción y mes aunque el cron corra dos veces.
- `mp_payment_id VARCHAR(100) NULL` (único), `link_pago VARCHAR(500) NULL`, `mp_preference_id VARCHAR(100) NULL`.
- Los cobros manuales existentes no cambian (todas las columnas nuevas son nulas).

**`mp_notificaciones` (nueva)**: registro de cada aviso recibido de Mercado Pago (`id`, `tipo`, `recurso_id`, `payload` JSON, `recibida_en`, `procesada_en`, `error`). Sirve de auditoría y de idempotencia: un mismo pago notificado cinco veces se procesa una vez.

**`avisos_pago` (nueva)**: un registro por aviso enviado (`id`, `cobro_id`, `tipo` ENUM `previo`, `vencimiento`, `vencido`, `confirmacion`; `canal` ENUM `mail`, `whatsapp`; `numero` para los vencidos periódicos; `enviado_en`; `ok`). Clave única `(cobro_id, tipo, canal, numero)`: es lo que impide avisar dos veces. Los mails de cambio de precio se registran en `suscripciones_precio_historial` (nueva: `suscripcion_cliente_id`, `monto_anterior`, `monto_nuevo`, `moneda`, `creado_en`, `avisado_en`).

## 5. Comportamiento

### 5.1 Generación de cobros
Un script diario por CLI (`scripts/cobros-diario.php`, Cron Jobs de hPanel) recorre las suscripciones activas y no pausadas. Cuando faltan **5 días** o menos para `fecha_proximo_cobro` y todavía no existe el cobro de ese período, lo crea en `cobros` como `pendiente`, con el monto y la moneda de la suscripción, `fecha_vencimiento = fecha_proximo_cobro` y concepto con el nombre del plan y el mes. Según la modalidad:
- `link`: crea la preferencia de pago en Mercado Pago, con el id del cobro como referencia externa, y guarda `link_pago`.
- `debito`: no hay link; Mercado Pago cobra solo en la fecha. El cobro queda esperando su pago.
- `transferencia`: solo el cobro pendiente.

`fecha_proximo_cobro` es el vencimiento del próximo período a generar. **Avanza un mes en el momento en que se genera el cobro, no cuando se paga**, con la regla de `sumar_periodo` que ya existe (si el día no existe en el mes, usa el último). Así, si un cliente no paga, el mes siguiente igual genera su propio cobro y las deudas se acumulan, cada una visible como pendiente o vencida en el portal y en el admin. Un pago atrasado nunca mueve la fecha de la suscripción. *(Los 5 días de anticipación y esta regla de acumulación son decisión mía; la segunda sale de que quieras ver todos los pagos pendientes.)*

### 5.2 Validación de pagos
- Mercado Pago avisa por webhook a un endpoint público (`/api/mp-webhook`) que no usa sesión ni CSRF. El endpoint verifica la firma del mensaje, guarda el aviso en `mp_notificaciones`, responde 200 enseguida y procesa.
- Un aviso nunca se toma como prueba: el sistema consulta la API de Mercado Pago por el pago o el cobro de la suscripción y recién ahí decide. Antes de marcar pagado se comprueba que el estado sea aprobado, que la referencia corresponda a un cobro existente y que **monto y moneda coincidan** con el cobro.
- Al aprobarse: el cobro pasa a `pagado` con `fecha_pago`, `metodo_pago` ("Mercado Pago · link" o "Mercado Pago · débito") y `mp_payment_id`; se corta la secuencia de avisos y se manda el mail de confirmación.
- Un débito rechazado deja el cobro como está (`pendiente`, luego vencido) y dispara el aviso correspondiente indicando que hay que revisar el medio de pago.
- **Respaldo:** el cron diario (y uno más frecuente si el builder lo ve necesario) revisa en Mercado Pago los pagos de los últimos días de los cobros todavía pendientes, por si un webhook se perdió. Procesa igual que el webhook y es idempotente.

### 5.3 Avisos por falta de pago
Siempre avisos, nunca pausa. Para cada cobro `pendiente` de una suscripción:
- **3 días antes del vencimiento:** aviso amable con monto y link de pago.
- **El día del vencimiento:** recordatorio.
- **Vencido:** un aviso cada 3 días hasta que pague (días 3, 6, 9, ...).
- **Al pagar:** mail de confirmación. No hay más avisos.

Cada aviso se registra en `avisos_pago` antes de darlo por enviado, de modo que correr el cron dos veces el mismo día no duplica nada. Si un canal falla, se reintenta en la próxima corrida y no bloquea al otro canal.

**Canales**
- **Mail:** obligatorio. Usa `mail_enviar` con plantillas nuevas, por el webhook de n8n existente.
- **WhatsApp:** un segundo webhook de n8n (`WHATSAPP_WEBHOOK_URL`). Mientras la variable esté vacía, el canal está apagado y no pasa nada. Hasta que el número esté conectado, el admin ve en cada fila de la pestaña un botón **Avisar por WhatsApp** que abre un chat `wa.me` con el mensaje ya escrito. *(El botón es decisión mía para cubrir el hueco hasta que la API esté conectada.)*

### 5.4 Cambio de precio
En la fila de la suscripción, **Ajustar precio** pide el monto nuevo, lo guarda en la suscripción, registra el cambio en `suscripciones_precio_historial` y manda el mail de aviso al cliente. Si la modalidad es `debito`, actualiza también el monto de la suscripción en Mercado Pago. El cobro ya generado del período en curso conserva su monto; el nuevo rige desde el siguiente. *(Decisión mía.)*

### 5.5 Cambio de modalidad
Lo puede hacer el cliente (solo entre `debito` y `link`, desde el portal) o el admin (cualquiera). Pasar a `debito` lleva al cliente al alta de la suscripción en Mercado Pago y vuelve al portal; la modalidad solo cambia cuando Mercado Pago confirma la autorización. Pasar a `link` cancela la suscripción de Mercado Pago. Mientras haya un pago a medias, el sistema no debe permitir que se cobre el mismo mes por dos vías.

### 5.6 Reglas de borde *(decisiones mías, a revisar)*

- **Fecha del débito.** La suscripción de Mercado Pago se crea con inicio en `fecha_proximo_cobro` (si Mercado Pago permite fijar la fecha de inicio; el builder lo confirma). Si no permite alinearla, `fecha_proximo_cobro` pasa a seguir la fecha de autorización del cliente. Si Mercado Pago cobra en el momento de autorizar, ese pago se asigna al cobro del período en curso si existe; si no existe, se crea.
- **Alta de débito pendiente.** Mientras el cliente no autoriza, la suscripción conserva su modalidad anterior (por defecto `transferencia`) y `mp_preapproval_estado = 'pendiente'`. Solo al confirmarse la autorización pasa a `debito`. El admin ve la fila como "Débito pendiente de autorización" y puede reenviar el link de alta. Si la autorización nunca llega, no se cobra nada por esa vía.
- **Pausa y cancelación.** Pausar una suscripción de débito pausa también la suscripción en Mercado Pago, y reanudar la reactiva. Cancelar la cancela allá. Un pago válido de Mercado Pago que no coincida con ningún cobro no se pierde ni se crea solo: queda registrado en `mp_notificaciones` marcado "sin cobro asignado" y aparece en el admin como pago por revisar.
- **Cambio de modalidad a mitad de período.** El cobro abierto sigue pagándose por la vía con la que se generó hasta que se pague; la modalidad nueva rige desde el siguiente período. El cambio se bloquea mientras haya un pago en proceso (pendiente de acreditación). Cuando un cobro se marca pagado a mano, su link de pago se invalida.
- **Cobros atrasados al crearlos.** Si un cobro se crea cuando ya pasó la fecha de un aviso (por ejemplo, una suscripción alta con vencimiento próximo), se saltean los avisos "previo" que ya no corresponden y se sigue con el siguiente que toque.
- **Suscripciones existentes.** Al migrar, las filas ya existentes de `suscripciones_cliente` copian `monto` y `moneda` desde su plan y quedan en `transferencia`.

## 6. Pantallas

**Portal, `/clientes/pagos` (nueva)**, según los mockups aprobados:
- Lista por mes: lo pendiente arriba, con color (amarillo antes del vencimiento, rojo si venció) y el botón **Pagar ahora**; lo pagado abajo con fecha y método.
- Cartel de aviso en la pantalla de inicio (Estado) **solo** cuando hay un cobro por vencer en 3 días o vencido, que lleva a Pagos. El menú muestra un número rojo con la cantidad de cobros pendientes.
- Elección de modalidad: el cliente ve cómo paga hoy y puede cambiar entre débito automático y link mensual.
- Sin datos de otros clientes: todo sale del `cliente_id` de la sesión.

**Admin, Cobros → pestaña "Suscripciones mensuales" (nueva)**, según el mockup aprobado, al lado de Pendientes, Vencidos, Pagados y Todos:
- Resumen: ingreso mensual (suma de suscripciones activas), cantidad al día, pendientes y vencidas.
- Una fila por suscripción: cliente, plan, monto, modalidad, próximo cobro, estado y último aviso.
- Acciones: reenviar link, marcar como pagado (reutiliza la lógica manual de cobros), cambiar modalidad, ajustar precio, pausar cobro, historial, avisar por WhatsApp.
- Alta de la suscripción: **+ Nueva suscripción** (cliente, plan, modalidad, fecha de inicio y de primer cobro). Se necesita además un CRUD simple de **planes** en el admin, porque hoy la tabla existe pero no tiene pantalla.

El módulo `cobros` actual, el dashboard y los reportes no cambian: los cobros de suscripciones son cobros normales.

### 6.1 Estilo visual

Se reutiliza el CSS que ya existe (`admin/assets/admin.css`, que el portal también carga); no se agrega un sistema de diseño nuevo.

- **Colores:** solo los tokens de `:root` (`--ok`/`--ok-soft`, `--warn`/`--warn-soft`, `--danger`/`--danger-soft`, `--accent`/`--accent-soft`). Nada de hex sueltos.
- **Componentes existentes:** `.item` + `.item-top` + `.item-sub` para cada cobro o suscripción, `.badge-ok`, `.badge-warn`, `.badge-danger` y `.badge-accent` para estados y modalidad, `.stats`/`.stat` para el resumen, `.tabs` para la pestaña nueva, `.btn`/`.btn-primario`/`.btn-chico` para acciones y `.filtro` (selector "Más acciones…") para las secundarias.
- **Estados:** pagado → ok; por vencer → warn; vencido → danger; modalidad → accent; transferencia → insignia neutra. **El estado nunca se comunica solo con color:** cada insignia lleva ícono y texto.
- **Estilos nuevos, y solo estos:** `.aviso-pago` con variantes `-warn` y `-danger` (ícono, título, texto y botón **Pagar** dentro), `.contador` (número rojo sobre el ítem Pagos del menú, con texto accesible como "1 pago pendiente"), el ícono dentro de las insignias y `.monto` (Sora, cifras tabulares).
- **Celular primero:** probado a 360 px de ancho, sin scroll horizontal; todos los controles táctiles de 44 px (el panel ya lo hace con `@media (pointer: coarse)`); el cartel de aviso se anuncia con `role="status"`.
- **Formato:** montos como `$85.000` (es-AR), fechas cortas ("1 nov"), mes y año en el historial ("Octubre 2026"). Textos en voseo y primera persona singular.
- **Movimiento:** solo el feedback al tocar que ya tienen los botones, respetando `prefers-reduced-motion`.
- **Referencia visual:** los mockups aprobados viven en `.superpowers/brainstorm/` (archivo `pagos-estilos-v2.html`, armado con el CSS real).
- Al agregar los estilos nuevos hay que subir `PANEL_ASSET_V` y `PORTAL_ASSET_V`, como en las fases anteriores.

## 7. n8n

Un workflow nuevo, **"VEZZA · Avisos de pago"**, o una extensión del workflow "Mails del portal" si el builder lo ve más simple (misma credencial SMTP y mismo secreto):
- Recibe `POST` con el secreto y un cuerpo `{plantilla, destino, datos}`, igual que el de mails.
- Rama de mail: arma el asunto y el cuerpo según `plantilla` (`pago_previo`, `pago_vencimiento`, `pago_vencido`, `pago_confirmado`, `precio_cambiado`, `debito_rechazado`) y lo manda con SMTP de `info@vezzadev.com`.
- Rama de WhatsApp: un nodo de WhatsApp Business Cloud con plantillas aprobadas por Meta, desactivable. Los textos de las plantillas de WhatsApp se redactan junto con el workflow para presentarlas a Meta.
- Responde 2xx cuando el envío terminó. El timeout del lado PHP ya está ajustado a 25 s por el SMTP.
- n8n no decide nada: la lógica de cuándo avisar vive en PHP. n8n solo entrega.

Se entrega como JSON importable con una nota de cómo cargar credenciales. Si el MCP de n8n se reconecta, se puede crear directamente.

## 8. Configuración (`.env`)

Variables nuevas, sin valores en el repo: `MP_ACCESS_TOKEN`, `MP_WEBHOOK_SECRET`, `MP_PUBLIC_KEY` (si hiciera falta), `WHATSAPP_WEBHOOK_URL` (vacía = canal apagado) y, si el builder separa el workflow, `AVISOS_WEBHOOK_URL`. Se documentan en `.env.example` y en `DEPLOY.md`, junto con el alta del Cron Job diario y la URL del webhook para cargar en Mercado Pago.

## 9. Seguridad

- El endpoint del webhook es público: verifica firma, no confía en el cuerpo, valida montos, es idempotente y no revela información en sus errores (siempre 200/4xx genérico y log interno).
- Los links de pago y las suscripciones de Mercado Pago se crean desde el servidor; el portal nunca ve el access token.
- Las acciones del portal (cambiar modalidad, pagar) verifican que la suscripción o el cobro pertenezcan al cliente de la sesión; si no, 404.
- Una modalidad `debito` o `link` en el admin exige un cliente con mail para poder avisar; si no tiene, se avisa en pantalla.
- Los pagos y los avisos quedan registrados (`mp_notificaciones`, `avisos_pago`) para poder auditar qué pasó con cada cobro.

## 10. Pruebas

PHPUnit sobre `DbTestCase`, con el cliente de Mercado Pago y el transporte de mails inyectables (como `Mail::$transporte` y el fetcher del chequeo de servicios), sin tocar la red. Tests antes del código.

- Generación de cobros: crea uno por suscripción y mes, no duplica al correr dos veces, respeta suscripciones pausadas y canceladas, mes con menos días.
- Webhook: firma inválida rechazada; el mismo aviso repetido procesa una vez; pago aprobado con monto o moneda distintos **no** marca pagado; pago de un cobro ajeno; pago pendiente o rechazado no cambia el cobro.
- Aprobado: cobro pagado, `fecha_proximo_cobro` adelantada, avisos cortados, confirmación enviada una vez.
- Avisos: salen en los días 3 antes, 0 y cada 3 días vencido; no se repiten; un canal caído no bloquea al otro; WhatsApp apagado no rompe nada.
- Cambio de precio: afecta solo al siguiente cobro, queda el historial, el mail sale.
- Cambio de modalidad: no permite cobrar el mismo mes por dos vías; la modalidad solo cambia con la confirmación de Mercado Pago.
- Aislamiento del portal: un cliente no ve ni cambia pagos de otro (404).
- Regresión: los tests actuales del admin y del portal pasan sin modificarse.

## 11. Dependencias, orden y riesgos

- Depende de la fase 1 del portal (ya en `main`). No depende de las fases 2 y 3. Si la fase 2 (servicios) se implementa en paralelo, hay que coordinar `PORTAL_ASSET_V`, `PANEL_ASSET_V` y el `.htaccess`.
- Requiere antes, de parte de VEZZA: credenciales de Mercado Pago (access token y secreto del webhook), poder cargar la URL del webhook en el panel de Mercado Pago, e importar el workflow en n8n.
- WhatsApp queda para cuando el número esté conectado a una cuenta de WhatsApp Business Platform; el diseño no lo bloquea.
- Mercado Pago ofrece credenciales y usuarios de prueba. Se desarrolla contra ellas y los tests unitarios usan un cliente falso; la verificación con pagos reales de bajo monto es una compuerta aparte al final, antes de cargarle débito a un cliente de verdad.
- Riesgo principal: comportamiento real de las suscripciones de Mercado Pago (reintentos de débitos rechazados, cambios de monto). El builder debe validarlo contra la documentación y con pagos de prueba antes de dar la integración por terminada.

## 12. Qué queda a criterio del builder

Estructura de archivos y nombres de funciones dentro de las convenciones del repo; si el cliente de Mercado Pago usa cURL o streams; cómo repartir el trabajo en tareas; si el respaldo de reconciliación corre una o varias veces por día; el texto exacto de los mails y de las plantillas de WhatsApp (en voseo, primera persona singular); y cómo se presenta el alta de la suscripción de débito dentro de Mercado Pago.
