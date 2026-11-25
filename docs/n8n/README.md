# Workflow de n8n: avisos de las suscripciones

`vezza-avisos-de-pago.json` es el workflow **"VEZZA · Avisos de pago"**. Manda dos mails, siempre desde `info@vezzadev.com`:

| `plantilla` | Cuándo la manda la app | `datos` |
|---|---|---|
| `precio_cambiado` | Al ajustar el precio de una suscripción | `nombre`, `plan`, `monto_anterior`, `monto_nuevo` (ya con formato `$85.000`), `desde` (texto largo, `''` si no hay fecha) |
| `suscripcion_cancelada` | Al dar de baja una suscripción (`motivo = baja`) o cuando Mercado Pago cancela el débito por afuera y la suscripción sigue activa (`motivo = debito`) | `nombre`, `plan`, `motivo`, `acceso_hasta` (texto largo), `vencida` (`true` si lo pagado ya terminó y `acceso_hasta` viene vacío) |

n8n **no decide nada**: la app decide cuándo avisar y arma los datos; el workflow solo arma el texto y lo entrega. El contrato es el de `mail_enviar`: un `POST` con `{plantilla, destino, datos}` y el secreto en el header `X-Webhook-Secret`. Si n8n está caído o no contesta 2xx, la app no se rompe: el cambio igual queda hecho y en pantalla se avisa que el mail no salió.

## Cómo cargarlo

Hay dos caminos. El primero es el recomendado porque la app manda **todos** sus mails a una sola URL (`MAIL_WEBHOOK_URL`).

**A. Sumarlo al workflow de mails del portal (recomendado).** En n8n, abrí "VEZZA · Mails del portal" y agregá a su Switch dos salidas con las reglas `{{ $json.body.plantilla }}` igual a `precio_cambiado` y a `suscripcion_cancelada`. Copiá desde este JSON los dos nodos de mail y conectalos a esas salidas y a la respuesta. No hay que tocar `MAIL_WEBHOOK_URL`.

**B. Importarlo aparte.** *Workflows → Import from file* y elegí `vezza-avisos-de-pago.json`. Después de importar:
1. En el nodo **Aviso de la app**, elegí la credencial *Header Auth* con el nombre `X-Webhook-Secret` y como valor el mismo `MAIL_WEBHOOK_SECRET` del `.env`.
2. En los dos nodos de mail, elegí la credencial SMTP de `info@vezzadev.com` (la misma que ya usa el workflow del portal).
3. Activá el workflow y copiá la URL de producción del webhook (`.../webhook/vezza-avisos-de-pago`).
4. Esa URL tiene que ser la `MAIL_WEBHOOK_URL`. Como la app manda **todos** sus mails al mismo lugar, si apuntás la variable acá las invitaciones y recuperaciones del portal dejan de salir: este workflow responde `422` a cualquier plantilla que no sea de las dos suyas (salida "Otra plantilla"), así que la app avisa enseguida "no se mandó" en vez de quedarse esperando. Para que sigan saliendo, el workflow del portal tiene que pasar a recibirlos por acá (o usá el camino A, que no tiene este problema).

El archivo no trae secretos: solo referencias a credenciales por nombre.

## Probarlo

Con el workflow activo, desde una terminal (reemplazá la URL y el secreto, y poné un mail tuyo):

```bash
curl -X POST "https://TU-N8N/webhook/vezza-avisos-de-pago" \
  -H "Content-Type: application/json" -H "X-Webhook-Secret: TU_SECRETO" \
  -d '{"plantilla":"precio_cambiado","destino":"vos@ejemplo.com","datos":{"nombre":"Ana","plan":"Mantenimiento","monto_anterior":"$25.000","monto_nuevo":"$30.000","desde":"1 de abril de 2026"}}'
```

Tiene que responder `{"ok": true}` y llegar el mail. Probá también `suscripcion_cancelada` con `motivo` `baja` y `debito`, y con `vencida` en `true` y `false`.

## WhatsApp

No hay WhatsApp por API. Para avisar a quien paga por transferencia, el admin tiene el botón **Avisar por WhatsApp** en las suscripciones vencidas (abre `wa.me` con el mensaje escrito).
