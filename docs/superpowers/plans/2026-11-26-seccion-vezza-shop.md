# Sección VEZZA Shop Implementation Plan

> **Nota:** después de armar este plan, VEZZA Shop pasó a vivir dentro de la fila E-commerce de Servicios (ver el addendum del spec). Donde dice sección `#shop` suelta, leer "fila E-commerce"; el resto vale igual.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agregar a la landing una sección `#shop` con el video demo del formato que corresponde a cada ancho de pantalla y un botón a https://tiendas.vezzadev.com.

**Architecture:** Los cortes de ancho viven una sola vez, en el CSS, como la variable `--shop-format` (`9x16`, `4x5`, `1x1` o `16x9`) sobre `.shop__frame`; el mismo CSS fija el `aspect-ratio` de cada formato, así no hay salto de layout. El JS lee esa variable, elige el archivo de `assets/video/` y lo descarga solo cuando la sección está por verse.

**Tech Stack:** HTML/CSS/JS puro (sin build), ffmpeg para recomprimir, servidor PHP local solo para mirar la página.

**Spec:** `docs/superpowers/specs/2026-11-26-seccion-vezza-shop-design.md`

## Global Constraints

- Cortes de ancho: menos de 600 px → 9:16 · 600 a 899 → 4:5 · 900 a 1199 → 1:1 · 1200 o más → 16:9 (en CSS: `37.5em`, `56.25em`, `75em`).
- Botón primario: texto "Conocer VEZZA Shop", clases `btn btn--primary btn--lg`, destino `https://tiendas.vezzadev.com`, misma pestaña. Sin "Acceder", sin cambios en el menú.
- Sección `#shop` justo después del hero, fondo claro (sin `section--invert`), videos sin marco.
- Archivos publicados en `assets/video/`: `vezza-shop-{9x16,4x5,1x1,16x9}.mp4` y `.jpg`. Ningún nombre con DRAFT. `TIENDAS/` no se toca (es la fuente y sigue en `.gitignore`).
- Voz: voseo y primera persona singular (como "Te acompaño"). Tema claro, Manrope + Sora.
- Respetar `prefers-reduced-motion` y `navigator.connection.saveData`; botón de pausa siempre disponible.
- HTML/CSS/JS puro y el estilo de `js/main.js` (IIFE, `var`, funciones `initXxx`). Sin dependencias nuevas.
- No hacer commit ni push salvo que Valentino lo pida.
- Verificación: no hay tests automáticos para la landing (los de `tests/` son del panel PHP). Se verifica con ffprobe y en el navegador, con los resultados esperados de cada paso.

## Review Focus

Entradas o condiciones que el spec implica y que más probablemente muerdan; cada una tiene su paso de verificación en la Task 3.

1. Autoplay bloqueado por el navegador (ahorro de batería en iOS): `play()` falla; no debe haber error ni botón mintiendo, queda el póster y "Reproducir".
2. Rotar o cambiar el ancho al cruzar un corte con el video andando: pasa al otro formato en el mismo segundo y estado (los cuatro duran 47,13 s), sin quedar en negro.
3. Ahorro de datos o reducir movimiento: no se pide ningún `.mp4` hasta que la persona toca "Reproducir".
4. La sección nunca se ve (se entra directo por `#contacto`): no se pide ningún `.mp4`.
5. Sin JS o con el video roto (404, sin red): queda el póster y el botón "Conocer VEZZA Shop" funciona; el botón de pausa no se muestra.

---

### Task 1: Videos publicados, `.htaccess` y DEPLOY.md

**Files:**
- Create: `assets/video/vezza-shop-{9x16,4x5,1x1,16x9}.mp4` y `.jpg` (8 archivos)
- Modify: `.htaccess` (bloques de tipos de archivo y caché, líneas 36-72)
- Modify: `DEPLOY.md` (lista de archivos a subir a `public_html`, líneas 21-37)

**Interfaces:**
- Produces: rutas `assets/video/vezza-shop-<fmt>.mp4` y `assets/video/vezza-shop-<fmt>.jpg` para `<fmt>` en `9x16 | 4x5 | 1x1 | 16x9`. La Task 3 arma las rutas con ese patrón.

