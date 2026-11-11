<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

requirePost();
verifyCsrf();

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    jsonResponse(['success' => false, 'message' => 'Invalid request.'], 400);
}

$userId = trim((string)($input['user_id'] ?? ''));
$fullName = trim((string)($input['full_name'] ?? ''));
$password = (string)($input['password'] ?? '');

$requestedRole = strtolower(trim((string)($input['role'] ?? 'student')));
$allowedRoles = [
    'student', 'lecturer', 'verifier', 'hod', 'registry',
    'supervisor', 'chaplaincy', 'dean', 'admin', 'alumni'
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

$role = $requestedRole;

if (
    !preg_match('/^[A-Za-z0-9._-]{3,64}$/', $userId) ||
    strlen($fullName) < 2 ||
    strlen($fullName) > 120 ||
    strlen($password) < 10 ||
    strlen($password) > 4096
) {
    jsonResponse([
        'success' => false,
        'message' => 'Enter a valid university ID, full name and password of at least 10 characters.'
    ], 422);
}

try {
    $pdo = database();

    $statement = $pdo->prepare(
        'INSERT INTO users (user_id, full_name, role, password_hash, status)
         VALUES (?, ?, ?, ?, ?)'
    );

    $statement->execute([
        $userId,
        $fullName,
        $role,
        password_hash($password, PASSWORD_DEFAULT),
        'Active'
    ]);

    jsonResponse([
        'success' => true,
        'message' => 'Account created successfully. You can now sign in.'
    ], 201);

} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        jsonResponse([
            'success' => false,
            'message' => 'This university ID already has an account.'
        ], 409);
    }

    error_log('Student registration error: ' . $e->getMessage());

    jsonResponse([
        'success' => false,
        'message' => 'Account registration is temporarily unavailable.'
    ], 500);
}