<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mailer.php';

requirePost();
verifyCsrf();

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    jsonResponse([
        'success' => false,
        'message' => 'Invalid request.'
    ], 400);
}

$email = strtolower(trim((string)($input['email'] ?? '')));

if (
    strlen($email) > 254 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    jsonResponse([
        'success' => false,
        'message' => 'Enter a valid email address.'
    ], 422);
}

// Session-based rate limit, including requests for unknown email addresses.
$now = time();
$attempts = $_SESSION['password_reset_attempts'] ?? [];

if (!is_array($attempts)) {
    $attempts = [];
}

$attempts = array_values(array_filter(
    $attempts,
    static fn($timestamp) => is_int($timestamp) && $timestamp > $now - 900
));

if (count($attempts) >= 5) {
    jsonResponse([
        'success' => true,
        'message' => 'If an active account uses that email address, a password reset link will be sent shortly.'
    ]);
}

$attempts[] = $now;
$_SESSION['password_reset_attempts'] = $attempts;

$pdo = database();

try {
    $statement = $pdo->prepare(
        'SELECT id, full_name, email
         FROM users
         WHERE email = ?
           AND status = ?
         LIMIT 1'
    );

    $statement->execute([$email, 'Active']);
    $user = $statement->fetch();

    if (!$user) {
        jsonResponse([
            'success' => true,
            'message' => 'If an active account uses that email address, a password reset link will be sent shortly.'
        ]);
    }

    // Limit repeated requests for the same account.
    $rateLimit = $pdo->prepare(
        'SELECT COUNT(*)
         FROM password_reset_tokens
         WHERE user_id = ?
           AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
    );

    $rateLimit->execute([$user['id']]);

    if ((int)$rateLimit->fetchColumn() >= 3) {
        jsonResponse([
            'success' => true,
            'message' => 'If an active account uses that email address, a password reset link will be sent shortly.'
        ]);
    }

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);

    $pdo->beginTransaction();

    $invalidate = $pdo->prepare(
        'UPDATE password_reset_tokens
         SET used_at = NOW()
         WHERE user_id = ?
           AND used_at IS NULL'
    );

    $invalidate->execute([$user['id']]);

    $insert = $pdo->prepare(
        'INSERT INTO password_reset_tokens
            (user_id, token_hash, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
    );

    $insert->execute([$user['id'], $tokenHash]);

    $pdo->commit();

    $mailConfigPath = getenv('USERPROFILE')
        . DIRECTORY_SEPARATOR . 'aussh_private'
        . DIRECTORY_SEPARATOR . 'mail_config.php';

    if (!is_file($mailConfigPath)) {
        throw new RuntimeException('Private email configuration is missing.');
    }

    ob_start();
    $mailConfig = require $mailConfigPath;
    ob_end_clean();

    $appUrl = rtrim((string)($mailConfig['app_url'] ?? ''), '/');

    if (!filter_var($appUrl, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Invalid application URL.');
    }

    // The token is placed in the URL fragment, not the server request path.
    $resetLink = $appUrl . '/#password-reset=' . $rawToken;

    sendPasswordResetEmail(
        (string)$user['email'],
        (string)$user['full_name'],
        $resetLink
    );

    unset($rawToken, $resetLink);

    jsonResponse([
        'success' => true,
        'message' => 'If an active account uses that email address, a password reset link will be sent shortly.'
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Do not log passwords, reset tokens, or email credentials.
    error_log(
    'AUSSH password reset request failed: ' .
    get_class($e) . ' - ' . $e->getMessage()
);

    jsonResponse([
        'success' => false,
        'message' => 'Password recovery is temporarily unavailable. Please try again later.'
    ], 500);
}
