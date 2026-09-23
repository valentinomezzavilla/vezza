<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();
layout_start('Tareas', 'tareas', ['tareas.js']);
?>
<div id="tareas"></div>
<?php
layout_end();
