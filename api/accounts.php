<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/account_management_lib.php';
require_once __DIR__ . '/role_migrations.php';
require_once __DIR__ . '/mailer.php';

$sessionUser = requireAuthentication();
$pdo = database();
ensureAccountManagementSchema($pdo);
$activeAccount = $pdo->prepare(
    "SELECT id, user_id, full_name, role, status
     FROM users WHERE user_id = ? AND status = 'Active' LIMIT 1"
);
$activeAccount->execute([$sessionUser['user_id']]);
$actor = $activeAccount->fetch(PDO::FETCH_ASSOC);
if (!$actor || $actor['role'] !== $sessionUser['role']) {
    jsonResponse(['success' => false, 'message' => 'Your account is no longer authorised. Sign in again.'], 403);
}
$actorId = (string)$actor['user_id'];
$isSuperAdmin = $actor['role'] === 'admin' && isDesignatedSuperAdministrator($pdo, $actorId);
$isAdmin = $actor['role'] === 'admin';
$isRegistry = $actor['role'] === 'registry';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if ($isAdmin) {
        $accounts = $pdo->query(
            "SELECT u.id, u.user_id, u.full_name, u.email, u.role, u.status, u.created_at,
                    COALESCE(p.department, '') AS department,
                    COALESCE(p.college, '') AS college, p.faculty, p.programme, p.study_year, p.student_verification_status,
                    EXISTS(
                        SELECT 1 FROM portal_super_administrators sa
                        WHERE sa.user_id = u.user_id
                    ) AS is_super_administrator
             FROM users u
             LEFT JOIN portal_account_profiles p ON p.user_id = u.user_id
             ORDER BY u.created_at DESC, u.user_id LIMIT 1000"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($accounts as &$account) {
            $account['id'] = (int)$account['id'];
            $account['is_super_administrator'] = (bool)$account['is_super_administrator'];
        }
        unset($account);
        $audit = $pdo->query(
            "SELECT a.id, a.actor_user_id, actor.full_name AS actor_name,
                    target.user_id, target.full_name AS target_name,
                    a.action, a.details, a.created_at
             FROM portal_audit a
             LEFT JOIN users actor ON actor.user_id = a.actor_user_id
             LEFT JOIN users target ON target.id = a.record_id
             WHERE a.record_type = 'user_account'
             ORDER BY a.created_at DESC, a.id DESC LIMIT 300"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($audit as &$entry) {
            $entry['details'] = json_decode((string)$entry['details'], true) ?: [];
        }
        unset($entry);
        jsonResponse([
            'success' => true,
            'accounts' => $accounts,
            'audit' => $audit,
            'is_super_administrator' => $isSuperAdmin,
        ]);
    }

    if ($isRegistry) {
        $pending = $pdo->query(
            "SELECT u.id, u.user_id, u.full_name, u.email, u.status, u.created_at,
                    COALESCE(p.college, '') AS college, p.programme, p.study_year,
                    p.student_verification_status
             FROM portal_account_profiles p
             INNER JOIN users u ON u.user_id = p.user_id
             WHERE u.role = 'student' AND p.student_verification_status = 'Pending'
             ORDER BY p.created_at, u.user_id LIMIT 1000"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($pending as &$student) {
            $student['id'] = (int)$student['id'];
        }
        unset($student);
        jsonResponse(['success' => true, 'students' => $pending]);
    }
    jsonResponse(['success' => false, 'message' => 'Only authorised administrators or Registry can use account management.'], 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}
verifyCsrf();
$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    jsonResponse(['success' => false, 'message' => 'Invalid request.'], 400);
}
$action = (string)($input['action'] ?? '');

