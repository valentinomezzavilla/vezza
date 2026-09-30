<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

if (isset($_GET['token']) || isset($_POST['token'])) {
    portal_pagina_clave(
        'recuperacion',
        'Elegí tu contraseña nueva',
        'Escribí la contraseña con la que vas a ingresar de ahora en adelante.',
        'Guardar contraseña'
    );
    exit;
}

session_boot_cliente();
$enviado = false;
$error = null;
$pendiente = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        csrf_check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null);
        // Misma respuesta exista o no la cuenta, y el trabajo de verdad se hace después de responder:
        // así el tiempo de respuesta tampoco revela qué emails tienen acceso.
        $pendiente = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $enviado = true;
    } catch (HttpError $e) {
        $error = $e->getMessage();
        http_response_code($e->status);
    }
}

portal_inicio('Recuperar contraseña', 'portal-auth');
?>
<main id="main" class="portal-auth-card card" tabindex="-1">
  <h1>Recuperar tu contraseña</h1>
  <?php if ($enviado): ?>
    <div class="aviso" role="status">Si ese email tiene acceso al portal, te mandamos un link para elegir una contraseña nueva. Vale 2 horas.</div>
    <a class="btn" href="/clientes/login">Volver a ingresar</a>
  <?php else: ?>
    <p>Escribí el email con el que ingresás y te mandamos un link.</p>
    <?php if ($error !== null): ?>
      <div class="form-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/clientes/recuperar">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="campo">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="username" autocapitalize="none" required autofocus>
      </div>
      <button class="btn btn-primario" type="submit">Mandame el link</button>
    </form>
    <p class="portal-enlace"><a href="/clientes/login">Volver a ingresar</a></p>
  <?php endif; ?>
</main>
<?php
portal_fin();
if ($pendiente !== null) {
    responder_y_seguir();
    cliente_recuperar_solicitar($pendiente, client_ip());
}