- [ ] **Step 1: Recomprimir los cuatro videos.** Desde la raíz del repo, uno por formato: `ffmpeg -i TIENDAS/<origen> -vf scale=<W>:<H> -c:v libx264 -crf 27 -preset slow -pix_fmt yuv420p -movflags +faststart -an assets/video/vezza-shop-<fmt>.mp4`. Orígenes y tamaños: `vezza-demo-9x16-historias-DRAFT.mp4` → 720x1280; `vezza-demo-4x5-post-DRAFT.mp4` → 864x1080; `vezza-demo-1x1-post-DRAFT.mp4` → 960x960; `vezza-demo-16x9-DRAFT.mp4` → 1600x900.
- [ ] **Step 2: Verificar peso y duración.** Run: `ffprobe -v error -show_entries format=duration,size -of compact=p=0 assets/video/vezza-shop-<fmt>.mp4` (los cuatro). Expected: `duration` ≈ 47.13 en todos y `size` bastante menor que el origen (objetivo: 5 MB o menos; si alguno pasa, repetir ese con `-crf 29`).
- [ ] **Step 3: Generar los pósters.** Run: `ffmpeg -ss 2 -i assets/video/vezza-shop-<fmt>.mp4 -frames:v 1 -q:v 4 assets/video/vezza-shop-<fmt>.jpg` (los cuatro). Mirar cada JPG con la herramienta Read: tienen que mostrar el título y el mockup del navegador, sin texto cortado; si el segundo 2 cae en una transición, elegir otro y regenerar.
- [ ] **Step 4: Verificar que el texto sigue legible.** Extraer un frame del `.mp4` nuevo del 9x16 en el segundo 12 y mirarlo: "Filtros para encontrar rápido" y los botones se leen igual que en el origen. Si no, bajar el `-crf`.
- [ ] **Step 5: `.htaccess`.** En `<IfModule mod_mime.c>` agregar `AddType video/mp4 .mp4`; en `mod_expires` agregar `ExpiresByType video/mp4 "access plus 1 year"`; en el bloque `mod_headers` que ya pone `Cache-Control` de un año para estáticos, sumar `mp4` a la lista de extensiones si esa lista existe (leer el bloque antes de tocar). Los `.mp4` no se comprimen (ya están comprimidos).
- [ ] **Step 6: `DEPLOY.md`.** En el árbol de `public_html/` agregar `assets/video/` junto a `assets/icons/` e `assets/img/`, con una línea: los videos se versionan con `data-version` en `index.html` (ver Task 3) porque el navegador los guarda un año.
- [ ] **Step 7: Verificación diferida.** `.htaccess` no se puede probar en local. Anotar en el reporte final que después del primer deploy hay que correr `curl -I https://vezzadev.com/assets/video/vezza-shop-16x9.mp4` y esperar `Content-Type: video/mp4`, `Accept-Ranges: bytes` y caché de un año.

---

### Task 2: Marcado y CSS de la sección (sin JS)

**Files:**
- Modify: `index.html` (insertar entre el cierre del hero, línea 145, y `<!-- SERVICIOS -->`, línea 147; subir `css/styles.css?v=1` → `?v=2`, línea 46)
- Modify: `css/styles.css` (bloque nuevo `/* ---------- VEZZA Shop ---------- */` después del hero, cerca de la línea 433, antes de `.section`)

**Interfaces:**
- Consumes: tokens y clases existentes de `css/styles.css` (`.section`, `.container`, `.label`, `.h2`, `.lead`, `.btn`, `.btn--primary`, `.btn--lg`, variables `--space-*`, `--dur`, `--ease-out`, `--bg`, `--ink`, `--accent`). Leerlas antes de escribir; no inventar variables.
- Produces (la Task 3 depende de estos nombres exactos):
  - `<section class="section shop" id="shop" aria-labelledby="shop-title" data-shop>`
  - `.shop__frame[data-version="1"]` que contiene `<video class="shop__video" data-shop-video muted loop playsinline preload="none" poster="assets/video/vezza-shop-16x9.jpg" aria-label="Demostración de VEZZA Shop: una tienda online y su panel de pedidos">` (sin `src`) y `<button class="shop__toggle" type="button" data-shop-toggle hidden>Pausar</button>`.
  - Variable CSS `--shop-format` en `.shop__frame`, con valor `16x9` por defecto y `9x16`, `4x5`, `1x1` en los cortes. Valor sin comillas, tal como lo va a leer `getComputedStyle(...).getPropertyValue('--shop-format').trim()`.

