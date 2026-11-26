# Vencimiento fijo y pagos desde el portal — Diseño

Fecha: 2026-11-26. Estado: **propuesto**, pendiente de aprobación del usuario.

Se apoya en `2026-11-25-pagos-estilo-vezzashop-design.md` (planes, suscripciones con monto propio, débito de Mercado Pago, registro manual, estado calculado desde "pago hasta"). Este diseño **cambia dos decisiones** de aquel: el portal de clientes ahora tiene una pantalla de pagos, y vuelve el pago único con Mercado Pago (Checkout Pro), esta vez lo inicia el cliente desde el portal y no hay links mensuales generados de antemano.

## 1. Intención

1. Cada suscripción vence **un día fijo de cada mes**. El pago que vence ese día cubre ese mes (mes adelantado).
2. El cliente paga **desde su portal** con Mercado Pago (tarjeta, dinero en cuenta o efectivo), por transferencia o activando el débito automático.
3. **Cada pago registrado** le manda por mail un **comprobante no válido como factura**, que también puede imprimir desde el portal.

## 2. Decisiones del usuario (conversación del 2026-11-26)

| Tema | Decisión |
|---|---|
| Día de vencimiento | Por suscripción. Por defecto, el día del alta: quien se da de alta el 28 vence todos los 28. |
| Suscripciones que ya existen | Pasan a vencer **el 5 de cada mes**. |
| Mes que cubre el pago | El del vencimiento: lo que vence el 10 de diciembre es diciembre. |
| Medios en el portal | Mercado Pago, transferencia y débito automático. |
| Comprobante | Mail con el comprobante armado + página imprimible en el portal. Sin PDF adjunto. |

## 3. Vencimiento fijo

### Modelo
- `suscripciones_cliente.dia_vencimiento` (1 a 28). Se limita a 28 porque es el último día que existe en todos los meses (y el débito de Mercado Pago tampoco acepta más; *a confirmar*). Un alta del 29, 30 o 31 toma el **28**.
- `fecha_proximo_cobro` pasa a leerse como **próximo vencimiento** y **siempre cae en `dia_vencimiento`**. El estado (al día, por vencer con 7 días o menos, vencido) se calcula igual que hoy.
- **Alta:** el formulario pide día de vencimiento (por defecto el día de la fecha de inicio) y primer vencimiento (por defecto la fecha de inicio). Si el primer vencimiento no cae en ese día, se rechaza con el campo marcado.
- **Cada pago corre el vencimiento un mes desde el vencimiento que pagó**, nunca desde hoy. Pagar tarde no corre el día. Pagar antes adelanta igual: el que paga el 1 de diciembre lo que vence el 5 queda con vencimiento el 5 de enero.
  - Cambia el comportamiento actual de "Registrar pago" (`avance = max_hoy`), que hoy cuenta desde hoy si el cliente estaba atrasado. Con día fijo, un cliente con tres meses adeudados que paga uno sigue vencido: debe los otros dos. Si hubo una pausa o un arreglo, el admin corrige el vencimiento a mano desde Editar.
  - El débito ya avanza desde la fecha (`avance = fecha`). Se quita la excepción de "atrasado por meses → desde hoy" por la misma razón.
- **Cambiar el día** de una suscripción mueve el próximo vencimiento al nuevo día **del mismo mes**, y el formulario muestra cómo queda antes de guardar. Con un débito vivo, el día se cambia también en Mercado Pago; si Mercado Pago no lo permite (*a confirmar*), el cambio se rechaza con un aviso para pedir el débito de nuevo.
- **Débito nuevo:** el preapproval se crea con `start_date` = próximo vencimiento y `billing_day` = día de vencimiento (*a confirmar los dos nombres*).

### Migración `005_vencimiento_fijo`
- Agrega `dia_vencimiento TINYINT UNSIGNED NOT NULL DEFAULT 5`.
- Las suscripciones existentes quedan con día 5 y su próximo vencimiento pasa al **primer día 5 igual o posterior** a su fecha actual (`2026-12-01 → 2026-12-05`, `2026-12-20 → 2027-01-05`). Así **nadie queda vencido por la migración**; como mucho alguien gana unos días. Las que no tienen fecha la siguen sin tener.
- Es aditiva y se aplica desde `/admin/migraciones`.

## 4. Comprobantes de pago

