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
6. **Carpeta de comprobantes:** en ese mismo nivel creá
   `vezza_uploads/comprobantes/`. El panel la crea solo si tiene permisos, pero
   conviene dejarla hecha.

### Deploys siguientes

- `git push` a `main`, igual que siempre.
- Si el push trae un archivo nuevo en `db/migrations/`, entrá a
  `https://vezzadev.com/admin/migraciones` y tocá **Aplicar migraciones**.
- Si cambiaste `admin/assets/*.css` o `*.js`, subí `PANEL_ASSET_V` en
  `admin/includes/layout.php` antes de hacer push.

### Qué nunca se sube

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
