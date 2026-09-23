<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../admin/includes/env.php';

env_load(__DIR__ . '/../.env.testing');

require_once __DIR__ . '/../admin/includes/bootstrap.php';
