<?php

declare(strict_types=1);

$localConfig = __DIR__ . '/config.local.php';

if (!is_file($localConfig)) {
    throw new RuntimeException('Local database configuration is missing.');
}

$config = require $localConfig;

if (!is_array($config) || !isset($config['db'])) {
    throw new RuntimeException('Invalid database configuration.');
}

return $config;
