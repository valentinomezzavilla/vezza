<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();
layout_start('Monitor', 'monitor', ['mod-monitor.js', 'monitor.js']);
?>
<section>
  <div id="resumen" class="monitor-resumen" role="status"></div>
  <div id="servicios"></div>
</section>
<section class="seccion">
  <h2>Historial de caídas</h2>
  <div id="eventos"></div>
</section>
<?php
layout_end();
