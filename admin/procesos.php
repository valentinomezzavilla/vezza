<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();
layout_start('Procesos', 'procesos', ['vendor/sortable.min.js', 'kanban.js', 'mod-procesos.js', 'procesos.js']);
?>
<div class="toolbar">
  <select class="filtro" id="filtro-cliente" aria-label="Filtrar por cliente">
    <option value="">Todos los clientes</option>
  </select>
  <select class="filtro" id="filtro-estado" aria-label="Filtrar por estado">
    <option value="">Todos los estados</option>
    <option value="por_hacer">Por hacer</option>
    <option value="en_curso">En curso</option>
    <option value="en_revision">En revisión</option>
    <option value="entregado">Entregado</option>
  </select>
  <button type="button" class="btn" id="vista-kanban" aria-pressed="true">Tablero</button>
  <button type="button" class="btn" id="vista-lista" aria-pressed="false">Lista</button>
</div>
<div id="contenido" aria-live="polite"></div>
<?php
layout_end();
