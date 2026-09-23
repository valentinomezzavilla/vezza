<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();
layout_start('Clientes', 'clientes', ['mod-clientes.js', 'clientes.js']);
?>
<div class="toolbar">
  <input class="filtro" type="search" id="buscar" placeholder="Buscar por nombre" aria-label="Buscar cliente">
  <select class="filtro" id="filtro-estado" aria-label="Filtrar por estado">
    <option value="">Todos los estados</option>
    <option value="activo">Activos</option>
    <option value="pausado">Pausados</option>
    <option value="finalizado">Finalizados</option>
  </select>
</div>
<div id="lista" class="lista" aria-live="polite"></div>
<?php
layout_end();
