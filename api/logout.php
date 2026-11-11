<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

requirePost();
verifyCsrf();

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => 'Lax',
    ]);
}

session_destroy();

jsonResponse([
    'success' => true,
    'message' => 'You have been signed out.'
]);
