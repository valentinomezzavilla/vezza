# Subir VEZZA a Hostinger

## Opción recomendada: publicación automática con Git

El sitio se publica solo desde el repo `github.com/valentinomezzavilla/vezza`,
rama `main`. Cada `git push` actualiza `public_html`.

- **Configuración:** hPanel → Avanzado → **Git**. Repositorio
  `https://github.com/valentinomezzavilla/vezza.git`, rama `main`,
  directorio vacío (`public_html`). La **implementación automática** está
  activada con un webhook en GitHub (Settings → Webhooks).
- **Qué queda público:** Hostinger copia el repo entero, pero `.htaccess`
  bloquea `.git/`, `imgs/` y los `.md`.
- **Si cambiás CSS o JS:** igual tenés que subir el `?v=N` en `index.html`
  antes de hacer push.

## Opción manual: subir los archivos

## Qué subir

Subí estos archivos y carpetas a `public_html`:

```
public_html/
├── .htaccess
├── index.html
├── robots.txt
├── sitemap.xml
├── css/styles.css
├── js/main.js
└── assets/
    ├── icons/  (favicon.svg, favicon-32.png, apple-touch-icon.png)
    └── img/    (og-image.png y las capturas de proyectos)
```

**No subas** `imgs/`, que son las piezas originales de la marca y quedan solo
como referencia, ni este `DEPLOY.md`. Si lo subís por error, `.htaccess`
bloquea su acceso.

## Antes de subir

1. **Formulario → n8n:** ya está conectado al workflow
   *VEZZA · Formulario de contacto*
   (`https://vmezza.app.n8n.cloud/webhook/vezza-contacto`). Ese workflow valida
   los datos, guarda cada contacto en la tabla `vezza_contactos` y te avisa por
   mail a `info@vezzadev.com` (enviado por SMTP de Hostinger). Tiene que quedar **activo** en n8n; si lo desactivás, el formulario
   muestra un error.

   Si algún día cambiás de automatización, esto es lo que tiene que cumplir:
   - Recibe un `POST` `application/x-www-form-urlencoded` con los campos
     `nombre`, `email`, `tipo`, `mensaje`, `origen` y `website`.
   - `website` es una trampa anti-spam. Si llega con texto, es un bot y hay que
     descartarlo.
   - Tiene que responder con un código 2xx. Cualquier otro código hace que la web
     muestre un error.
   - Tiene que permitir CORS desde `https://vezzadev.com`. En n8n es la opción
     *Allowed Origins* del nodo Webhook. Sin CORS, el navegador bloquea la
     respuesta y la persona ve un error aunque los datos hayan llegado.
2. **WhatsApp (opcional, más adelante):** el botón está comentado en
   `index.html`, dentro de Contacto. Descomentalo y reemplazá `[[NÚMERO]]`.

## Paso a paso en Hostinger

1. Entrá a hPanel → **Sitios web** → tu dominio → **Administrador de archivos**.
2. Abrí `public_html` y borrá el `default.php` o el `index.php` de ejemplo, si
   hay alguno.
3. Tocá **Subir** y subí los archivos y carpetas de la lista de arriba. Otra
   opción es comprimirlos en un `.zip`, subirlo y usar **Extraer**.
4. Revisá que `.htaccess` haya quedado dentro de `public_html`. Es un archivo
   oculto; si no lo ves, activá "Mostrar archivos ocultos".
5. En hPanel → **Seguridad → SSL**, verificá que el certificado esté activo.
   Suele venir incluido y gratis.
6. Abrí `https://vezzadev.com` y probá lo siguiente:
   - `http://` y `www.` redirigen a `https://vezzadev.com`.
   - Mandarte un mensaje de prueba desde el formulario muestra el mensaje de
     éxito y llega a tu automatización.
7. Opcional: en Google Search Console agregá el dominio y enviá
   `https://vezzadev.com/sitemap.xml`.

## Cuando hagas cambios

- Si editás `css/styles.css` o `js/main.js`, subí el número `?v=1` que aparece
  en `index.html` (`?v=2`, `?v=3`…). Si no lo hacés, los navegadores pueden
  seguir mostrando la versión vieja hasta un año.
- Si cambiás una imagen, subila con **otro nombre** (por ejemplo
  `og-image-2.png`) y actualizá la referencia, por el mismo motivo.
- Si cambia el dominio, buscá y reemplazá `https://vezzadev.com` en
  `index.html`, `sitemap.xml` y `robots.txt`.

## Panel admin (`/admin`)

El panel vive en el mismo repo y el mismo `public_html` que la landing. Es PHP
plano más MySQL: Hostinger lo ejecuta solo y el `git push` sigue siendo el único
paso de deploy. La landing no depende del panel. Si el panel falla, la landing
sigue funcionando igual.

### Setup único en Hostinger (antes del primer push con el panel)

1. **PHP:** en hPanel → Avanzado → Configuración de PHP, confirmá que la
   versión sea 8.1 o superior y que la extensión `fileinfo` esté activa.
