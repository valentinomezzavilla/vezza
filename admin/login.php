<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

session_boot();
$nextCrudo = $_GET['next'] ?? $_POST['next'] ?? null;
$next = safe_next(is_string($nextCrudo) ? $nextCrudo : null);

if (auth_logged()) {
    header('Location: ' . $next, true, 302);
    exit;
}

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $token = $_POST['csrf'] ?? null;
        csrf_check(is_string($token) ? $token : null);
        $usuario = is_string($_POST['usuario'] ?? null) ? $_POST['usuario'] : '';
        $clave = is_string($_POST['clave'] ?? null) ? $_POST['clave'] : '';
        $resultado = auth_attempt($usuario, $clave, client_ip());
        if ($resultado === 'ok') {
            header('Location: ' . $next, true, 303);
            exit;
        }
        $error = $resultado === 'bloqueado'
            ? 'Demasiados intentos, probá en unos minutos.'
            : 'Usuario o contraseña incorrectos.';
        http_response_code($resultado === 'bloqueado' ? 429 : 401);
    } catch (HttpError $e) {
        $error = $e->getMessage();
        http_response_code($e->status);
    }
}

send_panel_headers();
header('Content-Type: text/html; charset=utf-8');
layout_head('Ingresar');
?>
<script src="/admin/assets/login.js?v=<?= PANEL_ASSET_V ?>" defer></script>
</head>
<body class="login-body">
<main class="login card">
  <h1>VEZZA Admin</h1>
  <p>Ingresá para ver el panel.</p>
  <?php if ($error !== null): ?>
    <div class="form-error" role="alert"><?= e($error) ?></div>
  <?php endif; ?>
  <form method="post" action="/admin-login">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <div class="campo">
      <label for="usuario">Usuario</label>
      <input id="usuario" name="usuario" autocomplete="username" autocapitalize="none" required autofocus>
    </div>
    <div class="campo">
      <label for="clave">Contraseña</label>
      <div class="fila-clave">
        <input id="clave" name="clave" type="password" autocomplete="current-password" required>
        <button type="button" class="btn" id="ver-clave" aria-controls="clave" aria-pressed="false">Mostrar</button>
      </div>
    </div>
    <button class="btn btn-primario" type="submit">Ingresar</button>
  </form>
</main>
</body>
</html>
