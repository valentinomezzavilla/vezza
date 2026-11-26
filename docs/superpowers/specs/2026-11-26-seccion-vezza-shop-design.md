# Sección VEZZA Shop en la landing (video responsive + botón)

Estado: aprobado por Valentino el 2026-11-26. Cambio chico.

## Addendum (pedido posterior de Valentino, mismo día)

VEZZA Shop ya no es una sección suelta `#shop` después del hero: **vive dentro
de la fila "E-commerce" del listado de Servicios**, y esa fila pasó a ser la
**primera** (01); el resto se renumeró 02 a 06 y los datos estructurados
(JSON-LD) siguen el mismo orden. Todo lo demás de este documento (formatos por
ancho, carga diferida, pausa, botón, archivos en `assets/video/`) sigue igual.
Lo que cambió, como decisiones de quien lo construyó:

- La fila E-commerce lleva `data-sticky`: arranca abierta y solo se abre o
  cierra con **su propio clic**. Con un panel de ~600 a 700 px, abrirla por
  hover o cerrarla cuando se abre otra fila hacía saltar la lista y cerraba la
  fila recién clickeada; y al tabular hacia el botón de pausa se cerraba sola.
  Las otras filas conservan el comportamiento de siempre.
- Se quitaron la etiqueta y el título propios de la sección (la fila ya se
  llama E-commerce); el texto de VEZZA Shop se sumó al párrafo de la fila.
- El texto del panel arranca en la misma sangría que el de las otras filas
  (variable `--svc-indent`); en celular el video usa todo el ancho.
- La carga diferida sigue funcionando sola: con la fila cerrada el video queda
  recortado y no se descarga ni se reproduce nada.

## Intención

VEZZA Shop (https://tiendas.vezzadev.com) es la plataforma de tiendas online
de VEZZA y vive de lo que trae esta landing. La landing necesita una sección
que la muestre en acción y lleve a ese sitio.

Qué hace Shop, leído de tiendas.vezzadev.com: cada negocio tiene una vidriera
pública (`tunegocio.vezzashop.com`) y un panel `/admin` (pedidos, productos,
carga por Excel, diseño, envíos y pagos). Alta en `/suscribirse`.

## Objetivos

- Sección nueva `#shop` justo después del hero, sobre fondo claro.
- Mostrar el video demo con el formato que corresponde a cada ancho de pantalla.
- Un botón primario que lleve a `https://tiendas.vezzadev.com`.

## No objetivos

- No se toca el portal, el panel `/admin` ni el sitio de tiendas.
- No se agrega "Acceder" (es para quien ya tiene tienda; el sitio de tiendas
  ya trae "Ingresar a mi panel"). No se cambia el menú.

## Antiobjetivos (lo que lo haría fallar aunque "funcione")

- Que el visitante descargue los cuatro videos, o uno entero antes de ver la
  sección: en celular pesa en datos y en velocidad.
- Que el video se reproduzca sin forma de pausarlo o ignorando "reducir
  movimiento".
- Que se publique un archivo con DRAFT en el nombre o que el deploy deje la
  sección sin videos.

## Restricciones encontradas

- `/TIENDAS/` está en `.gitignore` (commit `efc31de`) y el deploy copia el repo
  a `public_html`: nada de esa carpeta llega a producción.
- Los 4 videos: 47 s, H.264, sin audio, 9 a 13 MB cada uno, nombre con DRAFT.
  1920x1080 (16:9), 1080x1080 (1:1), 1080x1350 (4:5), 1080x1920 (9:16).
- Fondo de los videos `#F4F2EE` frente al `--bg` de la landing `#F5F3EE`: se
  funden, no necesitan marco. El 9:16 y el 1:1 traen aire arriba y abajo
  (pensados para historias y posts); se mira en pantalla real y, si sobra
  demasiado, se recorta con `object-fit: cover`.
- `<source media>` dentro de `<video>` no lo respetan los navegadores.
- `.htaccess` no define caché ni tipo MIME para `.mp4`.
- Marca: tema claro, Manrope + Sora, voseo; HTML/CSS/JS puro.

## Enfoques considerados

1. **JS elige el archivo con `matchMedia` y carga uno solo** (elegido). Baja un
   video, solo cuando la sección está por verse, y se adapta al rotar.
2. `<source media>` solo con HTML: no funciona en los navegadores principales.
3. Un único video (16:9) para todos: descarta lo que Valentino hizo para cada
   formato y en celular se ve chico.

## Diseño

Elección de video por ancho de ventana (se vuelve a evaluar al rotar o
redimensionar, con los mismos cortes que usa el CSS):

| Ancho | Video |
|---|---|
| menos de 600 px | 9:16 |
| 600 a 899 px | 4:5 |
| 900 a 1199 px | 1:1 |
| 1200 px o más | 16:9 |

- Archivos publicados en `assets/video/` (copias recomprimidas, sin DRAFT):
  `vezza-shop-9x16.mp4`, `vezza-shop-4x5.mp4`, `vezza-shop-1x1.mp4`,
  `vezza-shop-16x9.mp4`, más un póster `.jpg` por formato. `TIENDAS/` sigue
  siendo la fuente.
- Marcado: `<video muted loop playsinline preload="none" poster=...>` sin
  `src`; el JS asigna el `src` del formato elegido cuando la sección se acerca
  a la pantalla (`IntersectionObserver`).
- Reproducción: se reproduce mientras se ve y se pausa al salir. Botón de
  pausa/play visible. Con `prefers-reduced-motion: reduce` o
  `navigator.connection.saveData` queda el póster con botón de play y no se
  descarga nada hasta que la persona lo pide.
- Sin JS: se ve el póster del 16:9 y el botón sigue funcionando.
- Contenido: etiqueta "VEZZA Shop", título, una bajada con la voz de la landing,
  el video y el botón primario grande "Conocer VEZZA Shop →" a
  `https://tiendas.vezzadev.com` (misma pestaña).
- Archivos que se tocan: `index.html`, `css/styles.css`, `js/main.js`,
  `.htaccess` (`AddType video/mp4 .mp4` y caché de 1 año para `video/mp4`),
  y se sube el `?v=` de CSS y JS.

## Qué queda para quien lo construya

- Parámetros de recompresión de los videos (objetivo: bastante más livianos sin
  que se note en texto chico) y el segundo del póster.
- Texto exacto del título y la bajada, en voseo y primera persona singular.
- Medidas máximas del video por formato para que no queden gigantes.
- Verificar en navegador real: celular, tablet y compu, rotación, pausa y
  "reducir movimiento".
