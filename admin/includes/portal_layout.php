<?php
declare(strict_types=1);

const PORTAL_ASSET_V = '1';

/** Imprime el <head> y abre el <body>. La sesión del portal ya tiene que estar arrancada. */
function portal_inicio(string $titulo, string $claseBody = ''): void
{
    send_panel_headers();
    header('Content-Type: text/html; charset=utf-8');
    $v = PORTAL_ASSET_V;
    $pv = PANEL_ASSET_V;
    ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#F5F3EE">
<meta name="color-scheme" content="light">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($titulo) ?> · VEZZA</title>
<link rel="icon" href="/assets/icons/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Sora:wght@600;700&display=swap">
<link rel="stylesheet" href="/admin/assets/admin.css?v=<?= $pv ?>">
<link rel="stylesheet" href="/clientes/assets/portal.css?v=<?= $v ?>">
<script src="/clientes/assets/portal.js?v=<?= $v ?>" defer></script>
</head>
<body class="<?= e($claseBody) ?>">
<a class="skip" href="#main">Saltar al contenido</a>
    <?php
}

function portal_fin(): void
{
    echo "</body>\n</html>\n";
}

/**
 * Pantalla para elegir contraseña con un token (activar la cuenta o recuperarla).
 * Un token ausente, malformado o que no es texto se trata como inválido: muestra "link vencido", nunca un error.
 */
function portal_pagina_clave(string $tipo, string $titulo, string $intro, string $textoBoton): void
{
    session_boot_cliente();
    $crudo = $_POST['token'] ?? $_GET['token'] ?? '';
    $token = is_string($crudo) ? $crudo : '';
    $error = null;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            csrf_check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null);
            $clave = is_string($_POST['clave'] ?? null) ? $_POST['clave'] : '';
            $repetir = is_string($_POST['repetir'] ?? null) ? $_POST['repetir'] : '';
            if (cliente_token_valido($token, $tipo) && $clave !== $repetir) {
                throw new HttpError(422, 'Las contraseñas no coinciden.');
            }
            cliente_token_consumir($token, $tipo, $clave);
            header('Location: /clientes/login?aviso=' . ($tipo === 'invitacion' ? 'activada' : 'cambiada'), true, 303);
            exit;
        } catch (HttpError $e) {
            $error = $e->campos['clave'] ?? $e->getMessage();
            http_response_code($e->status);
        }
    }
    $valido = cliente_token_valido($token, $tipo);
    portal_inicio($titulo, 'portal-auth');
    ?>
<main id="main" class="portal-auth-card card" tabindex="-1">
  <h1><?= e($titulo) ?></h1>
  <?php if (!$valido): ?>
    <p>Este link venció o ya se usó. Pedinos uno nuevo y te lo mandamos.</p>
    <a class="btn" href="/clientes/login">Ir a ingresar</a>
  <?php else: ?>
    <p><?= e($intro) ?></p>
    <?php if ($error !== null): ?>
      <div class="form-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <div class="campo">
        <label for="clave">Contraseña nueva</label>
        <div class="fila-clave">
          <input id="clave" name="clave" type="password" autocomplete="new-password" minlength="<?= CLIENTE_CLAVE_MIN ?>" required autofocus>
          <button type="button" class="btn" data-ver-clave="clave" aria-controls="clave" aria-pressed="false">Mostrar</button>
        </div>
        <small class="item-sub">Al menos <?= CLIENTE_CLAVE_MIN ?> caracteres.</small>
      </div>
      <div class="campo">
        <label for="repetir">Repetila</label>
        <input id="repetir" name="repetir" type="password" autocomplete="new-password" minlength="<?= CLIENTE_CLAVE_MIN ?>" required>
      </div>
      <button class="btn btn-primario" type="submit"><?= e($textoBoton) ?></button>
    </form>
  <?php endif; ?>
</main>
    <?php
    portal_fin();
}
