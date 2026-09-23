<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();
layout_start('Reportes', 'reportes', ['reportes.js']);
?>
<form id="filtros" class="toolbar">
  <label class="campo">Desde<input type="date" id="desde" class="filtro" required></label>
  <label class="campo">Hasta<input type="date" id="hasta" class="filtro" required></label>
  <label class="campo">Cliente
    <select id="cliente" class="filtro"><option value="">Todos los clientes</option></select>
  </label>
  <button type="submit" class="btn btn-primario">Ver reporte</button>
</form>
<div id="reporte" aria-live="polite"></div>
<?php
layout_end();
