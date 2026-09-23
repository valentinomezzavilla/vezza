<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();

$id = is_string($_GET['id'] ?? null) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id < 1 || !crud_exists('procesos', $id)) {
    pagina_no_encontrada('Ese proceso no existe.', '/admin/procesos', 'Volver a procesos', 'procesos');
}

layout_start('Proceso', 'procesos', ['vendor/sortable.min.js', 'mod-procesos.js', 'proceso.js'], ['proceso-id' => $id]);
?>
<section id="ficha" class="card" aria-live="polite"></section>
<section class="seccion">
  <h2>Subtareas</h2>
  <div id="checklist"></div>
</section>
<?php
layout_end();
