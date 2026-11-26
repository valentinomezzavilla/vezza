<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

$clienteId = require_cliente();
$cuenta = portal_cuenta($clienteId);

// Lo que vuelve de un pago o de un aviso (?aviso=...): solo textos fijos, nunca lo que venga en la URL.
$avisos = [
    'aprobado' => ['ok', 'Listo: Mercado Pago aprobó tu pago. En unos minutos se acredita y te llega el comprobante por mail.'],
    'pendiente' => ['warn', 'Tu pago quedó pendiente de acreditación (por ejemplo, si pagaste en efectivo). Cuando se acredite te llega el comprobante por mail.'],
    'rechazado' => ['danger', 'Mercado Pago no aprobó el pago. Podés probar de nuevo con otro medio.'],
    'transferencia' => ['ok', 'Recibí el aviso de tu transferencia. Cuando la confirme te llega el comprobante por mail.'],
    'debito' => ['ok', 'Listo: cuando Mercado Pago confirme el débito automático lo vas a ver acá.'],
    'no_mp' => ['danger', 'No pudimos iniciar el pago con Mercado Pago. Probá de nuevo en un rato o pagá por transferencia.'],
    'muchos' => ['warn', 'Iniciaste muchos pagos seguidos. Esperá un rato y probá de nuevo.'],
];
$aviso = $avisos[is_string($_GET['aviso'] ?? null) ? $_GET['aviso'] : ''] ?? null;

portal_inicio('Mi cuenta');
?>
<main id="main" class="portal-main" tabindex="-1">
  <header class="page-head">
    <h1>Hola, <?= e($cuenta['nombre']) ?></h1>
    <form method="post" action="/clientes/logout">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <button class="btn btn-chico" type="submit">Salir</button>
    </form>
  </header>
  <?php if ($aviso !== null): ?>
    <div class="aviso aviso-<?= e($aviso[0]) ?>" role="status"><?= e($aviso[1]) ?></div>
  <?php endif; ?>
  <section class="card">
    <h2>Mi suscripción</h2>
    <?php if ($cuenta['suscripciones']): ?>
      <div class="lista seccion">
        <?php foreach ($cuenta['suscripciones'] as $s): ?>
          <?= portal_suscripcion_html($s, portal_acciones_pago($s)) ?>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="item-sub seccion">Todavía no tenés un plan contratado.</p>
    <?php endif; ?>
  </section>
  <?= portal_pagos_html($cuenta) ?>
</main>
<?php
portal_fin();
