<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();

$id = is_string($_GET['id'] ?? null) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id < 1 || !crud_exists('clientes', $id)) {
    pagina_no_encontrada('Ese cliente no existe.', '/admin/clientes', 'Volver a clientes', 'clientes');
}

layout_start('Cliente', 'clientes', [
    'mod-clientes.js', 'mod-procesos.js', 'mod-cobros.js', 'mod-fixs.js', 'mod-eventos.js', 'mod-acceso.js', 'mod-suscripciones.js', 'mod-sistemas.js', 'mod-informados.js', 'cliente.js',
], ['cliente-id' => $id]);
?>
<section id="ficha" class="card" aria-live="polite"></section>
<section id="acceso" class="card seccion" aria-live="polite"></section>
<section id="informados" class="card seccion" hidden></section>
<section id="suscripcion" class="card seccion" aria-live="polite"></section>
<section id="sistemas" class="card seccion" aria-live="polite"></section>
<section id="pestanas" class="seccion"></section>
<?php
layout_end();
