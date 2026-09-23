<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();
layout_start('Gastos', 'gastos', ['mod-gastos.js', 'gastos.js']);
?>
<section>
  <h2>Suscripciones</h2>
  <div id="suscripciones" class="lista seccion"></div>
</section>
<section class="seccion">
  <h2>Gastos</h2>
  <form id="filtros-gastos" class="toolbar seccion">
    <label class="campo">Desde<input type="date" id="filtro-desde" class="filtro"></label>
    <label class="campo">Hasta<input type="date" id="filtro-hasta" class="filtro"></label>
    <label class="campo">Categoría<input id="filtro-categoria" class="filtro" list="categorias" placeholder="Todas"></label>
    <datalist id="categorias"></datalist>
  </form>
  <div id="gastos"></div>
</section>
<?php
layout_end();