2. **Base de datos:** en hPanel → Bases de datos → MySQL, creá una base y un
   usuario con una contraseña larga. Anotá el host (suele ser `localhost`), el
   nombre, el usuario y la contraseña.
3. **Esquema:** abrí phpMyAdmin en esa base → Importar → subí
   `db/migrations/001_inicial.sql` desde tu compu.
4. **Hash de tu contraseña:** en tu compu, desde PowerShell, corré
   `php scripts/hash-password.php` y copiá la línea `ADMIN_PASSWORD_HASH=...`.
5. **`.env` fuera de `public_html`:** en el Administrador de archivos subí un
   nivel desde `public_html` (a la carpeta que la contiene) y creá un archivo
   `.env`:

   ```dotenv
   APP_ENV=production
   ADMIN_USERNAME=tu-usuario
   ADMIN_PASSWORD_HASH=$2y$12$...
   DB_HOST=localhost
   DB_NAME=u123456_vezza
   DB_USER=u123456_vezza
   DB_PASS=la-contraseña-de-la-base
   UPLOADS_DIR=
   SESSIONS_DIR=
   ```

   `APP_ENV=production` es obligatorio: activa la cookie `Secure`. En
   `ADMIN_PASSWORD_HASH` pegá el hash que generaste. No agregues comentarios
   con `#` al final de una línea: pasarían a formar parte del valor.
6. **Carpetas privadas:** en ese mismo nivel creá `vezza_uploads/comprobantes/`
   (comprobantes) y `vezza_sessions/` (sesiones del panel, para que no te
   desloguee la limpieza del hosting). El panel las crea solo si tiene
   permisos, pero conviene dejarlas hechas.

### Deploys siguientes

- `git push` a `main`, igual que siempre.
- Si el push trae un archivo nuevo en `db/migrations/`, entrá a
  `https://vezzadev.com/admin/migraciones` y tocá **Aplicar migraciones**.
- Si cambiaste `admin/assets/*.css` o `*.js`, subí `PANEL_ASSET_V` en
  `admin/includes/layout.php` antes de hacer push.

### Qué nunca se sube

`composer.json` existe solo para correr los tests en tu compu. Si el deploy
por Git de Hostinger ejecuta `composer install` por su cuenta, funciona con
PHP 8.1 o superior y lo que instala en `vendor/` queda bloqueado al público.
Después del primer deploy revisá que la landing se haya actualizado.

`.env`, `.env.testing` y `vendor/` están en `.gitignore`. `.htaccess` además
responde 404 en `/.env*`, `/db/`, `/scripts/`, `/tests/`, `/vendor/` y
`/admin/includes/`, y 403 en `/docs/` y en los `.md`.

### Verificación después de cada deploy

```bash
scripts/verificar-panel.sh https://vezzadev.com
scripts/verificar-landing.sh https://vezzadev.com despues.txt
diff antes.txt despues.txt   # no tiene que haber diferencias
```

### Si algo sale mal

- **Panel con error 500:** revisá que `.env` exista un nivel arriba de
  `public_html` y que los datos de la base sean correctos. El detalle queda en
  el log de errores de PHP (hPanel → Avanzado → Logs de errores).
- **Te olvidaste la contraseña:** generá un hash nuevo con
  `php scripts/hash-password.php` y reemplazá `ADMIN_PASSWORD_HASH` en `.env`.
- **Te bloqueaste por intentos:** esperá 15 minutos o, en phpMyAdmin, corré
  `DELETE FROM login_intentos;`.
- **Volver atrás:** hacé `git revert` del commit que rompió algo y después
  `git push`. La landing no se ve afectada.

### Correr el panel en tu compu

1. XAMPP (PHP 8.2) está instalado en `C:\xampp` con un virtual host en el
   puerto 8080 que apunta a esta carpeta. Arrancá Apache y MySQL desde el
   XAMPP Control Panel.
2. `.env` local ya existe (base `vezza_admin`). Para usar tu propia
   contraseña, generá el hash con `php scripts/hash-password.php` y
   reemplazalo en `.env`.
3. Si hay migraciones nuevas: `php db/migrate.php`. Después abrí
   http://localhost:8080/admin.
4. Tests: `php vendor/bin/phpunit`. Usan la base `vezza_admin_test`, definida
   en `.env.testing`.

## Portal de clientes (`/clientes`)

Vive en el mismo repo y el mismo `public_html`. Es PHP plano más la misma base MySQL del panel. El `git push` sigue siendo el único paso de deploy.

### Primer deploy del portal

1. **Aplicá la migración `002_portal` ANTES del push.** El login del admin ahora consulta la columna `login_intentos.ambito`, que crea esa migración: si publicás el código antes, el login de `/admin-login` da un error 500 hasta que la apliques. Como `/admin/migraciones` pide estar logueado, si se te vence la sesión quedás afuera. La forma segura es importar `db/migrations/002_portal.sql` en phpMyAdmin (hPanel → Bases de datos → phpMyAdmin → Importar), igual que hiciste con la 001. La migración es aditiva: el código viejo sigue funcionando con el esquema nuevo. Si ya publicaste y tenés la sesión del admin abierta, podés aplicarla desde `https://vezzadev.com/admin/migraciones` → **Aplicar migraciones**.
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

