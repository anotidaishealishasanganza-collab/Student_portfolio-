<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

jsonResponse([
    'success' => true,
    'csrf_token' => $_SESSION['csrf_token']
]);