if (in_array($action, ['verify_student', 'reject_student'], true)) {
    if (!$isRegistry) {
        jsonResponse(['success' => false, 'message' => 'Only Registry can verify student identities.'], 403);
    }
    $studentId = trim((string)($input['user_id'] ?? ''));
    $reason = trim((string)($input['reason'] ?? ''));
    if ($studentId === '' || mb_strlen($reason, 'UTF-8') > 2000) {
        jsonResponse(['success' => false, 'message' => 'Invalid student verification request.'], 422);
    }
    if ($action === 'reject_student' && $reason === '') {
        jsonResponse(['success' => false, 'message' => 'A reason is required when rejecting a student registration.'], 422);
    }
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare(
            "SELECT u.id, u.user_id, p.student_verification_status
             FROM users u INNER JOIN portal_account_profiles p ON p.user_id = u.user_id
             WHERE u.user_id = ? AND u.role = 'student' FOR UPDATE"
        );
        $query->execute([$studentId]);
        $student = $query->fetch(PDO::FETCH_ASSOC);
        if (!$student || $student['student_verification_status'] !== 'Pending') {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Pending student registration not found.'], 404);
        }
        $verified = $action === 'verify_student';
        $status = $verified ? 'Verified' : 'Rejected';
        $pdo->prepare(
            'UPDATE portal_account_profiles
             SET student_verification_status = ?, verification_reason = ?,
                 reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP
             WHERE user_id = ?'
        )->execute([$status, $reason !== '' ? $reason : null, $actorId, $studentId]);
        if (!$verified) {
            $pdo->prepare("UPDATE users SET status = 'Disabled' WHERE user_id = ?")->execute([$studentId]);
        }
        accountAudit($pdo, $actorId, $verified ? 'student_identity_verified' : 'student_registration_rejected', (int)$student['id'], [
            'student_user_id' => $studentId,
            'status' => $status,
            'reason' => $reason,
        ]);
        accountNotify(
            $pdo,
            $studentId,
            $verified ? 'Student identity verified' : 'Student registration not approved',
            $verified
                ? 'Registry verified your student identity.'
                : 'Registry could not verify your student registration. Reason: ' . $reason,
            (int)$student['id'],
            'student-verification-' . strtolower($status)
        );
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    jsonResponse(['success' => true, 'verification_status' => $status]);
}

if (!$isAdmin) {
    jsonResponse(['success' => false, 'message' => 'Only administrators can manage staff accounts.'], 403);
}

$ordinaryRoles = [
    'lecturer', 'verifier', 'hod', 'registry', 'supervisor',
    'chaplaincy', 'dean', 'career_advisor', 'alumni',
    'workplace_supervisor', 'attachment_coordinator',
];
$lookupAccount = static function (PDO $pdo, string $userId): array|false {
    $query = $pdo->prepare('SELECT id, user_id, full_name, email, role, status FROM users WHERE user_id = ? LIMIT 1');
    $query->execute([$userId]);
    return $query->fetch(PDO::FETCH_ASSOC);
};