## Cobro de suscripciones (Mercado Pago)

Cada cliente puede pagar su suscripción mensual por **débito automático de Mercado Pago** (Mercado Pago cobra solo cada mes) o por transferencia, que se registra a mano con **Registrar pago**. No hay cron ni links de pago: el sistema solo escucha los avisos de Mercado Pago, y el estado de cada suscripción (al día, por vencer, vencido) se calcula desde su "pago hasta". Todo vive en el admin: ficha del cliente → **Suscripción**, **Cobros → Suscripciones mensuales** y **Planes** (`/admin/planes`). La landing y el portal no cambian.

### Primer deploy

1. **Hacé una copia de la base** (phpMyAdmin → Exportar) y aplicá la migración `003_pagos` desde `https://vezzadev.com/admin/migraciones` → **Aplicar migraciones**. Es aditiva (el código viejo sigue andando con el esquema nuevo), pero MySQL no la deshace si falla a la mitad: en ese caso se restaura la copia, no se vuelve a correr. Aplicala antes del push para que las pantallas nuevas encuentren sus tablas.
2. **Mercado Pago.** En [Mercado Pago Developers](https://www.mercadopago.com.ar/developers/panel/app) creá una **aplicación propia de VEZZA**, distinta de la de vezzashop: una aplicación tiene una sola URL de webhook por modo (prueba y producción), así que no se puede compartir. *(A confirmar con Mercado Pago.)* En **Webhooks → Configurar notificaciones** cargá `https://vezzadev.com/api/mp-webhook` con los eventos **Planes y suscripciones** (`subscription_preapproval` y `subscription_authorized_payment`). Al guardar te da una **clave secreta**. Solo se aceptan avisos firmados: los IPN viejos sin firma devuelven 401.
3. Agregá al `.env` del servidor (un nivel arriba de `public_html`):

   ```dotenv
   MP_ACCESS_TOKEN=APP_USR-...   # Credenciales → Access Token (de prueba primero, de producción después)
   MP_WEBHOOK_SECRET=...         # la clave secreta del paso 2
   ```

   Sin `MP_ACCESS_TOKEN` el débito automático no se ofrece en el admin; registrar pagos a mano sigue funcionando.
4. **Mails.** Importá o sumá el workflow de n8n que manda los dos avisos (cambio de precio y baja): ver `docs/n8n/README.md`. Sin él la app funciona igual: cada acción avisa en pantalla si el mail no salió.
5. **No hay Cron Job que configurar.**
6. Corré `scripts/verificar-panel.sh https://vezzadev.com` y confirmá `Todo OK`.

### Antes de cobrarle a un cliente real

Esto lo hacés vos, con tus credenciales de Mercado Pago; el código se probó con un Mercado Pago de mentira.

1. Con credenciales y usuarios de **prueba** de Mercado Pago: creá una suscripción en ARS, **Pasar a débito**, copiá el link de alta, autorizalo con la tarjeta de prueba y comprobá que la suscripción pasa a **débito**, que llega el cobro y que el "pago hasta" avanza un mes.
2. Probá un pago rechazado y una baja hecha desde la cuenta de Mercado Pago: la suscripción tiene que volver a **transferencia** (sigue activa) y el cliente recibe el mail `suscripcion_cancelada` con `motivo` `debito`.
3. Confirmá lo que no se pudo verificar contra la documentación (todo vive en `admin/includes/mercadopago.php` y `admin/includes/repos/pagos_mp.php`):
   - que la respuesta de crear la suscripción traiga `init_point`;
   - si la baja se escribe `cancelled` o `canceled` (la app acepta las dos al leer, pero al cancelar manda `cancelled`);
   - el valor para reactivar una suscripción pausada (`authorized`);
   - que `PUT /preapproval` acepte cambiar el monto, también en una suscripción todavía pendiente de autorización;
   - que `start_date` en el futuro difiera de verdad el primer cobro;
   - la fórmula de la firma de los webhooks de suscripciones;
   - que los pagos de un débito traigan la `external_reference` `susc-{id}` (de eso depende **Sincronizar**) y dónde viene el id de su suscripción.
4. Hacé un **pago real de bajo monto** (por ejemplo `$100`) con una suscripción propia y comprobá que se refleja; recién entonces cargale el débito a un cliente real. Revisá el admin a 360 px de ancho en un celular.

### Si algo no se refleja

Abrí la ficha del cliente → **Suscripción** → **Sincronizar con Mercado Pago**: lee el estado del débito y registra los pagos aprobados que falten, por el mismo camino que el webhook (no duplica nada). Los avisos de pagos que no se pudieron asignar a ninguna suscripción quedan en la tabla `mp_notificaciones` con `sin_cobro = 1` y el motivo en `error`.
