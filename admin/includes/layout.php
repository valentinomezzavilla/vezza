<?php
declare(strict_types=1);

const PANEL_ASSET_V = '1';

const NAV_PRINCIPAL = [
    'inicio' => ['/admin', 'Inicio'],
    'clientes' => ['/admin/clientes', 'Clientes'],
    'procesos' => ['/admin/procesos', 'Procesos'],
    'cobros' => ['/admin/cobros', 'Cobros'],
];

const NAV_MAS = [
    'gastos' => ['/admin/gastos', 'Gastos'],
    'tareas' => ['/admin/tareas', 'Tareas'],
    'fixs' => ['/admin/fixs', 'Fixs'],
    'agenda' => ['/admin/agenda', 'Agenda'],
    'reportes' => ['/admin/reportes', 'Reportes'],
];

function nav_links(array $items, string $activo): string
{
    $html = '';
    foreach ($items as $clave => [$href, $texto]) {
        $actual = $clave === $activo ? ' aria-current="page"' : '';
        $html .= '<a href="' . e($href) . '"' . $actual . '>' . e($texto) . '</a>';
    }
    return $html;
}

function form_logout(): string
{
    return '<form method="post" action="/admin-logout">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<button type="submit">Salir</button></form>';
}

function layout_head(string $titulo): void
{
    $v = PANEL_ASSET_V;
    ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#F5F3EE">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($titulo) ?> · VEZZA Admin</title>
<link rel="icon" href="/assets/icons/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Sora:wght@600;700&display=swap">
<link rel="stylesheet" href="/admin/assets/admin.css?v=<?= $v ?>">
    <?php
}

function layout_start(string $titulo, string $activo, array $scripts = [], array $data = []): void
{
    send_panel_headers();
    header('Content-Type: text/html; charset=utf-8');
    layout_head($titulo);
    $v = PANEL_ASSET_V;
    echo '<script src="/admin/assets/admin.js?v=' . $v . '" defer></script>' . "\n";
    foreach ($scripts as $script) {
        echo '<script src="/admin/assets/' . e($script) . '?v=' . $v . '" defer></script>' . "\n";
    }
    $attrs = '';
    foreach ($data as $clave => $valor) {
        $attrs .= ' data-' . e($clave) . '="' . e($valor) . '"';
    }
    $masActivo = array_key_exists($activo, NAV_MAS) || $activo === 'migraciones';
    ?>
</head>
<body<?= $attrs ?>>
<a class="skip" href="#main">Saltar al contenido</a>
<nav class="sidebar" aria-label="Principal">
  <div class="marca">VEZZA</div>
  <?= nav_links(NAV_PRINCIPAL + NAV_MAS, $activo) ?>
  <a href="/admin/migraciones"<?= $activo === 'migraciones' ? ' aria-current="page"' : '' ?>>Migraciones</a>
  <?= form_logout() ?>
</nav>
<nav class="bottom-nav" aria-label="Principal (móvil)">
  <?= nav_links(NAV_PRINCIPAL, $activo) ?>
  <details class="nav-mas<?= $masActivo ? ' activo' : '' ?>">
    <summary>Más</summary>
    <div class="nav-mas-menu">
      <?= nav_links(NAV_MAS, $activo) ?>
      <a href="/admin/migraciones">Migraciones</a>
      <?= form_logout() ?>
    </div>
  </details>
</nav>
<main id="main" class="main">
  <header class="page-head">
    <h1 id="titulo"><?= e($titulo) ?></h1>
    <div class="page-actions" id="page-actions"></div>
  </header>
    <?php
}

function layout_end(): void
{
    echo "</main>\n<div id=\"toasts\" class=\"toasts\" aria-live=\"polite\"></div>\n</body>\n</html>\n";
}

function pagina_no_encontrada(string $texto, string $volverHref, string $volverTexto, string $activo): void
{
    http_response_code(404);
    layout_start('No encontrado', $activo);
    echo '<p>' . e($texto) . ' <a href="' . e($volverHref) . '">' . e($volverTexto) . '</a></p>';
    layout_end();
    exit;
}
