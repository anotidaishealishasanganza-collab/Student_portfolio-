<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/api/account_management_lib.php';

$pdo = database();
$databaseName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$allowedDatabase = getenv('AUSSH_ACCOUNT_TEST_DATABASE');
if (!is_string($allowedDatabase) || $allowedDatabase === '' || !hash_equals($allowedDatabase, $databaseName)) {
    fwrite(STDERR, "Set AUSSH_ACCOUNT_TEST_DATABASE to the exact non-production database name before running.\n");
    exit(2);
}
ensureAccountManagementSchema($pdo);

$suffix = strtoupper(bin2hex(random_bytes(5)));
$password = 'TestOnly-' . $suffix . '!Pass';
$users = [
    'student' => 'TESTACCT_S_' . $suffix,
    'registered_student' => 'TESTACCT_NEW_' . $suffix,
    'reject_student' => 'TESTACCT_R_' . $suffix,
    'admin' => 'TESTACCT_A_' . $suffix,
    'super_admin' => 'TESTACCT_SA_' . $suffix,
    'registry' => 'TESTACCT_REG_' . $suffix,
    'created_staff' => 'TESTACCT_L_' . $suffix,
    'created_admin' => 'TESTACCT_AD_' . $suffix,
];
$roles = [
    'student' => 'student',
    'registered_student' => 'student',
    'reject_student' => 'student',
    'admin' => 'admin',
    'super_admin' => 'admin',
    'registry' => 'registry',
    'created_staff' => 'lecturer',
    'created_admin' => 'admin',
];
$sessions = [];
$server = null;
$serverLog = tempnam(sys_get_temp_dir(), 'aussh-accounts-server-');
$testCount = 0;

function accountAssert(bool $condition, string $message): void
{
    global $testCount;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $testCount++;
    echo 'PASS: ' . $message . PHP_EOL;
}

function accountStatus(array $headers): int
{
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match)) {
            return (int)$match[1];
        }
    }
    return 0;
}

function accountRequest(
    string $base,
    ?array $session,
    string $path,
    string $method = 'GET',
    ?array $body = null,
    ?string $cookie = null,
    ?string $csrf = null
): array {
    $headers = ['Accept: application/json'];
    $content = '';
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/json';
        if ($csrf !== null || $session !== null) {
            $headers[] = 'X-CSRF-Token: ' . ($csrf ?? $session['csrf_token']);
        }
        $content = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    if ($cookie !== null || $session !== null) {
        $headers[] = 'Cookie: ' . ($cookie ?? $session['cookie']);
    }
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => 30,
        ],
    ]);
    $response = @file_get_contents($base . $path, false, $context);
    if (!is_string($response)) {
        throw new RuntimeException('HTTP request failed: ' . $path);
    }
    $headersOut = $http_response_header ?? [];
    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON response from ' . $path . ': ' . $response);
    }
    return [accountStatus($headersOut), $data, $headersOut];
}

function accountLogin(string $base, string $userId, string $password): array
{
    [$status, $csrfData, $headers] = accountRequest($base, null, '/api/csrf.php');
    $cookie = '';
    foreach ($headers as $header) {
        if (stripos($header, 'Set-Cookie: AU_SUCCESS_SESSION=') === 0) {
            $cookie = explode(';', trim(substr($header, strlen('Set-Cookie: '))))[0];
            break;
        }
    }
    if ($status !== 200 || empty($csrfData['csrf_token']) || $cookie === '') {
        throw new RuntimeException('Could not establish a CSRF session for account test.');
    }
    [$status, $data, $headers] = accountRequest(
        $base,
        null,
        '/api/login.php',
        'POST',
        ['user_id' => $userId, 'password' => $password],
        $cookie,
        (string)$csrfData['csrf_token']
    );
    if ($status !== 200 || empty($data['success']) || empty($data['csrf_token'])) {
        throw new RuntimeException('Could not log in test account ' . $userId . ': ' . (string)($data['message'] ?? ''));
    }
    foreach ($headers as $header) {
        if (stripos($header, 'Set-Cookie: AU_SUCCESS_SESSION=') === 0) {
            $cookie = explode(';', trim(substr($header, strlen('Set-Cookie: '))))[0];
            break;
        }
    }
    return ['cookie' => $cookie, 'csrf_token' => (string)$data['csrf_token']];
}

