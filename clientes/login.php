<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

session_boot_cliente();
if (cliente_actual() !== null) {
    header('Location: /clientes', true, 302);
    exit;
}

$aviso = match ($_GET['aviso'] ?? null) {
    'activada' => 'Listo, tu contraseña quedó guardada. Ya podés ingresar.',
    'cambiada' => 'Listo, cambiaste tu contraseña. Ya podés ingresar.',
    default => null,
};
$error = null;
$email = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        csrf_check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null);
        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $clave = is_string($_POST['clave'] ?? null) ? $_POST['clave'] : '';
        $resultado = cliente_auth_attempt($email, $clave, client_ip());
        if ($resultado === 'ok') {
            header('Location: /clientes', true, 303);
            exit;
        }
        $error = $resultado === 'bloqueado'
            ? 'Demasiados intentos, probá en unos minutos.'
            : 'El email o la contraseña no son correctos.';
        http_response_code($resultado === 'bloqueado' ? 429 : 401);
    } catch (HttpError $e) {
        $error = $e->getMessage();
        http_response_code($e->status);
    }
}

portal_inicio('Ingresar', 'portal-auth');
?>
<main id="main" class="portal-auth-card card" tabindex="-1">
  <h1>Tu portal de VEZZA</h1>
  <p>Ingresá para ver tus servicios y tus tickets.</p>
  <?php if ($aviso !== null): ?>
    <div class="aviso" role="status"><?= e($aviso) ?></div>
  <?php endif; ?>
  <?php if ($error !== null): ?>
    <div class="form-error" role="alert"><?= e($error) ?></div>
  <?php endif; ?>
  <form method="post" action="/clientes/login">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="campo">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" value="<?= e($email) ?>" autocomplete="username" autocapitalize="none" required autofocus>
    </div>
    <div class="campo">
      <label for="clave">Contraseña</label>
      <div class="fila-clave">
        <input id="clave" name="clave" type="password" autocomplete="current-password" required>
        <button type="button" class="btn" data-ver-clave="clave" aria-controls="clave" aria-pressed="false">Mostrar</button>
      </div>
    </div>
    <button class="btn btn-primario" type="submit">Ingresar</button>
  </form>
  <p class="portal-enlace"><a href="/clientes/recuperar">Me olvidé la contraseña</a></p>
</main>
<?php
portal_fin();
