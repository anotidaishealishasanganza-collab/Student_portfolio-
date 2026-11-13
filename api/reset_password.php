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

$token = (string)($input['token'] ?? '');
$password = (string)($input['password'] ?? '');

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    jsonResponse([
        'success' => false,
        'message' => 'This password reset link is invalid or has expired.'
    ], 422);
}

if (
    strlen($password) < 10 ||
    strlen($password) > 4096
) {
    jsonResponse([
        'success' => false,
        'message' => 'Your new password must contain at least 10 characters.'
    ], 422);
}

$pdo = database();

try {
    $pdo->beginTransaction();

    $tokenHash = hash('sha256', $token);

    $statement = $pdo->prepare(
        'SELECT
            pr.id AS reset_id,
            pr.user_id,
            pr.expires_at,
            pr.used_at,
            u.status
         FROM password_reset_tokens pr
         INNER JOIN users u ON u.id = pr.user_id
         WHERE pr.token_hash = ?
         LIMIT 1
         FOR UPDATE'
    );

    $statement->execute([$tokenHash]);
    $record = $statement->fetch();

    if (
        !$record ||
        $record['used_at'] !== null ||
        strtotime($record['expires_at']) <= time() ||
        $record['status'] !== 'Active'
    ) {
        $pdo->rollBack();

        jsonResponse([
            'success' => false,
            'message' => 'This password reset link is invalid or has expired.'
        ], 422);
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if ($passwordHash === false) {
        throw new RuntimeException('Password hashing failed.');
    }

    $updateUser = $pdo->prepare(
        'UPDATE users
         SET password_hash = ?,
             failed_attempts = 0,
             locked_until = NULL
         WHERE id = ?'
    );

    $updateUser->execute([
        $passwordHash,
        $record['user_id']
    ]);

    $invalidate = $pdo->prepare(
        'UPDATE password_reset_tokens
         SET used_at = NOW()
         WHERE user_id = ?
           AND used_at IS NULL'
    );

    $invalidate->execute([$record['user_id']]);

    $pdo->commit();

    unset($password, $token, $passwordHash);

    jsonResponse([
        'success' => true,
        'message' => 'Your password has been changed successfully. You can now sign in using your University ID and new password.'
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('AUSSH password update failed: ' . get_class($e));

    jsonResponse([
        'success' => false,
        'message' => 'Unable to update your password right now. Please try again.'
    ], 500);
}