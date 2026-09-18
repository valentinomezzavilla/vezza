# Subir VEZZA a Hostinger

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
