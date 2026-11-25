<?php

declare(strict_types=1);

// Cloud database configuration (Render).
if (getenv('DB_HOST')) {
    return [
        'db' => [
            'host' => getenv('DB_HOST'),
            'port' => (int)(getenv('DB_PORT') ?: 4000),
            'database' => getenv('DB_NAME'),
            'username' => getenv('DB_USER'),
            'password' => getenv('DB_PASS'),
            'ssl_ca' => getenv('DB_SSL_CA') ?: '/etc/ssl/certs/isrgrootx1.pem',
        ],
    ];
}

// Local development configuration.
$localConfig = __DIR__ . '/config.local.php';

if (!is_file($localConfig)) {
    throw new RuntimeException('Local database configuration is missing.');
}

$config = require $localConfig;

if (!is_array($config) || !isset($config['db'])) {
    throw new RuntimeException('Invalid database configuration.');
}

return $config;