- **Cada pago que entra por `suscripcion_registrar_pago`** (portal con Mercado Pago, débito, transferencia confirmada o "Registrar pago" del admin) emite un comprobante. Un aviso repetido del mismo pago no emite otro: se emite solo cuando el resultado es `registrado`.
- Tabla `comprobantes`: `numero` correlativo único, `cobro_id` único, `cliente_id`, `emitido_en`, `destino`, `enviado_en`, `error`. Se muestra como `0001-00000042`.
- **Contenido:** datos de VEZZA (`COMPROBANTE_EMISOR_NOMBRE`, `COMPROBANTE_EMISOR_CUIT` opcional, `COMPROBANTE_EMISOR_CONTACTO`), número, fecha, cliente, concepto (`Mantenimiento · diciembre 2026`), período cubierto (`5/12/2026 al 4/1/2027`), medio de pago, monto y la leyenda **"Comprobante no válido como factura"**.
- **Mail:** plantilla nueva `comprobante_pago` por el webhook de n8n de siempre (`mail_enviar`), a la casilla del acceso al portal del cliente (`usuarios_cliente.email`) o, si no tiene acceso, a `clientes.email`. Se manda **después** de registrar el pago, fuera de la transacción: si n8n falla, el pago queda registrado igual y el comprobante queda con el error. En la ficha del cliente, cada pago muestra si el comprobante salió y tiene **Reenviar comprobante**.
- **Página imprimible:** `/clientes/comprobante?n=0001-00000042` con la sesión del portal (solo los del propio cliente; uno ajeno da 404) y `/admin/comprobante?n=...` para el admin. Estilo de impresión propio: se imprime o se guarda como PDF desde el navegador.
- **n8n:** se suma `comprobante_pago` al workflow "VEZZA · Mails del portal" (lista `PLANTILLAS`, validación y texto en `TEXTOS`), como se hizo con `precio_cambiado`.

## 5. Portal de clientes: pagar

El inicio del portal (`/clientes`, hoy "Lo estamos terminando de armar") pasa a mostrar la cuenta del cliente:

- Una tarjeta por suscripción: plan, monto, **próximo vencimiento** con su estado y los botones de pago.
- Lo pendiente de pago (cobros sueltos) y los últimos pagos, cada uno con su comprobante.
- API propia en `/clientes/api/*` con `portal_api_run` (sesión del cliente, CSRF en lo que no es GET), separada de `/api/*`. Cada id que viene del request se verifica contra el cliente de la sesión: uno ajeno da **404**.

### 5.1 Mercado Pago (Checkout Pro)
- **Pagar con Mercado Pago** crea un intento en `pagos_portal` (suscripción, monto, vencimiento que paga, preferencia, estado) y una **preferencia** de Checkout Pro con un ítem `Plan · mes año`, el monto de la suscripción, `external_reference = portal-{id del intento}`, `notification_url` al webhook de siempre y `back_urls` al portal (`?pago=aprobado|pendiente|rechazado`). El cliente va al `init_point`. El monto lo pone el servidor, nunca el navegador.
- El webhook atiende además el tema **`payment`**: consulta el pago a la API (nunca le cree al aviso), y si está aprobado, la referencia es `portal-{id}` y monto y moneda coinciden con el intento, lo registra como pago de **1 mes**, con medio `Mercado Pago · tarjeta`, `· dinero en cuenta` o `· efectivo` según el tipo de pago. Si algo no coincide, queda `sin_cobro` para revisarlo a mano, como hoy.
- Un pago en efectivo (Rapipago, Pago Fácil) llega primero pendiente y se registra recién cuando Mercado Pago avisa que se aprobó (*a confirmar que llega un segundo aviso*). El portal muestra "Pago pendiente de acreditación" mientras tanto.
- Si el cliente paga dos veces, se registran dos meses: es dinero que entró. Si genera varias preferencias y no paga, no pasa nada: la preferencia vence a las 72 h (*a confirmar el campo*).

### 5.2 Transferencia
- **Pagué por transferencia** muestra los datos de la cuenta (`TRANSFERENCIA_TITULAR`, `TRANSFERENCIA_CBU`, `TRANSFERENCIA_ALIAS`, `TRANSFERENCIA_BANCO`) y un formulario: fecha, monto (por defecto el de la suscripción), comprobante (PDF o imagen, hasta 5 MB, mismas reglas que los comprobantes del admin) y una nota opcional. Máximo 5 avisos por día por cliente.
- Queda un **pago informado** pendiente (`pagos_informados`). En el admin aparece en la ficha del cliente y en **Cobros → Suscripciones mensuales** con dos acciones:
  - **Confirmar:** registra el pago (con la fecha y el monto informados y el archivo como comprobante del cobro) y sale el comprobante.
  - **Rechazar:** pide un motivo y le avisa al cliente por mail (plantilla `pago_rechazado`).
- No avanza nada hasta que lo confirmás: un aviso del cliente no es un pago.

