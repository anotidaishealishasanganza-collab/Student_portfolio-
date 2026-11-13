<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/role_migrations.php';

requirePost();
verifyCsrf();

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    jsonResponse(['success' => false, 'message' => 'Invalid request.'], 400);
}

$userId = trim((string)($input['user_id'] ?? ''));
$fullName = trim((string)($input['full_name'] ?? ''));
$email = strtolower(trim((string)($input['email'] ?? '')));
$password = (string)($input['password'] ?? '');

$requestedRole = strtolower(trim((string)($input['role'] ?? 'student')));

$allowedRoles = [
    'student', 'lecturer', 'verifier', 'hod', 'registry',
    'supervisor', 'chaplaincy', 'dean', 'career_advisor', 'admin', 'alumni'
];

$sessionUser = $_SESSION['user'] ?? null;
$isAdmin = is_array($sessionUser) &&
    ($sessionUser['role'] ?? '') === 'admin';

if (!in_array($requestedRole, $allowedRoles, true)) {
    jsonResponse([
        'success' => false,
        'message' => 'Invalid account role.'
    ], 422);
}

if ($requestedRole !== 'student' && !$isAdmin) {
    jsonResponse([
        'success' => false,
        'message' => 'Only an authorised administrator can create non-student accounts.'
    ], 403);
}

if (
    !preg_match('/^[A-Za-z0-9._-]{3,64}$/', $userId) ||
    strlen($fullName) < 2 ||
    strlen($fullName) > 120 ||
    strlen($email) > 254 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    strlen($password) < 10 ||
    strlen($password) > 4096
) {
    jsonResponse([
        'success' => false,
        'message' => 'Enter a valid university ID, full name, email address and password of at least 10 characters.'
    ], 422);
}

try {
    $pdo = database();
    if ($requestedRole !== 'student') {
        $sessionUser = requireAuthentication();
        $authorisation = $pdo->prepare(
            "SELECT role FROM users
             WHERE user_id = ? AND status = 'Active' LIMIT 1"
        );
        $authorisation->execute([$sessionUser['user_id']]);
        if ($authorisation->fetchColumn() !== 'admin' || ($sessionUser['role'] ?? '') !== 'admin') {
            jsonResponse([
                'success' => false,
                'message' => 'Only an active administrator can create staff accounts.'
            ], 403);
        }
    }

    if ($requestedRole === 'career_advisor') {
        ensureCareerAdvisorRole($pdo);
    }

    $check = $pdo->prepare(
        'SELECT user_id, email FROM users
         WHERE user_id = ? OR email = ?
         LIMIT 1'
    );

    $check->execute([$userId, $email]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if ($existing['user_id'] === $userId) {
            jsonResponse([
                'success' => false,
                'message' => 'This university ID already has an account.'
            ], 409);
        }

        jsonResponse([
            'success' => false,
            'message' => 'This email address is already registered.'
        ], 409);
    }

    $statement = $pdo->prepare(
        'INSERT INTO users
            (user_id, full_name, email, role, password_hash, status)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    $statement->execute([
        $userId,
        $fullName,
        $email,
        $requestedRole,
        password_hash($password, PASSWORD_DEFAULT),
        'Active'
    ]);

    jsonResponse([
        'success' => true,
        'message' => 'Account created successfully. You can now sign in.'
    ], 201);

}catch (PDOException $e) {
    if ((string) $e->getCode() === '23000') {
        jsonResponse([
            'success' => false,
            'message' => 'This university ID or email address is already registered.'
        ], 409);
    }

    error_log('Registration database error: ' . $e->getMessage());

    jsonResponse([
        'success' => false,
        'message' => 'Registration could not be completed. Please try again.'
    ], 500);
}