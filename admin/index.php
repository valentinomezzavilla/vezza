<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();
layout_start('Inicio', 'inicio', ['dashboard.js']);
?>
<div id="dashboard" aria-live="polite"><p class="item-sub">Cargando…</p></div>
<?php
layout_end();
