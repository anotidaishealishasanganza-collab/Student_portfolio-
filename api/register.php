<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/account_management_lib.php';

requirePost();
verifyCsrf();

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    jsonResponse(['success' => false, 'message' => 'Invalid request.'], 400);
}
if (array_key_exists('role', $input) && strtolower(trim((string)$input['role'])) !== 'student') {
    jsonResponse(['success' => false, 'message' => 'Public registration is limited to student accounts.'], 403);
}

$userId = trim((string)($input['user_id'] ?? ''));
$fullName = trim((string)($input['full_name'] ?? ''));
$email = strtolower(trim((string)($input['email'] ?? '')));
$password = (string)($input['password'] ?? '');
$college = is_string($input['college'] ?? null) ? trim($input['college']) : '';
$programme = trim((string)($input['programme'] ?? ''));
$studyYear = trim((string)($input['study_year'] ?? ''));
$allowedStudyYears = ['Year 1', 'Year 2', 'Year 3', 'Year 4', 'Year 5', 'Postgraduate'];

if (
    !preg_match('/^[A-Za-z0-9._-]{3,64}$/', $userId) ||
    mb_strlen($fullName, 'UTF-8') < 2 || mb_strlen($fullName, 'UTF-8') > 120 ||
    strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    strlen($password) < 10 || strlen($password) > 4096 ||
    !isAllowedAccountCollege($college) ||
    mb_strlen($programme, 'UTF-8') < 2 || mb_strlen($programme, 'UTF-8') > 180 ||
    !in_array($studyYear, $allowedStudyYears, true)
) {
    jsonResponse([
        'success' => false,
        'message' => 'Enter your university ID, full name, university email, password, approved College / School and complete academic information.',
    ], 422);
}

$allowedDomains = getenv('AUSSH_UNIVERSITY_EMAIL_DOMAINS');
if (is_string($allowedDomains) && trim($allowedDomains) !== '') {
    $domains = array_map(
        static fn(string $domain): string => strtolower(ltrim(trim($domain), '@')),
        explode(',', $allowedDomains)
    );
    $emailDomain = strtolower((string)substr(strrchr($email, '@'), 1));
    if (!in_array($emailDomain, $domains, true)) {
        jsonResponse(['success' => false, 'message' => 'Use an email address from an approved university email domain.'], 422);
    }
}

$pdo = database();
ensureAccountManagementSchema($pdo);
$check = $pdo->prepare(
    'SELECT user_id, email FROM users WHERE user_id = ? OR email = ? LIMIT 1'
);
$check->execute([$userId, $email]);
$existing = $check->fetch(PDO::FETCH_ASSOC);
if ($existing) {
    jsonResponse([
        'success' => false,
        'message' => $existing['user_id'] === $userId
            ? 'This university ID already has an account.'
            : 'This email address is already registered.',
    ], 409);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
if (!is_string($passwordHash)) {
    jsonResponse(['success' => false, 'message' => 'The account could not be secured. Please try again.'], 500);
}

try {
    $pdo->beginTransaction();
    $insert = $pdo->prepare(
        'INSERT INTO users (user_id, full_name, email, role, password_hash, status)
         VALUES (?, ?, ?, \'student\', ?, \'Active\')'
    );
    $insert->execute([$userId, $fullName, $email, $passwordHash]);
    $accountId = (int)$pdo->lastInsertId();
    $profile = $pdo->prepare(
        'INSERT INTO portal_account_profiles
            (user_id, college, faculty, department, programme, study_year, student_verification_status)
         VALUES (?, ?, \'\', \'\', ?, ?, \'Pending\')'
    );
    $profile->execute([$userId, $college, $programme, $studyYear]);
    accountAudit($pdo, $userId, 'student_registered', $accountId, [
        'verification_status' => 'Pending',
        'college' => $college,
        'programme' => $programme,
        'study_year' => $studyYear,
    ]);
    $pdo->commit();
    unset($password, $passwordHash);
    jsonResponse([
        'success' => true,
        'message' => 'Registration received. Registry must verify your student identity before your academic status is verified.',
        'verification_status' => 'Pending',
    ], 201);
} catch (PDOException $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ((string)$error->getCode() === '23000') {
        jsonResponse(['success' => false, 'message' => 'This university ID or email address is already registered.'], 409);
    }
    error_log('Student registration database error: ' . get_class($error));
    jsonResponse(['success' => false, 'message' => 'Registration could not be completed. Please try again.'], 500);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Student registration failed: ' . get_class($error));
    jsonResponse(['success' => false, 'message' => 'Registration could not be completed. Please try again.'], 500);
}
