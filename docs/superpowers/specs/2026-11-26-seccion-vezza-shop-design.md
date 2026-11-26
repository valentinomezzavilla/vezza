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

## Addendum 2: reproductor común (controles nativos)

Valentino pidió que el video sea "un reproductor común y corriente", con los
botones adentro del video y sin botones propios por fuera. Reemplaza a la
primera versión de este addendum (botones "Pausar" y "Pantalla completa"
propios), que se descartó. Decisiones de quien lo construyó:

- El `<video>` usa los controles del navegador (`controls`): play y pausa,
  tiempo, barra, pantalla completa y menú. `controlsList="nodownload
  noremoteplayback"`. No hay botones propios en el HTML, el CSS ni el JS.
- Trae `src` (16:9) y `poster` desde el HTML, con `preload="none"`: no baja
  nada hasta que se reproduce, y sin JS ya es un reproductor que funciona.
  `js/main.js` cambia `src` y `poster` al formato del ancho antes de que se
  descargue algo. Los `?v=` del `src` y del `poster` y el `data-version` se
  suben juntos si se reemplaza un archivo.
- Sigue arrancando solo, mudo y en loop cuando se ve (300 ms a la vista), se
  pausa al salir de pantalla y no arranca solo con "reducir movimiento" o
  ahorro de datos. Si la persona lo pausa con los controles, no se reanuda
  solo; si lo pausa el script al salir de pantalla, sí.
- En pantalla completa (la del botón nativo) elige el formato más parecido a
  la forma de la pantalla (9:16, 4:5, 1:1 o 16:9), sigue en el mismo segundo
  y se muestra entero (`object-fit: contain`); al salir vuelve al formato del
  ancho. En pantalla completa no rige la pausa por salir de pantalla.
- Tras este cambio no queda código de los botones propios.