function accountCsrfSession(string $base): array
{
    [$status, $data, $headers] = accountRequest($base, null, '/api/csrf.php');
    $cookie = '';
    foreach ($headers as $header) {
        if (stripos($header, 'Set-Cookie: AU_SUCCESS_SESSION=') === 0) {
            $cookie = explode(';', trim(substr($header, strlen('Set-Cookie: '))))[0];
            break;
        }
    }
    if ($status !== 200 || empty($data['csrf_token']) || $cookie === '') {
        throw new RuntimeException('Could not establish a public registration CSRF session.');
    }
    return ['cookie' => $cookie, 'csrf_token' => (string)$data['csrf_token']];
}

function accountCreateStudentPayload(string $userId, string $email): array
{
    return [
        'user_id' => $userId,
        'full_name' => '[TEST ONLY] Account workflow student',
        'email' => $email,
        'password' => 'Student-Test-Only!42',
        'college' => 'College of Business and Management Sciences',
        'programme' => 'TEST ONLY Programme',
        'study_year' => 'Year 2',
    ];
}

function accountStaffPayload(string $userId, string $email, string $role = 'lecturer'): array
{
    return [
        'action' => 'create_account',
        'user_id' => $userId,
        'full_name' => '[TEST ONLY] Invited account',
        'email' => $email,
        'role' => $role,
        'college' => 'College of Engineering and Applied Sciences',
    ];
}

