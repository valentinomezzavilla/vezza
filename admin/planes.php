<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_admin();
layout_start('Planes', 'cobros', ['planes.js']);
?>
<div id="planes"></div>
<?php
layout_end();
