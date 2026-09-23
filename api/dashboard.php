<?php
declare(strict_types=1);

require __DIR__ . '/../admin/includes/bootstrap.php';

api_resource(['list' => fn(array $get) => dashboard_resumen()]);