- [ ] **Step 1: Marcado.** Etiqueta `<p class="label">VEZZA Shop</p>`; `<h2 class="h2" id="shop-title">Tu tienda online, sin vueltas.</h2>`; bajada `<p class="lead">` con este texto: "Armé una plataforma para que vendas por internet con tu propio link: catálogo, carrito, checkout y un panel para manejar pedidos y productos. Probala 7 días gratis."; luego `.shop__frame`; luego `<div class="shop__actions">` con `<a class="btn btn--primary btn--lg" href="https://tiendas.vezzadev.com">Conocer VEZZA Shop <span aria-hidden="true">→</span></a>`. El `<h2>` va dentro del `.container` como en las demás secciones.
- [ ] **Step 2: CSS del marco.** `.shop__frame` centrado, ancho `min(100%, <máx del formato>)`, `aspect-ratio` por formato según `--shop-format`, `position: relative`, `overflow: hidden`, `border-radius` chico (el video no lleva borde ni sombra). Máximos: 9:16 → 22rem, 4:5 → 32rem, 1:1 → 36rem, 16:9 → 100% del `.container`. `.shop__video` ocupa el marco entero con `object-fit: cover`.
- [ ] **Step 3: CSS de los cortes.** Con `min-width` y orden creciente: por defecto `--shop-format: 9x16`; `@media (min-width: 37.5em)` → `4x5`; `(min-width: 56.25em)` → `1x1`; `(min-width: 75em)` → `16x9`. Cada media query también fija el `aspect-ratio` y el ancho máximo de ese formato. Sin JS el póster del HTML es 16:9: para que no se deforme en los cortes chicos, `.shop__video` usa `object-fit: cover`.
- [ ] **Step 4: CSS del botón de pausa.** `.shop__toggle`: píldora de al menos 44 px de alto, texto visible, fondo `--paper`/`--bg` con borde `--ink`, `:focus-visible` heredado, en una esquina donde no tape el título ni el mockup (abajo a la izquierda está libre en 16:9 y 9:16; revisar los cuatro formatos). `.shop__actions` centrado con el mismo espacio que usa `.hero__actions`.
- [ ] **Step 5: Verificar en navegador (solo CSS).** Levantar el servidor: `<php.exe de C:\Users\valen\.tools\php-vezza> -S 127.0.0.1:8099 -t C:\Users\valen\OneDrive\Escritorio\VEZZA` en segundo plano (ver memoria `entorno-local-de-tests`). Con las herramientas de Chrome: abrir `http://127.0.0.1:8099/`, y con `resize_window` a 390, 700, 1000 y 1400 de ancho correr con `javascript_tool`: `getComputedStyle(document.querySelector('.shop__frame')).getPropertyValue('--shop-format').trim()`. Expected: `9x16`, `4x5`, `1x1`, `16x9` en ese orden. En cada ancho sacar una captura: la sección va después del hero, el póster se ve completo, el botón "Conocer VEZZA Shop" se ve y no hay scroll horizontal (`document.documentElement.scrollWidth <= innerWidth`).

---

### Task 3: Comportamiento del video (`js/main.js`) y verificación completa

**Files:**
- Modify: `js/main.js` (función nueva junto a las otras `initXxx` y su llamada donde se inicializan las demás; subir `js/main.js?v=1` → `?v=2` en `index.html`, línea 50)

**Interfaces:**
- Consumes: los nombres de la Task 2 (`[data-shop]`, `[data-shop-video]`, `[data-shop-toggle]`, `.shop__frame[data-version]`, `--shop-format`) y las rutas de la Task 1.
- Produces: `initShopVideo()` sin parámetros ni retorno. Helpers internos, en este orden:
  - `currentFormat(): string` lee `--shop-format` del marco.
  - `fileFor(fmt: string, ext: 'mp4' | 'jpg'): string` devuelve `assets/video/vezza-shop-<fmt>.<ext>?v=<data-version>`.
  - `autoplayAllowed(): boolean` es `false` si `matchMedia('(prefers-reduced-motion: reduce)').matches` o `navigator.connection && navigator.connection.saveData`.
  - `load(fmt: string): void` fija `video.poster` y `video.src`; si ya había `src` conserva `currentTime` y si estaba reproduciendo vuelve a reproducir.
  - `setLabel(playing: boolean): void` pone "Pausar" o "Reproducir" en el botón.

