<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function database(): PDO
{
    static $pdo = null;

    global $config;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = $config['db'];

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $db['host'],
        $db['port'],
        $db['database']
    );

    $pdo = new PDO($dsn, $db['username'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    $secure = !empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off';

    session_name('AU_SUCCESS_SESSION');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function requirePost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }
}

function verifyCsrf(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';

    if (
        !is_string($provided) ||
        !is_string($expected) ||
        $provided === '' ||
        !hash_equals($expected, $provided)
    ) {
        jsonResponse([
            'success' => false,
            'message' => 'Your security token is invalid. Refresh and try again.'
        ], 419);
    }
}

function requireAuthentication(): array
{
    if (
        empty($_SESSION['user']) ||
        !is_array($_SESSION['user'])
    ) {
        jsonResponse([
            'success' => false,
            'message' => 'Authentication required.'
        ], 401);
    }

    return $_SESSION['user'];
}

function requireRole(array $allowedRoles): array
{
    $user = requireAuthentication();

    if (!in_array($user['role'], $allowedRoles, true)) {
        jsonResponse([
            'success' => false,
            'message' => 'You are not authorised to perform this action.'
        ], 403);
    }

    return $user;
}

startSecureSession();
