<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();
layout_start('Fixs', 'fixs', ['vendor/sortable.min.js', 'kanban.js', 'mod-fixs.js', 'fixs.js']);
?>
<div class="toolbar">
  <select class="filtro" id="filtro-cliente" aria-label="Filtrar por cliente">
    <option value="">Todos los clientes</option>
  </select>
</div>
<div id="tablero" aria-live="polite"></div>
<?php
layout_end();