if ($action === 'create_account') {
    if (array_key_exists('password', $input)) {
        jsonResponse(['success' => false, 'message' => 'Passwords are set by account holders through the secure activation link.'], 422);
    }
    $userId = trim((string)($input['user_id'] ?? ''));
    $fullName = trim((string)($input['full_name'] ?? ''));
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $role = strtolower(trim((string)($input['role'] ?? '')));
    $college = is_string($input['college'] ?? null) ? trim($input['college']) : '';
    $makeSuperAdmin = ($input['make_super_administrator'] ?? false) === true;
    if (
        !preg_match('/^[A-Za-z0-9._-]{3,64}$/', $userId) ||
        mb_strlen($fullName, 'UTF-8') < 2 || mb_strlen($fullName, 'UTF-8') > 120 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 ||
        !isAllowedAccountCollege($college)
    ) {
        jsonResponse(['success' => false, 'message' => 'Enter a valid university ID, name, email and one of the approved colleges or schools.'], 422);
    }
    if (!in_array($role, $ordinaryRoles, true) && $role !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'That account role cannot be created here.'], 422);
    }
    if (($role === 'admin' || $makeSuperAdmin) && !$isSuperAdmin) {
        jsonResponse(['success' => false, 'message' => 'Only a designated Super Administrator can create administrator accounts.'], 403);
    }
    if ($makeSuperAdmin && $role !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'Super Administrator designation requires the administrator role.'], 422);
    }
    if ($role === 'career_advisor') {
        ensureCareerAdvisorRole($pdo);
    }
    if ($role === 'workplace_supervisor') {
        ensureWorkplaceSupervisorRole($pdo);
    }
    if ($role === 'attachment_coordinator') {
        ensureAttachmentCoordinatorRole($pdo);
    }
    $duplicate = $pdo->prepare('SELECT user_id, email FROM users WHERE user_id = ? OR email = ? LIMIT 1');
    $duplicate->execute([$userId, $email]);
    $existing = $duplicate->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        jsonResponse([
            'success' => false,
            'message' => $existing['user_id'] === $userId
                ? 'This university ID already has an account.'
                : 'This email address is already registered.',
        ], 409);
    }
    $initialHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    if (!is_string($initialHash)) {
        jsonResponse(['success' => false, 'message' => 'Could not securely initialize the account.'], 500);
    }
    $pdo->beginTransaction();
    try {
        if ($role === 'admin' || $makeSuperAdmin) {
            $pdo->query('SELECT user_id FROM portal_super_administrators FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
            if (!isDesignatedSuperAdministrator($pdo, $actorId)) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'message' => 'Only a designated Super Administrator can create administrator accounts.'], 403);
            }
        }
        $insert = $pdo->prepare(
            "INSERT INTO users (user_id, full_name, email, role, password_hash, status)
             VALUES (?, ?, ?, ?, ?, 'Disabled')"
        );
        $insert->execute([$userId, $fullName, $email, $role, $initialHash]);
        $databaseId = (int)$pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO portal_account_profiles
                (user_id, college, faculty, department, programme, study_year, student_verification_status)
             VALUES (?, ?, \'\', \'\', \'\', \'\', \'Pending\')
             ON DUPLICATE KEY UPDATE college = VALUES(college)'
        )->execute([$userId, $college]);
        if ($makeSuperAdmin) {
            $pdo->prepare(
                'INSERT INTO portal_super_administrators (user_id, assigned_by) VALUES (?, ?)'
            )->execute([$userId, $actorId]);
        }
        $token = createAccountActivation($pdo, $databaseId, $actorId);
        accountAudit($pdo, $actorId, 'account_created', $databaseId, [
            'user_id' => $userId,
            'role' => $role,
            'status' => 'Disabled',
            'college' => $college,
        ]);
        if ($makeSuperAdmin) {
            accountAudit($pdo, $actorId, 'super_administrator_assigned', $databaseId, [
                'user_id' => $userId,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($error instanceof PDOException && (string)$error->getCode() === '23000') {
            jsonResponse(['success' => false, 'message' => 'This university ID or email address is already registered.'], 409);
        }
        throw $error;
    }
    $emailSent = sendAccountActivation($email, $fullName, $token);
    unset($token, $initialHash);
    jsonResponse([
        'success' => true,
        'user_id' => $userId,
        'status' => 'Disabled',
        'activation_email_sent' => $emailSent,
        'message' => $emailSent
            ? 'Account invitation sent. The account remains inactive until the recipient activates it.'
            : 'Account invitation is recorded but the activation email could not be delivered. Resend the invitation after checking mail configuration.',
    ], 201);
}

$targetId = trim((string)($input['user_id'] ?? ''));
if ($targetId === '' || $targetId === $actorId) {
    jsonResponse(['success' => false, 'message' => 'Select an account other than your own.'], 422);
}
$target = $lookupAccount($pdo, $targetId);
if (!$target) {
    jsonResponse(['success' => false, 'message' => 'Account not found.'], 404);
}
$targetIdDb = (int)$target['id'];
$targetIsSuperAdmin = isDesignatedSuperAdministrator($pdo, $targetId);
if ($target['role'] === 'admin' && !$isSuperAdmin) {
    jsonResponse(['success' => false, 'message' => 'Only a designated Super Administrator can change administrator accounts.'], 403);
}

if ($action === 'set_college') {
    $college = is_string($input['college'] ?? null) ? trim($input['college']) : '';
    if (!isAllowedAccountCollege($college)) {
        jsonResponse(['success' => false, 'message' => 'Choose one of the approved colleges or schools.'], 422);
    }
    $oldQuery = $pdo->prepare('SELECT college FROM portal_account_profiles WHERE user_id = ?');
    $oldQuery->execute([$targetId]);
    $oldCollege = $oldQuery->fetchColumn();
    $pdo->prepare(
        'INSERT INTO portal_account_profiles
            (user_id, college, faculty, department, programme, study_year, student_verification_status)
         VALUES (?, ?, \'\', \'\', \'\', \'\', \'Pending\')
         ON DUPLICATE KEY UPDATE college = VALUES(college)'
    )->execute([$targetId, $college]);
    accountAudit($pdo, $actorId, 'college_changed', $targetIdDb, [
        'user_id' => $targetId,
        'previous_college' => $oldCollege === false ? '' : $oldCollege,
        'college' => $college,
    ]);
    jsonResponse(['success' => true, 'college' => $college]);
}

if ($action === 'deactivate_account') {
    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT user_id FROM portal_super_administrators FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
        $lockedTarget = $pdo->prepare('SELECT status, role FROM users WHERE id = ? FOR UPDATE');
        $lockedTarget->execute([$targetIdDb]);
        $currentTarget = $lockedTarget->fetch(PDO::FETCH_ASSOC);
        if (!$currentTarget || $currentTarget['status'] !== 'Active') {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'This account is already inactive.'], 409);
        }
        if ($currentTarget['role'] === 'admin' && !isDesignatedSuperAdministrator($pdo, $actorId)) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Only a designated Super Administrator can change administrator accounts.'], 403);
        }
        $targetIsSuperAdmin = $currentTarget['role'] === 'admin' &&
            isDesignatedSuperAdministrator($pdo, $targetId);
        if ($targetIsSuperAdmin) {
            $activeSuperAdmins = (int)$pdo->query(
                "SELECT COUNT(*) FROM portal_super_administrators s
                 INNER JOIN users u ON u.user_id = s.user_id
                 WHERE u.role = 'admin' AND u.status = 'Active'"
            )->fetchColumn();
            if ($activeSuperAdmins <= 1) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'message' => 'The last active Super Administrator cannot be deactivated.'], 409);
            }
        }
        $pdo->prepare("UPDATE users SET status = 'Disabled' WHERE id = ?")->execute([$targetIdDb]);
        $pdo->prepare(
            'UPDATE portal_account_activation_tokens SET used_at = CURRENT_TIMESTAMP
             WHERE user_id = ? AND used_at IS NULL'
        )->execute([$targetIdDb]);
        accountAudit($pdo, $actorId, 'account_deactivated', $targetIdDb, [
            'user_id' => $targetId,
            'previous_status' => $currentTarget['status'],
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    jsonResponse(['success' => true, 'status' => 'Disabled']);
}

if ($action === 'set_department') {
    $department = trim((string)($input['department'] ?? ''));
    if (mb_strlen($department, 'UTF-8') > 180) {
        jsonResponse(['success' => false, 'message' => 'Department must be 180 characters or fewer.'], 422);
    }
    $oldQuery = $pdo->prepare('SELECT department FROM portal_account_profiles WHERE user_id = ?');
    $oldQuery->execute([$targetId]);
    $oldDepartment = $oldQuery->fetchColumn();
    $pdo->prepare(
        'INSERT INTO portal_account_profiles
            (user_id, faculty, department, programme, study_year, student_verification_status)
         VALUES (?, \'\', ?, \'\', \'\', \'Pending\')
         ON DUPLICATE KEY UPDATE department = VALUES(department)'
    )->execute([$targetId, $department]);
    accountAudit($pdo, $actorId, 'department_changed', $targetIdDb, [
        'user_id' => $targetId,
        'previous_department' => $oldDepartment === false ? '' : $oldDepartment,
        'department' => $department,
    ]);
    jsonResponse(['success' => true, 'department' => $department]);
}

if ($action === 'resend_invitation') {
    if ($target['role'] === 'student') {
        jsonResponse(['success' => false, 'message' => 'Student identity verification is managed by Registry, not staff activation invitations.'], 422);
    }
    if ($target['status'] !== 'Disabled') {
        jsonResponse(['success' => false, 'message' => 'Only inactive accounts can receive an activation invitation.'], 409);
    }
    $pdo->beginTransaction();
    $token = createAccountActivation($pdo, $targetIdDb, $actorId);
    accountAudit($pdo, $actorId, 'activation_invitation_resent', $targetIdDb, ['user_id' => $targetId]);
    $pdo->commit();
    $emailSent = sendAccountActivation((string)$target['email'], (string)$target['full_name'], $token);
    unset($token);
    jsonResponse([
        'success' => true,
        'activation_email_sent' => $emailSent,
        'message' => $emailSent ? 'Activation invitation sent.' : 'Invitation token refreshed, but the activation email could not be delivered.',
    ]);
}

if ($action === 'change_role') {
    if (!$isSuperAdmin) {
        jsonResponse(['success' => false, 'message' => 'Only a designated Super Administrator can assign roles.'], 403);
    }
    $newRole = strtolower(trim((string)($input['role'] ?? '')));
    if (!in_array($newRole, array_merge($ordinaryRoles, ['admin']), true)) {
        jsonResponse(['success' => false, 'message' => 'Invalid account role.'], 422);
    }
    if ($newRole === 'workplace_supervisor') {
        ensureWorkplaceSupervisorRole($pdo);
    }
    if ($newRole === 'attachment_coordinator') {
        ensureAttachmentCoordinatorRole($pdo);
    }
    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT user_id FROM portal_super_administrators FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
        if (!isDesignatedSuperAdministrator($pdo, $actorId)) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Only a designated Super Administrator can assign roles.'], 403);
        }
        $lockedTarget = $pdo->prepare('SELECT role FROM users WHERE id = ? FOR UPDATE');
        $lockedTarget->execute([$targetIdDb]);
        $currentRole = $lockedTarget->fetchColumn();
        if ($currentRole === false) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Account not found.'], 404);
        }
        $targetIsSuperAdmin = $currentRole === 'admin' &&
            isDesignatedSuperAdministrator($pdo, $targetId);
        if ($targetIsSuperAdmin && $newRole !== 'admin') {
            $activeSuperAdmins = (int)$pdo->query(
                "SELECT COUNT(*) FROM portal_super_administrators s
                 INNER JOIN users u ON u.user_id = s.user_id
                 WHERE u.role = 'admin' AND u.status = 'Active'"
            )->fetchColumn();
            if ($activeSuperAdmins <= 1) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'message' => 'The last Super Administrator cannot be demoted.'], 409);
            }
        }
        $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $targetIdDb]);
        if ($targetIsSuperAdmin && $newRole !== 'admin') {
            $pdo->prepare('DELETE FROM portal_super_administrators WHERE user_id = ?')->execute([$targetId]);
        }
        accountAudit($pdo, $actorId, 'role_changed', $targetIdDb, [
            'user_id' => $targetId,
            'previous_role' => $currentRole,
            'role' => $newRole,
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    jsonResponse(['success' => true, 'role' => $newRole]);
}

if ($action === 'set_super_administrator') {
    if (!$isSuperAdmin) {
        jsonResponse(['success' => false, 'message' => 'Only a designated Super Administrator can change Super Administrator designations.'], 403);
    }
    if ($target['role'] !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'Only an administrator account can be designated Super Administrator.'], 422);
    }
    $enabled = ($input['enabled'] ?? false) === true;
    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT user_id FROM portal_super_administrators FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
        if (!isDesignatedSuperAdministrator($pdo, $actorId)) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Only a designated Super Administrator can change Super Administrator designations.'], 403);
        }
        $lockedTarget = $pdo->prepare('SELECT role FROM users WHERE id = ? FOR UPDATE');
        $lockedTarget->execute([$targetIdDb]);
        if ($lockedTarget->fetchColumn() !== 'admin') {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Only an administrator account can be designated Super Administrator.'], 422);
        }
        $targetIsSuperAdmin = isDesignatedSuperAdministrator($pdo, $targetId);
        if ($enabled === $targetIsSuperAdmin) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'The Super Administrator designation is already in that state.'], 409);
        }
        if (!$enabled && $targetIsSuperAdmin) {
            $activeSuperAdmins = (int)$pdo->query(
                "SELECT COUNT(*) FROM portal_super_administrators s
                 INNER JOIN users u ON u.user_id = s.user_id
                 WHERE u.role = 'admin' AND u.status = 'Active'"
            )->fetchColumn();
            if ($activeSuperAdmins <= 1) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'message' => 'The last Super Administrator cannot be removed.'], 409);
            }
        }
        if ($enabled) {
            $pdo->prepare(
                'INSERT INTO portal_super_administrators (user_id, assigned_by) VALUES (?, ?)'
            )->execute([$targetId, $actorId]);
        } else {
            $pdo->prepare('DELETE FROM portal_super_administrators WHERE user_id = ?')->execute([$targetId]);
        }
        accountAudit($pdo, $actorId, $enabled ? 'super_administrator_assigned' : 'super_administrator_removed', $targetIdDb, [
            'user_id' => $targetId,
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    jsonResponse(['success' => true, 'is_super_administrator' => $enabled]);
}

jsonResponse(['success' => false, 'message' => 'Unknown account management action.'], 400);
