<?php
declare(strict_types=1);

const PANEL_ASSET_V = '3';

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

// Trazos de íconos Lucide (ISC). Solo constantes: nunca se arma SVG con datos del usuario.
const ICONOS = [
    'inicio' => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
    'clientes' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'procesos' => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M8 7v7"/><path d="M12 7v4"/><path d="M16 7v9"/>',
    'cobros' => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
    'gastos' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
    'tareas' => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="m9 12 2 2 4-4"/>',
    'fixs' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
    'agenda' => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
    'reportes' => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
    'migraciones' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>',
    'mas' => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
    'salir' => '<path d="m16 17 5-5-5-5"/><path d="M21 12H9"/><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>',
];

function icono(string $nombre): string
{
    if (!isset(ICONOS[$nombre])) {
        return '';
    }
    return '<svg class="icono" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
        . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ICONOS[$nombre] . '</svg>';
}

function nav_links(array $items, string $activo): string
{
    $html = '';
    foreach ($items as $clave => [$href, $texto]) {
        $actual = $clave === $activo ? ' aria-current="page"' : '';
        $html .= '<a href="' . e($href) . '"' . $actual . '>' . icono($clave) . '<span>' . e($texto) . '</span></a>';
    }
    return $html;
}

function form_logout(): string
{
    return '<form method="post" action="/admin-logout" class="form-salir">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<button type="submit">' . icono('salir') . '<span>Salir</span></button></form>';
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
<meta name="color-scheme" content="light">
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
  <?= nav_links(['migraciones' => ['/admin/migraciones', 'Migraciones']], $activo) ?>
  <?= form_logout() ?>
</nav>
<nav class="bottom-nav" aria-label="Principal (móvil)">
  <?= nav_links(NAV_PRINCIPAL, $activo) ?>
  <details class="nav-mas<?= $masActivo ? ' activo' : '' ?>">
    <summary><?= icono('mas') ?><span>Más</span></summary>
    <div class="nav-mas-menu">
      <?= nav_links(NAV_MAS, $activo) ?>
      <?= nav_links(['migraciones' => ['/admin/migraciones', 'Migraciones']], $activo) ?>
      <?= form_logout() ?>
    </div>
  </details>
</nav>
<main id="main" class="main" tabindex="-1">
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