### 5.3 Débito automático
- **Activar débito automático** aparece si la suscripción está en transferencia, en ARS, con plan mensual y Mercado Pago configurado. Usa el mismo `suscripcion_pasar_a_debito` del admin, con el inicio en el próximo vencimiento, y lleva al cliente al alta de Mercado Pago. Desde ahí sigue como hoy: cada cobro aprobado llega por webhook, se registra y sale su comprobante.
- La baja o la pausa del débito siguen siendo del admin.

## 6. "Acceder a VEZZA" en los sistemas

`/api/sistema-cuenta` suma el día de vencimiento y el link al portal. El módulo de cada sistema muestra "Vence el 5 de cada mes" y un botón **Pagar en el portal**. El sistema sigue sin cobrar nada por sí mismo.

## 7. No objetivos

- Pagar cobros sueltos (no de suscripción) desde el portal. Se ven, pero se pagan como hoy. Se puede sumar después con el mismo flujo.
- Factura electrónica (ARCA), PDF adjunto, cuotas con interés, otras monedas en Mercado Pago, pausar o cortar servicios por falta de pago, avisos automáticos antes del vencimiento.
- Que el cliente cambie su día de vencimiento, su plan o se dé de baja desde el portal.

## 8. Anti-objetivos (fallaría aunque funcione)

- Dar por pagado algo que Mercado Pago no confirmó o una transferencia que el admin no confirmó.
- Registrar dos veces el mismo pago o mandar dos comprobantes del mismo pago.
- Que un cliente vea o pague la suscripción, el pago o el comprobante de otro.
- Que la migración deje vencido a alguien que hoy está al día.
- Que un fallo de n8n o de Mercado Pago deshaga o trabe un pago ya registrado.

## 9. Variables nuevas

`TRANSFERENCIA_TITULAR`, `TRANSFERENCIA_CBU`, `TRANSFERENCIA_ALIAS`, `TRANSFERENCIA_BANCO`, `COMPROBANTE_EMISOR_NOMBRE`, `COMPROBANTE_EMISOR_CUIT` (opcional), `COMPROBANTE_EMISOR_CONTACTO`. Sin los datos de transferencia, el portal no ofrece ese medio. Sin `MP_ACCESS_TOKEN`, no ofrece Mercado Pago ni débito.

## 10. Etapas (cada una se publica sola y deja todo andando)

1. **Vencimiento fijo:** migración 005, formulario de alta y edición, avance de los pagos y débito con día fijo.
2. **Comprobantes:** tabla, numeración, mail, página imprimible, reenviar y plantilla en n8n. Desde acá ya salen comprobantes de los pagos que registrás vos y de los débitos.
3. **Portal con la cuenta:** suscripciones, pendientes, últimos pagos y comprobantes, sin pagar todavía.
4. **Pagar con Mercado Pago** (Checkout Pro y tema `payment` del webhook).
5. **Transferencia informada** y su bandeja en el admin.
6. **Débito desde el portal** y el botón "Pagar en el portal" de los sistemas.

## 11. A confirmar con Mercado Pago

Desde la sesión en que se escribió este diseño no se pudo leer la documentación de Mercado Pago, así que estos nombres salen de lo conocido y se verifican antes de cobrar de verdad, con credenciales de prueba, como se hizo con el débito:
- Preferencia: `items[].title/quantity/unit_price/currency_id`, `external_reference`, `notification_url`, `back_urls`, `auto_return`, `expiration_date_to` y que la respuesta traiga `init_point`.
- Que en Argentina Checkout Pro ofrezca tarjeta, dinero en cuenta y efectivo, y que un pago en efectivo mande otro aviso al aprobarse.
- Tema del webhook `payment` y los campos de `GET /v1/payments/{id}`: `status`, `external_reference`, `transaction_amount`, `currency_id`, `payment_type_id`.
- Débito: `auto_recurring.billing_day` (rango 1 a 28) y si se puede cambiar con `PUT /preapproval/{id}`.

## 12. Tests

- Vencimiento: alta con día por defecto (incluido el 29 al 31 → 28), primer vencimiento que no cae en el día, pago puntual, tardío, adelantado y de varios meses, cambio de día, migración (nadie queda vencido, las sin fecha siguen sin fecha).
- Comprobantes: uno por pago registrado, ninguno por aviso repetido, numeración correlativa, destino (acceso al portal o mail del cliente), mail fallido que no deshace el pago, reenviar, página propia y ajena (404).
- Portal: aislamiento por cliente en cada endpoint, preferencia con el monto del servidor, webhook `payment` (aprobado, pendiente, monto distinto, repetido, referencia desconocida), transferencia informada (archivo, límite diario, confirmar, rechazar), débito desde el portal con las mismas validaciones del admin.
- Los tests existentes siguen pasando.
