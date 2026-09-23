<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();
layout_start('Agenda', 'agenda', ['mod-eventos.js', 'agenda.js']);
?>
<div class="cal-nav">
  <button type="button" class="btn" id="mes-anterior" aria-label="Mes anterior">‹</button>
  <h2 id="mes-titulo" aria-live="polite"></h2>
  <button type="button" class="btn" id="mes-siguiente" aria-label="Mes siguiente">›</button>
  <button type="button" class="btn" id="mes-hoy">Hoy</button>
</div>
<div id="cal-grid" class="cal-grid"></div>
<div id="cal-lista" class="cal-lista"></div>
<div class="leyenda" aria-hidden="true">
  <span class="t-evento">Evento</span>
  <span class="t-entrega">Entrega</span>
  <span class="t-cobro">Cobro</span>
  <span class="t-cobro vencido">Cobro vencido</span>
  <span class="t-suscripcion">Suscripción</span>
  <span class="t-tarea">Tarea</span>
</div>
<?php
layout_end();
