<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

requirePost();
verifyCsrf();

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    jsonResponse([
        'success' => false,
        'message' => 'Invalid request.'
    ], 400);
}

$userId = trim((string)($input['user_id'] ?? ''));
$password = (string)($input['password'] ?? '');

if (
    $userId === '' ||
    $password === '' ||
    strlen($userId) > 64 ||
    strlen($password) > 4096
) {
    jsonResponse([
        'success' => false,
        'message' => 'Enter your university ID and password.'
    ], 422);
}

$pdo = database();

try {
    $pdo->beginTransaction();

    $statement = $pdo->prepare(
        'SELECT id, user_id, full_name, role, password_hash,
                status, failed_attempts, locked_until
         FROM users
         WHERE user_id = ?
         LIMIT 1
         FOR UPDATE'
    );

    $statement->execute([$userId]);

    $user = $statement->fetch();

    if (!$user) {
        $pdo->commit();

        jsonResponse([
            'success' => false,
            'message' => 'Invalid university ID or password.'
        ], 401);
    }

    $now = new DateTimeImmutable('now');
    $lockedUntil = $user['locked_until']
        ? new DateTimeImmutable($user['locked_until'])
        : null;

    if ($lockedUntil && $lockedUntil > $now) {
        $pdo->commit();

        jsonResponse([
            'success' => false,
            'message' => 'Account temporarily locked. Please try again later.'
        ], 423);
    }

    if ($user['status'] !== 'Active') {
        $pdo->commit();

        jsonResponse([
            'success' => false,
            'message' => 'Invalid university ID or password.'
        ], 401);
    }

    if (!password_verify($password, $user['password_hash'])) {
        $attempts = (int)$user['failed_attempts'] + 1;

        if ($attempts >= 5) {
            $lockUntil = $now->modify('+15 minutes')->format('Y-m-d H:i:s');

            $update = $pdo->prepare(
                'UPDATE users
                 SET failed_attempts = 0,
                     locked_until = ?
                 WHERE id = ?'
            );

            $update->execute([$lockUntil, $user['id']]);
        } else {
            $update = $pdo->prepare(
                'UPDATE users
                 SET failed_attempts = ?
                 WHERE id = ?'
            );

            $update->execute([$attempts, $user['id']]);
        }

        $pdo->commit();

        jsonResponse([
            'success' => false,
            'message' => 'Invalid university ID or password.'
        ], 401);
    }

    $update = $pdo->prepare(
        'UPDATE users
         SET failed_attempts = 0,
             locked_until = NULL,
             last_login_at = CURRENT_TIMESTAMP
         WHERE id = ?'
    );

    $update->execute([$user['id']]);

    $pdo->commit();

    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'user_id' => $user['user_id'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
    ];

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    jsonResponse([
        'success' => true,
        'message' => 'Login successful.',
        'user' => $_SESSION['user'],
        'csrf_token' => $_SESSION['csrf_token'],
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('AU authentication error: ' . $e->getMessage());

    jsonResponse([
        'success' => false,
        'message' => 'The authentication service is temporarily unavailable.'
    ], 500);
}