- [ ] **Step 1: Estado inicial.** `initShopVideo()` sale si falta `[data-shop]`. Fija enseguida el póster del formato actual (`fileFor(currentFormat(), 'jpg')`) para que no se vea el del 16:9 recortado en un marco vertical. Muestra el botón (`hidden = false`) solo si el `<video>` existe, y lo deja en "Reproducir" porque todavía no hay `src`.
- [ ] **Step 2: Carga diferida.** Con `IntersectionObserver` (margen de 200 px, un solo disparo) sobre la sección: al entrar, si `autoplayAllowed()` es `true`, `load(currentFormat())` y `play()`. Si no hay `IntersectionObserver`, tratarlo como visible. Si `autoplayAllowed()` es `false`, no se carga nada hasta que se toque el botón.
- [ ] **Step 3: Botón.** Clic: si no hay `src` → `load(currentFormat())` y `play()`; si está reproduciendo → `pause()` y recordar que fue una pausa de la persona; si está en pausa → `play()`. `setLabel` sigue a los eventos `play` y `pause` del `<video>`, no al clic, para no mentir.
- [ ] **Step 4: Pausar al salir.** Otro observer (sin margen): al salir de pantalla pausa solo si estaba reproduciendo por autoplay; al volver reanuda solo si la persona no lo pausó a mano ni `autoplayAllowed()` es `false`.
- [ ] **Step 5: Cambio de formato.** En `resize` (con `requestAnimationFrame`, sin otro debounce) comparar `currentFormat()` con el último cargado; si cambió y ya hay `src`, `load(nuevo)`. Si todavía no hay `src`, solo actualizar el póster.
- [ ] **Step 6: Errores.** `play()` devuelve una promesa: capturar el rechazo y dejar el botón en "Reproducir" sin error en consola. En el evento `error` del `<video>`: ocultar el botón (`hidden = true`) y dejar el póster.
- [ ] **Step 7: Verificar los caminos del Review Focus en el navegador** (servidor de la Task 2 andando). Con `read_network_requests` filtrando `.mp4` y `javascript_tool` para `video.paused`, `video.currentSrc` y el texto del botón:
  - **Un solo archivo por ancho.** A 390, 700, 1000 y 1400 de ancho, recargar, bajar hasta la sección y esperar 2 s. Expected: exactamente un `.mp4` pedido (`9x16`, `4x5`, `1x1`, `16x9` respectivamente), `paused === false`, botón "Pausar".
  - **Pausa y reanudación.** Clic en el botón. Expected: `paused === true`, botón "Reproducir"; scrollear fuera y volver: sigue en pausa. Otro clic: reproduce.
  - **Pausa al salir.** Sin tocar el botón, scrollear hasta el contacto. Expected: `paused === true`; al volver, `paused === false`.
  - **Cambio de corte (Review Focus 2).** Con el video en el segundo ~5 a 1400 px, `resize_window` a 390. Expected: `currentSrc` pasa a `...9x16.mp4?v=1`, `currentTime` ≥ 4,5 (no vuelve a 0) y sigue reproduciendo; en la captura no hay recuadro negro.
  - **Sección que no se ve (Review Focus 4).** Abrir `http://127.0.0.1:8099/#contacto`. Expected: cero pedidos `.mp4`; al subir hasta la sección, empieza a cargar.
  - **Ahorro de datos (Review Focus 3).** Copiar `index.html` a `index.savedata.html` en la raíz y agregarle, antes del `<script src="js/main.js...">`, `<script>Object.defineProperty(navigator,'connection',{value:{saveData:true}})</script>`; abrirlo. Expected: cero pedidos `.mp4`, póster visible, botón "Reproducir"; clic → se pide un `.mp4` y reproduce. Borrar `index.savedata.html` al terminar.
  - **Video roto (Review Focus 5).** Con `javascript_tool`, `document.querySelector('[data-shop-video]').src = 'assets/video/no-existe.mp4'`. Expected: el botón queda oculto, el póster se ve y "Conocer VEZZA Shop" sigue llevando a `https://tiendas.vezzadev.com` (`href` correcto, sin `target`).
  - **Autoplay bloqueado (Review Focus 1).** Con `javascript_tool` reemplazar `HTMLMediaElement.prototype.play` por una función que devuelve `Promise.reject(new DOMException('x','NotAllowedError'))`, recargar el marco con un `resize_window` que cambie de formato y `read_console_messages`. Expected: sin errores no capturados, botón "Reproducir".
  - **Sin JS.** Comprobar en el HTML que el `<video>` tiene `poster` del 16:9 y no tiene `src`, y que el botón de pausa está `hidden`; el enlace a tiendas existe en el marcado.
- [ ] **Step 8: Revisión visual final.** Una captura por ancho (390, 700, 1000, 1400). Revisar si en 9:16 y 1:1 sobra demasiado aire arriba y abajo; si sí, recortar con `aspect-ratio` más bajo en ese formato (el video ya usa `object-fit: cover`) y repetir la captura. Detener el servidor PHP y reportar qué se verificó, qué no (el `.htaccess` queda para después del deploy) y que no se hizo commit.
