<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();
layout_start('Cobros', 'cobros', ['mod-cobros.js', 'mod-suscripciones.js', 'mod-informados.js', 'cobros.js']);
?>
<section id="informados" class="card" hidden></section>
<div class="toolbar">
  <select class="filtro" id="filtro-cliente" aria-label="Filtrar por cliente">
    <option value="">Todos los clientes</option>
  </select>
</div>
<div id="cobros"></div>
<?php
layout_end();
