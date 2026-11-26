<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

// "Pagué por transferencia" del portal: muestra los datos de la cuenta y el formulario para avisar (fecha, monto, comprobante).
// GET /clientes/transferencia?s={suscripción} · POST con csrf. Una suscripción ajena da 404.
$clienteId = require_cliente();
$crudo = $_POST['suscripcion_id'] ?? $_GET['s'] ?? '';
$suscripcionId = is_string($crudo) && ctype_digit($crudo) ? (int)$crudo : 0;
try {
    $s = portal_suscripcion($clienteId, $suscripcionId);
} catch (HttpError) {
    http_response_code(404);
    portal_inicio('No encontrado');
    echo '<main id="main" class="portal-main"><section class="card"><p>Esa suscripción no existe. <a href="/clientes">Volver al inicio</a></p></section></main>';
    portal_fin();
    exit;
}

$error = null;
$campos = [];
$valores = ['fecha' => hoy(), 'monto' => '', 'nota' => ''];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $valores = [
        'fecha' => is_string($_POST['fecha'] ?? null) ? $_POST['fecha'] : '',
        'monto' => is_string($_POST['monto'] ?? null) ? $_POST['monto'] : '',
        'nota' => is_string($_POST['nota'] ?? null) ? $_POST['nota'] : '',
    ];
    try {
        csrf_check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null);
        $f = $_FILES['archivo'] ?? null;
        $subio = is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_string($f['tmp_name'] ?? null) && is_uploaded_file($f['tmp_name']);
        if (is_array($f) && in_array($f['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new HttpError(422, 'Revisá los datos marcados', ['archivo' => 'El archivo supera los 5 MB']);
        }
        portal_informar_transferencia($clienteId, $suscripcionId, array_filter($valores, fn($v) => $v !== ''),
            $subio ? ['tmp' => $f['tmp_name'], 'bytes' => (int)$f['size']] : null);
        header('Location: /clientes?aviso=transferencia', true, 303);
        exit;
    } catch (HttpError $e) {
        $error = $e->getMessage();
        $campos = $e->campos ?? [];
    }
}

$datos = transferencia_datos();
$motivo = portal_transferencia_motivo($s);
$campoError = fn(string $c): string => isset($campos[$c]) ? '<div class="campo-error" role="alert">' . e($campos[$c]) . '</div>' : '';

portal_inicio('Pagar por transferencia');
?>
<main id="main" class="portal-main" tabindex="-1">
  <header class="page-head">
    <h1>Pagar por transferencia</h1>
    <a class="btn btn-chico" href="/clientes">Volver</a>
  </header>
  <?php if ($motivo !== null): ?>
    <section class="card"><p><?= e($motivo) ?></p></section>
  <?php else: ?>
    <section class="card">
      <h2><?= e((string)$s['plan']) ?> · <?= e(comprobante_monto_texto((string)$s['monto'], (string)$s['moneda'])) ?></h2>
      <p class="item-sub">Transferí a esta cuenta y después avisame acá con el comprobante. Cuando lo confirme, te llega el comprobante de pago por mail.</p>
      <dl class="portal-datos-transferencia">
        <?php foreach (['titular' => 'Titular', 'banco' => 'Banco', 'alias' => 'Alias', 'cbu' => 'CBU / CVU'] as $k => $etiqueta): ?>
          <?php if (!empty($datos[$k])): ?>
            <div><dt class="stat-label"><?= e($etiqueta) ?></dt><dd><code><?= e((string)$datos[$k]) ?></code></dd></div>
          <?php endif; ?>
        <?php endforeach; ?>
      </dl>
    </section>
    <section class="card seccion">
      <h2>Ya transferí</h2>
      <?php if ($error !== null): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post" action="/clientes/transferencia" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="suscripcion_id" value="<?= (int)$s['id'] ?>">
        <div class="campo<?= isset($campos['fecha']) ? ' invalido' : '' ?>">
          <label for="fecha">Fecha de la transferencia *</label>
          <input id="fecha" name="fecha" type="date" required max="<?= e(hoy()) ?>" value="<?= e($valores['fecha']) ?>">
          <?= $campoError('fecha') ?>
        </div>
        <div class="campo<?= isset($campos['monto']) ? ' invalido' : '' ?>">
          <label for="monto">Monto</label>
          <input id="monto" name="monto" inputmode="decimal" placeholder="<?= e(number_format((float)$s['monto'], 0, ',', '.')) ?>" value="<?= e($valores['monto']) ?>">
          <small class="item-sub">Si lo dejás vacío, el de tu plan.</small>
          <?= $campoError('monto') ?>
        </div>
        <div class="campo<?= isset($campos['archivo']) ? ' invalido' : '' ?>">
          <label for="archivo">Comprobante de la transferencia *</label>
          <input id="archivo" name="archivo" type="file" required accept="application/pdf,image/jpeg,image/png,image/webp">
          <small class="item-sub">PDF o imagen, hasta 5 MB.</small>
          <?= $campoError('archivo') ?>
        </div>
        <div class="campo">
          <label for="nota">Nota (opcional)</label>
          <textarea id="nota" name="nota" rows="2" maxlength="300"><?= e($valores['nota']) ?></textarea>
        </div>
        <button class="btn btn-primario" type="submit">Avisar que transferí</button>
      </form>
    </section>
  <?php endif; ?>
</main>
<?php
portal_fin();
