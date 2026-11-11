<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$user = requireAuthentication();

jsonResponse([
    'success' => true,
    'user' => $user,
    'csrf_token' => $_SESSION['csrf_token'] ?? null
]);
