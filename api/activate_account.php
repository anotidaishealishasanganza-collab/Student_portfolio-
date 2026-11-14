<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/account_management_lib.php';

requirePost();
verifyCsrf();
$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    jsonResponse(['success' => false, 'message' => 'Invalid activation request.'], 400);
}
$token = (string)($input['token'] ?? '');
$password = (string)($input['password'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    jsonResponse(['success' => false, 'message' => 'This activation link is invalid or has expired.'], 422);
}
if (strlen($password) < 10 || strlen($password) > 4096) {
    jsonResponse(['success' => false, 'message' => 'Choose a password with at least 10 characters.'], 422);
}
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
if (!is_string($passwordHash)) {
    jsonResponse(['success' => false, 'message' => 'The password could not be secured. Try again.'], 500);
}

$pdo = database();
ensureAccountManagementSchema($pdo);
$pdo->beginTransaction();
try {
    $query = $pdo->prepare(
        'SELECT t.id AS token_id, t.user_id, u.user_id AS university_id, u.role,
                u.status, u.full_name
         FROM portal_account_activation_tokens t
         INNER JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = ? AND t.used_at IS NULL
           AND t.expires_at > CURRENT_TIMESTAMP
         LIMIT 1 FOR UPDATE'
    );
    $query->execute([hash('sha256', $token)]);
    $activation = $query->fetch(PDO::FETCH_ASSOC);
    if (!$activation || $activation['status'] !== 'Disabled' || $activation['role'] === 'student') {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'This activation link is invalid or has expired.'], 422);
    }
    $pdo->prepare(
        "UPDATE users SET password_hash = ?, status = 'Active',
             failed_attempts = 0, locked_until = NULL
         WHERE id = ?"
    )->execute([$passwordHash, (int)$activation['user_id']]);
    $pdo->prepare(
        'UPDATE portal_account_activation_tokens
         SET used_at = CURRENT_TIMESTAMP WHERE id = ?'
    )->execute([(int)$activation['token_id']]);
    $pdo->prepare(
        'UPDATE portal_account_activation_tokens
         SET used_at = CURRENT_TIMESTAMP
         WHERE user_id = ? AND used_at IS NULL'
    )->execute([(int)$activation['user_id']]);
    accountAudit(
        $pdo,
        (string)$activation['university_id'],
        'account_activated',
        (int)$activation['user_id'],
        ['role' => $activation['role']]
    );
    accountNotify(
        $pdo,
        (string)$activation['university_id'],
        'Account activated',
        'Your AU Student Success Hub (AUSSH) account is active. You can now sign in with your University ID and the password you created.',
        (int)$activation['user_id'],
        'activated'
    );
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}
unset($token, $password, $passwordHash);
jsonResponse(['success' => true, 'message' => 'Account activated. You can now sign in.']);
