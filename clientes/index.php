<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

$clienteId = require_cliente();
$nombre = (string)q_val('SELECT nombre FROM clientes WHERE id = ?', [$clienteId]);

portal_inicio('Inicio');
?>
<main id="main" class="portal-main" tabindex="-1">
  <header class="page-head">
    <h1>Hola, <?= e($nombre) ?></h1>
    <form method="post" action="/clientes/logout">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <button class="btn btn-chico" type="submit">Salir</button>
    </form>
  </header>
  <section class="card">
    <p>Desde acá vas a poder ver el estado de tus servicios, gestionar tus suscripciones y abrir tickets. Lo estamos terminando de armar.</p>
  </section>
</main>
<?php
portal_fin();