try {
    $pdo->beginTransaction();
    $insertUser = $pdo->prepare(
        'INSERT INTO users (user_id, full_name, email, role, password_hash, status)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($users as $key => $userId) {
        if (in_array($key, ['registered_student', 'reject_student', 'created_staff', 'created_admin'], true)) {
            continue;
        }
        $insertUser->execute([
            $userId,
            '[TEST ONLY] Account workflow ' . $key,
            'invalid-' . strtolower($userId),
            $roles[$key],
            password_hash($password, PASSWORD_DEFAULT),
            'Active',
        ]);
    }
    $pdo->commit();
    $superDbIdQuery = $pdo->prepare('SELECT id FROM users WHERE user_id = ?');
    $superDbIdQuery->execute([$users['super_admin']]);
    $superDbId = (int)$superDbIdQuery->fetchColumn();
    $pdo->prepare(
        'INSERT INTO portal_super_administrators (user_id, assigned_by) VALUES (?, ?)'
    )->execute([$users['super_admin'], $users['super_admin']]);
    putenv('AUSSH_SUPER_ADMIN_USER_IDS=' . $users['admin']);
    try {
        ensureAccountManagementSchema($pdo);
    } finally {
        putenv('AUSSH_SUPER_ADMIN_USER_IDS');
    }
    $bootstrapAudit = $pdo->prepare(
        "SELECT COUNT(*) FROM portal_audit WHERE actor_user_id = ?
         AND action = 'super_administrator_assigned'
         AND details LIKE '%server_configuration%'"
    );
    $bootstrapAudit->execute([$users['admin']]);
    accountAssert((int)$bootstrapAudit->fetchColumn() === 1,
        'Environment-configured Super Administrator assignment is audited');
    $pdo->prepare('DELETE FROM portal_super_administrators WHERE user_id = ?')->execute([$users['admin']]);

    $serverSocket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$serverSocket) throw new RuntimeException('Could not reserve a local test server port: ' . $error);
    $address = stream_socket_get_name($serverSocket, false);
    fclose($serverSocket);
    $port = (int)substr((string)$address, strrpos((string)$address, ':') + 1);
    $serverEnvironment = getenv();
    if (!is_array($serverEnvironment)) {
        $serverEnvironment = [];
    }
    $serverEnvironment['USERPROFILE'] = sys_get_temp_dir() . DIRECTORY_SEPARATOR .
        'aussh-account-test-' . strtolower($suffix);
    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__)],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $serverLog, 'a'],
            2 => ['file', $serverLog, 'a'],
        ],
        $pipes,
        dirname(__DIR__),
        $serverEnvironment,
        ['bypass_shell' => true]
    );
    if (!is_resource($server)) throw new RuntimeException('Could not start account test server.');
    fclose($pipes[0]);
    $base = 'http://127.0.0.1:' . $port;
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
        if (is_resource($socket)) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) throw new RuntimeException('Account test server did not start.');
    foreach (['admin', 'super_admin', 'registry', 'student'] as $key) {
        $sessions[$key] = accountLogin($base, $users[$key], $password);
    }
    $csrf = $sessions['student']['csrf_token'];
    [$status] = accountRequest($base, null, '/api/accounts.php', 'POST', [
        'action' => 'create_account',
        'user_id' => 'TESTACCT_CSRF_' . $suffix,
        'full_name' => '[TEST ONLY] CSRF rejection',
        'email' => 'csrf-' . strtolower($suffix) . '@example.invalid',
        'role' => 'lecturer',
    ]);
    accountAssert($status === 419, 'Account management write APIs reject requests without CSRF protection');
    $studentPayload = accountCreateStudentPayload($users['registered_student'], 'student-' . strtolower($suffix) . '@example.invalid');
    [$status, $response] = accountRequest($base, $sessions['student'], '/api/register.php', 'POST', $studentPayload);
    accountAssert($status === 201 && $response['verification_status'] === 'Pending',
        'Public student registration creates a student with Registry identity verification pending: HTTP ' .
        $status . ' ' . (string)($response['message'] ?? ''));
    $profileQuery = $pdo->prepare('SELECT college, faculty, department, programme, study_year, student_verification_status FROM portal_account_profiles WHERE user_id = ?');
    $profileQuery->execute([$users['registered_student']]);
    $studentProfile = $profileQuery->fetch(PDO::FETCH_ASSOC);
    accountAssert($studentProfile && $studentProfile['student_verification_status'] === 'Pending' &&
        $studentProfile['college'] === 'College of Business and Management Sciences' &&
        $studentProfile['faculty'] === '' && $studentProfile['department'] === '' &&
        $studentProfile['study_year'] === 'Year 2',
        'Student academic profile is saved without granting verified status');
    $pdo->prepare(
        "UPDATE portal_account_profiles SET college = '', faculty = 'TEST ONLY Faculty' WHERE user_id = ?"
    )->execute([$users['registered_student']]);
    $pdo->exec('ALTER TABLE portal_account_profiles DROP COLUMN college');
    ensureAccountManagementSchema($pdo);
    $profileQuery->execute([$users['registered_student']]);
    $legacyProfile = $profileQuery->fetch(PDO::FETCH_ASSOC);
    accountAssert($legacyProfile['college'] === '' && $legacyProfile['faculty'] === 'TEST ONLY Faculty',
        'Legacy values outside the approved college list are preserved and not guessed');
    $pdo->prepare(
        "UPDATE portal_account_profiles SET college = '',
            faculty = 'College of Business and Management Sciences',
            department = 'School of Law'
         WHERE user_id = ?"
    )->execute([$users['registered_student']]);
    $pdo->exec('ALTER TABLE portal_account_profiles DROP COLUMN college');
    ensureAccountManagementSchema($pdo);
    $profileQuery->execute([$users['registered_student']]);
    accountAssert($profileQuery->fetch(PDO::FETCH_ASSOC)['college'] === '',
        'College migration does not guess when legacy fields conflict');
    $pdo->prepare(
        "UPDATE portal_account_profiles SET college = '', faculty = 'TEST ONLY Faculty' WHERE user_id = ?"
    )->execute([$users['registered_student']]);
    $pdo->exec('ALTER TABLE portal_account_profiles DROP COLUMN college');
    ensureAccountManagementSchema($pdo);
    $profileQuery->execute([$users['registered_student']]);
    accountAssert($profileQuery->fetch(PDO::FETCH_ASSOC)['college'] === 'School of Law',
        'College migration backfills an exact approved legacy value');

    [$status] = accountRequest($base, $sessions['student'], '/api/register.php', 'POST', array_merge($studentPayload, ['role' => 'admin']));
    accountAssert($status === 403, 'Public registration cannot choose an administrator or staff role');
    [$status] = accountRequest($base, $sessions['student'], '/api/register.php', 'POST', array_merge($studentPayload, ['user_id' => $users['reject_student']]));
    accountAssert($status === 409, 'Duplicate student email addresses are rejected');
    [$status] = accountRequest($base, $sessions['student'], '/api/register.php', 'POST', array_merge($studentPayload, ['email' => 'unique-' . strtolower($suffix) . '@example.invalid']));
    accountAssert($status === 409, 'Duplicate university IDs are rejected');
    $missingAcademic = accountCreateStudentPayload('TESTACCT_M_' . $suffix, 'missing-' . strtolower($suffix) . '@example.invalid');
    unset($missingAcademic['college']);
    [$status] = accountRequest($base, $sessions['student'], '/api/register.php', 'POST', $missingAcademic);
    accountAssert($status === 422, 'Student registration requires a College / School selection');
    [$status] = accountRequest($base, $sessions['student'], '/api/register.php', 'POST',
        array_merge($studentPayload, [
            'user_id' => 'TESTACCT_BADCOL_' . $suffix,
            'email' => 'bad-college-' . strtolower($suffix) . '@example.invalid',
            'college' => 'TEST ONLY College',
        ]));
    accountAssert($status === 422, 'Student registration rejects a College / School outside the approved list');

    [$status, $response] = accountRequest($base, $sessions['admin'], '/api/accounts.php', 'POST',
        accountStaffPayload($users['created_staff'], 'staff-' . strtolower($suffix) . '@example.invalid'));
    accountAssert($status === 201 && $response['status'] === 'Disabled' &&
        $response['activation_email_sent'] === false && !array_key_exists('activation_token', $response),
        'Administrator can invite ordinary staff without exposing activation tokens');
    $staffQuery = $pdo->prepare('SELECT id, role, status, password_hash FROM users WHERE user_id = ?');
    $staffQuery->execute([$users['created_staff']]);
    $staffRow = $staffQuery->fetch(PDO::FETCH_ASSOC);
    $createdStaffDbId = (int)$staffRow['id'];
    accountAssert($staffRow && $staffRow['role'] === 'lecturer' && $staffRow['status'] === 'Disabled' &&
        password_verify('not-a-user-password', $staffRow['password_hash']) === false,
        'Staff invitations are inactive and have no user-chosen or emailed password');
    $staffCollegeQuery = $pdo->prepare('SELECT college FROM portal_account_profiles WHERE user_id = ?');
    $staffCollegeQuery->execute([$users['created_staff']]);
    accountAssert($staffCollegeQuery->fetchColumn() === 'College of Engineering and Applied Sciences',
        'Administrator-created staff account saves its approved College / School');
    $invalidStaff = accountStaffPayload(
        'TESTACCT_BADSTAFF_' . $suffix,
        'bad-staff-college-' . strtolower($suffix) . '@example.invalid'
    );
    $invalidStaff['college'] = 'TEST ONLY College';
    [$status] = accountRequest($base, $sessions['admin'], '/api/accounts.php', 'POST', $invalidStaff);
    accountAssert($status === 422, 'Staff account creation rejects a College / School outside the approved list');
    $tokenQuery = $pdo->prepare(
        'SELECT token_hash, expires_at, used_at FROM portal_account_activation_tokens
         WHERE user_id = ? ORDER BY id DESC LIMIT 1'
    );
    $tokenQuery->execute([$createdStaffDbId]);
    $storedToken = $tokenQuery->fetch(PDO::FETCH_ASSOC);
    accountAssert($storedToken && preg_match('/^[a-f0-9]{64}$/', $storedToken['token_hash']) === 1 &&
        strtotime($storedToken['expires_at']) > time() && $storedToken['used_at'] === null,
        'Activation token is stored as a hash with a 24-hour expiry');
    [$status, $resent] = accountRequest($base, $sessions['admin'], '/api/accounts.php', 'POST', [
        'action' => 'resend_invitation', 'user_id' => $users['created_staff'],
    ]);
    $tokenQuery->execute([$createdStaffDbId]);
    $resentToken = $tokenQuery->fetch(PDO::FETCH_ASSOC);
    $oldTokenUsed = $pdo->prepare(
        'SELECT used_at FROM portal_account_activation_tokens WHERE token_hash = ?'
    );
    $oldTokenUsed->execute([$storedToken['token_hash']]);
    $oldTokenUsedAt = $oldTokenUsed->fetchColumn();
    accountAssert($status === 200 && $resent['activation_email_sent'] === false &&
        $resentToken['token_hash'] !== $storedToken['token_hash'] &&
        $oldTokenUsedAt !== false && $oldTokenUsedAt !== null,
        'Resending an invitation rotates the single-use token without returning it to the administrator');

    [$status] = accountRequest($base, $sessions['admin'], '/api/accounts.php', 'POST',
        accountStaffPayload($users['created_admin'], 'admin-' . strtolower($suffix) . '@example.invalid', 'admin'));
    accountAssert($status === 403, 'Ordinary administrators cannot create administrator accounts');
    [$status] = accountRequest($base, $sessions['admin'], '/api/accounts.php', 'POST',
        accountStaffPayload('TESTACCT_X_' . $suffix, 'invalidrole-' . strtolower($suffix) . '@example.invalid', 'student'));
    accountAssert($status === 422, 'Staff invitation endpoint does not accept public student registration');
    [$status] = accountRequest($base, $sessions['admin'], '/api/accounts.php', 'POST', [
        'action' => 'change_role', 'user_id' => $users['admin'], 'role' => 'admin',
    ]);
    accountAssert($status === 422, 'Administrators cannot assign themselves additional roles');

    [$status] = accountRequest($base, $sessions['student'], '/api/accounts.php', 'POST',
        accountStaffPayload('TESTACCT_Y_' . $suffix, 'studentstaff-' . strtolower($suffix) . '@example.invalid'));
    accountAssert($status === 403, 'Students cannot create staff accounts through the management API');
    [$status] = accountRequest($base, $sessions['registry'], '/api/accounts.php', 'POST',
        accountStaffPayload('TESTACCT_Z_' . $suffix, 'registrystaff-' . strtolower($suffix) . '@example.invalid'));
    accountAssert($status === 403, 'Registry accounts cannot create staff accounts');

    $knownActivationToken = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE portal_account_activation_tokens SET token_hash = ? WHERE user_id = ? ORDER BY id DESC LIMIT 1')
        ->execute([hash('sha256', $knownActivationToken), $createdStaffDbId]);
    $pdo->prepare('UPDATE portal_account_activation_tokens SET expires_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 MINUTE) WHERE user_id = ? ORDER BY id DESC LIMIT 1')
        ->execute([$createdStaffDbId]);
    [$status] = accountRequest($base, null, '/api/activate_account.php', 'POST', [
        'token' => $knownActivationToken,
        'password' => 'New-Test-Only-Password!42',
    ], $sessions['student']['cookie'], $sessions['student']['csrf_token']);
    accountAssert($status === 422, 'Expired activation links cannot activate an account');
    $pdo->prepare('UPDATE portal_account_activation_tokens SET expires_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 HOUR) WHERE user_id = ? ORDER BY id DESC LIMIT 1')
        ->execute([$createdStaffDbId]);
    [$status, $activation] = accountRequest($base, null, '/api/activate_account.php', 'POST', [
        'token' => $knownActivationToken,
        'password' => 'New-Test-Only-Password!42',
    ], $sessions['student']['cookie'], $sessions['student']['csrf_token']);
    accountAssert($status === 200 && $activation['success'], 'Staff recipient can activate using the one-time token and establish a password');
    [$status] = accountRequest($base, null, '/api/activate_account.php', 'POST', [
        'token' => $knownActivationToken,
        'password' => 'Second-Test-Only-Password!42',
    ], $sessions['student']['cookie'], $sessions['student']['csrf_token']);
    accountAssert($status === 422, 'Activation token cannot be reused');
    [$status, $login] = accountRequest($base, null, '/api/login.php', 'POST', [
        'user_id' => $users['created_staff'],
        'password' => 'New-Test-Only-Password!42',
    ], $sessions['student']['cookie'], $sessions['student']['csrf_token']);
    accountAssert($status === 200 && $login['success'], 'Activated staff can sign in through the existing University ID login');

    [$status, $adminDenied] = accountRequest($base, $sessions['admin'], '/api/accounts.php', 'POST', [
        'action' => 'create_account',
        'user_id' => $users['created_admin'],
        'full_name' => '[TEST ONLY] Administrator',
        'email' => 'admin-' . strtolower($suffix) . '@example.invalid',
        'role' => 'admin',
        'college' => 'College of Business and Management Sciences',
    ]);
    accountAssert($status === 403, 'Regular administrator cannot promote or create another administrator');
    [$status, $superCreate] = accountRequest($base, $sessions['super_admin'], '/api/accounts.php', 'POST', [
        'action' => 'create_account',
        'user_id' => $users['created_admin'],
        'full_name' => '[TEST ONLY] Designated administrator',
        'email' => 'admin-' . strtolower($suffix) . '@example.invalid',
        'role' => 'admin',
        'college' => 'College of Business and Management Sciences',
        'make_super_administrator' => true,
    ]);
    accountAssert($status === 201 && $superCreate['status'] === 'Disabled',
        'Designated Super Administrator can create an inactive administrator and assign designation');
    $designationQuery = $pdo->prepare('SELECT assigned_by FROM portal_super_administrators WHERE user_id = ?');
    $designationQuery->execute([$users['created_admin']]);
    accountAssert($designationQuery->fetchColumn() === $users['super_admin'],
        'Super Administrator assignment records its assigning administrator');

    [$status, $accountList] = accountRequest($base, $sessions['admin'], '/api/accounts.php');
    accountAssert($status === 200 && isset($accountList['accounts']) &&
        !isset($accountList['accounts'][0]['password_hash']) &&
        !isset($accountList['accounts'][0]['token_hash']) &&
        count(array_filter(
            $accountList['accounts'],
            static fn(array $account): bool => $account['user_id'] === $users['created_staff'] &&
                $account['college'] === 'College of Engineering and Applied Sciences'
        )) === 1 &&
        $accountList['is_super_administrator'] === false,
        'Administrator directory shows College / School without returning password hashes or tokens');
    [$status, $adminList] = accountRequest($base, $sessions['super_admin'], '/api/accounts.php');
    accountAssert($status === 200 && $adminList['is_super_administrator'] === true,
        'Configured Super Administrator is recognized for privileged account management');

    [$status] = accountRequest($base, $sessions['admin'], '/api/accounts.php', 'POST', [
        'action' => 'deactivate_account', 'user_id' => $users['created_staff'],
    ]);
    accountAssert($status === 200, 'Administrator can deactivate ordinary staff');
    $pdo->prepare("UPDATE users SET status = 'Active' WHERE user_id = ?")->execute([$users['created_staff']]);
    [$status] = accountRequest($base, $sessions['admin'], '/api/accounts.php', 'POST', [
        'action' => 'set_college', 'user_id' => $users['created_staff'],
        'college' => 'College of Health, Agriculture and Natural Sciences',
    ]);
    accountAssert($status === 200, 'Administrator can change staff College / School assignment');
    $staffCollegeQuery->execute([$users['created_staff']]);
    accountAssert($staffCollegeQuery->fetchColumn() === 'College of Health, Agriculture and Natural Sciences',
        'College / School change is persisted');
    [$status] = accountRequest($base, $sessions['admin'], '/api/accounts.php', 'POST', [
        'action' => 'resend_invitation', 'user_id' => $users['super_admin'],
    ]);
    accountAssert($status === 403, 'Ordinary administrators cannot manage an administrator invitation');
    [$status] = accountRequest($base, $sessions['super_admin'], '/api/accounts.php', 'POST', [
        'action' => 'change_role', 'user_id' => $users['created_staff'], 'role' => 'admin',
    ]);
    accountAssert($status === 200, 'Designated Super Administrator can promote an existing staff account');
    [$status] = accountRequest($base, $sessions['admin'], '/api/accounts.php', 'POST', [
        'action' => 'deactivate_account', 'user_id' => $users['created_staff'],
    ]);
    accountAssert($status === 403, 'Ordinary administrator cannot manage a promoted administrator account');
    [$status] = accountRequest($base, $sessions['super_admin'], '/api/accounts.php', 'POST', [
        'action' => 'change_role', 'user_id' => $users['created_staff'], 'role' => 'lecturer',
    ]);
    accountAssert($status === 200, 'Designated Super Administrator can return the account to an ordinary role');

    $rejectPayload = accountCreateStudentPayload($users['reject_student'], 'reject-' . strtolower($suffix) . '@example.invalid');
    $publicSession = accountCsrfSession($base);
    [$status] = accountRequest($base, $publicSession, '/api/register.php', 'POST', $rejectPayload);
    accountAssert($status === 201, 'Second student enters the Registry verification queue');
    [$status, $queue] = accountRequest($base, $sessions['registry'], '/api/accounts.php');
    $pendingRegistrations = array_values(array_filter(
        $queue['students'],
        static fn(array $student): bool => in_array($student['user_id'], [$users['registered_student'], $users['reject_student']], true)
    ));
    accountAssert($status === 200 && count($pendingRegistrations) === 2 &&
        count(array_filter($pendingRegistrations, static fn(array $student): bool =>
            $student['user_id'] === $users['registered_student'] && $student['college'] === 'School of Law'
        )) === 1, 'Registry sees pending student registrations, College / School and academic details');
    [$status] = accountRequest($base, $sessions['registry'], '/api/accounts.php', 'POST', [
        'action' => 'reject_student', 'user_id' => $users['reject_student'], 'reason' => '',
    ]);
    accountAssert($status === 422, 'Registry rejection requires a reason');
    [$status, $rejection] = accountRequest($base, $sessions['registry'], '/api/accounts.php', 'POST', [
        'action' => 'reject_student', 'user_id' => $users['reject_student'], 'reason' => 'TEST ONLY: identity could not be matched.',
    ]);
    accountAssert($status === 200 && $rejection['verification_status'] === 'Rejected',
        'Registry can reject student identity with an audited reason');
    $rejectedStatus = $pdo->prepare('SELECT u.status, p.student_verification_status, p.verification_reason FROM users u JOIN portal_account_profiles p ON p.user_id=u.user_id WHERE u.user_id=?');
    $rejectedStatus->execute([$users['reject_student']]);
    $rejected = $rejectedStatus->fetch(PDO::FETCH_ASSOC);
    accountAssert($rejected['status'] === 'Disabled' && $rejected['student_verification_status'] === 'Rejected' &&
        $rejected['verification_reason'] === 'TEST ONLY: identity could not be matched.',
        'Rejected identity disables account but preserves verification reason');
    [$status, $verification] = accountRequest($base, $sessions['registry'], '/api/accounts.php', 'POST', [
        'action' => 'verify_student', 'user_id' => $users['registered_student'],
    ]);
    accountAssert($status === 200 && $verification['verification_status'] === 'Verified',
        'Registry can explicitly verify the student identity');
    $verifiedProfile = $pdo->prepare('SELECT student_verification_status, reviewed_by, reviewed_at FROM portal_account_profiles WHERE user_id=?');
    $verifiedProfile->execute([$users['registered_student']]);
    $verified = $verifiedProfile->fetch(PDO::FETCH_ASSOC);
    accountAssert($verified['student_verification_status'] === 'Verified' &&
        $verified['reviewed_by'] === $users['registry'] && !empty($verified['reviewed_at']),
        'Verified identity records the Registry reviewer and review date');

    $auditQuery = $pdo->prepare(
        "SELECT COUNT(*) FROM portal_audit WHERE record_type = 'user_account'
         AND record_id IN (SELECT id FROM users WHERE user_id IN (?, ?, ?, ?))"
    );
    $auditQuery->execute([$users['registered_student'], $users['reject_student'], $users['created_staff'], $users['created_admin']]);
    accountAssert((int)$auditQuery->fetchColumn() >= 8, 'Registration, activation, role, College / School and verification changes are audited');
    $notification = $pdo->prepare("SELECT COUNT(*) FROM portal_notifications WHERE recipient_user_id = ? AND record_type = 'account'");
    $notification->execute([$users['registered_student']]);
    accountAssert((int)$notification->fetchColumn() >= 1, 'Registry outcome is delivered through in-app notification');

    echo PHP_EOL . 'All ' . $testCount . ' account management integration checks passed.' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'TEST FAILURE: ' . $error->getMessage() . PHP_EOL);
    $failure = true;
} finally {
    try {
        ensureAccountManagementSchema($pdo);
    } catch (Throwable $schemaError) {
        fwrite(STDERR, 'TEST CLEANUP WARNING: account schema could not be restored: ' . $schemaError->getMessage() . PHP_EOL);
        $failure = true;
    }
    if ($server !== null && is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $testUserIds = array_values($users);
    $placeholders = implode(',', array_fill(0, count($testUserIds), '?'));
    $userDatabaseIds = $pdo->prepare("SELECT id FROM users WHERE user_id IN ($placeholders)");
    $userDatabaseIds->execute($testUserIds);
    $recordIds = array_map('intval', $userDatabaseIds->fetchAll(PDO::FETCH_COLUMN));
    if ($recordIds) {
        $recordMarks = implode(',', array_fill(0, count($recordIds), '?'));
        $pdo->prepare(
            "DELETE FROM portal_audit WHERE record_type = 'user_account'
             AND (record_id IN ($recordMarks) OR actor_user_id IN ($placeholders))"
        )->execute(array_merge($recordIds, $testUserIds));
        $pdo->prepare(
            "DELETE FROM portal_notifications WHERE record_type = 'account'
             AND (record_id IN ($recordMarks) OR recipient_user_id IN ($placeholders))"
        )->execute(array_merge($recordIds, $testUserIds));
    }
    $pdo->prepare("DELETE FROM portal_super_administrators WHERE user_id IN ($placeholders)")->execute($testUserIds);
    $pdo->prepare("DELETE FROM portal_account_activation_tokens WHERE user_id IN (SELECT id FROM users WHERE user_id IN ($placeholders))")
        ->execute($testUserIds);
    $pdo->prepare("DELETE FROM portal_account_profiles WHERE user_id IN ($placeholders)")->execute($testUserIds);
    $pdo->prepare("DELETE FROM users WHERE user_id IN ($placeholders)")->execute($testUserIds);
    if (is_string($serverLog) && is_file($serverLog)) unlink($serverLog);
}
if (isset($failure)) exit(1);
