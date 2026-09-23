<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();

$aplicadas = null;
$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $token = $_POST['csrf'] ?? null;
        csrf_check(is_string($token) ? $token : null);
        $aplicadas = migrar(db());
    } catch (HttpError $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[panel] ' . $e);
        $error = 'Falló la migración: ' . $e->getMessage();
    }
}
$pendientes = array_keys(migraciones_pendientes(db()));

layout_start('Migraciones', 'migraciones');
?>
<?php if ($error !== null): ?>
  <div class="form-error" role="alert"><?= e($error) ?></div>
<?php endif; ?>
<?php if ($aplicadas !== null): ?>
  <div class="aviso"><?= $aplicadas ? 'Aplicadas: ' . e(implode(', ', $aplicadas)) : 'No había migraciones pendientes.' ?></div>
<?php endif; ?>
<section class="card">
  <?php if ($pendientes): ?>
    <p>Hay <?= count($pendientes) ?> migración(es) pendiente(s):</p>
    <ul><?php foreach ($pendientes as $nombre): ?><li><?= e($nombre) ?></li><?php endforeach; ?></ul>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <button class="btn btn-primario" type="submit">Aplicar migraciones</button>
    </form>
  <?php else: ?>
    <p>La base está al día.</p>
  <?php endif; ?>
</section>
<?php
layout_end();
