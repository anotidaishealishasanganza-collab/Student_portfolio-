<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/bootstrap.php';

$pdo = database();
$databaseName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$allowedDatabase = getenv('AUSSH_RESULTS_TEST_DATABASE');
if (!is_string($allowedDatabase) || $allowedDatabase === '' || !hash_equals($allowedDatabase, $databaseName)) {
    fwrite(STDERR, "Set AUSSH_RESULTS_TEST_DATABASE to the exact non-production database name before running.\n");
    exit(2);
}
if ((int)$pdo->query('SELECT COUNT(*) FROM portal_academic_results')->fetchColumn() !== 0) {
    fwrite(STDERR, "Refusing to run: the selected database already contains academic results. Use an isolated test database.\n");
    exit(2);
}

$suffix = strtoupper(bin2hex(random_bytes(5)));
$password = 'TestOnly-' . $suffix . '!Pass';
$users = [
    'student' => 'TESTRES_S_' . $suffix,
    'other_student' => 'TESTRES_X_' . $suffix,
    'lecturer' => 'TESTRES_L_' . $suffix,
    'hod' => 'TESTRES_H_' . $suffix,
    'other_hod' => 'TESTRES_HX_' . $suffix,
    'registry' => 'TESTRES_R_' . $suffix,
    'admin' => 'TESTRES_A_' . $suffix,
];
$roles = [
    'student' => 'student',
    'other_student' => 'student',
    'lecturer' => 'lecturer',
    'hod' => 'hod',
    'other_hod' => 'hod',
    'registry' => 'registry',
    'admin' => 'admin',
];
$assignmentId = null;
$bulkAssignmentId = null;
$bulkSecondAssignmentId = null;
$resultId = null;
$batchId = null;
$importIds = [];
$importResultIds = [];
$temporaryFiles = [];
$server = null;
$serverLog = tempnam(sys_get_temp_dir(), 'aussh-results-test-');
$settings = $pdo->query('SELECT * FROM portal_result_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
$testCount = 0;

function testAssert(bool $condition, string $message): void
{
    global $testCount;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $testCount++;
    echo 'PASS: ' . $message . PHP_EOL;
}

function resultTestRequest(
    string $base,
    ?array $session,
    string $path,
    string $method = 'GET',
    ?array $body = null,
    ?string $cookieOverride = null,
    ?string $csrfOverride = null
): array
{
    $headers = ['Accept: application/json'];
    $content = '';
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/json';
        if ($csrfOverride !== null || $session !== null) {
            $headers[] = 'X-CSRF-Token: ' . ($csrfOverride ?? $session['csrf_token']);
        }
        $content = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    if ($cookieOverride !== null || $session !== null) {
        $headers[] = 'Cookie: ' . ($cookieOverride ?? $session['cookie']);
    }
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ]);
    $response = @file_get_contents($base . $path, false, $context);
    if (!is_string($response)) {
        $serverStatus = is_resource($GLOBALS['server'] ?? null)
            ? json_encode(proc_get_status($GLOBALS['server']))
            : 'server process unavailable';
        $logContents = is_string($GLOBALS['serverLog'] ?? null) && is_file($GLOBALS['serverLog'])
            ? file_get_contents($GLOBALS['serverLog'])
            : '';
        throw new RuntimeException(
            'HTTP request failed: ' . $path . '; server=' . $serverStatus .
            '; server log=' . (is_string($logContents) ? $logContents : '')
        );
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match)) {
            $status = (int)$match[1];
        }
    }
    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON response from ' . $path . ': ' . $response);
    }
    return [$status, $data, $http_response_header ?? []];
}

function resultTestApi(string $base, array $session, array $body): array
{
    [$status, $data] = resultTestRequest($base, $session, '/api/results.php', 'POST', $body);
    return [$status, $data];
}

function resultTestUpload(string $base, array $session, int $assignmentId, string $path, string $filename): array
{
    $boundary = '----------------AUSSH' . bin2hex(random_bytes(12));
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('Could not read the temporary test upload.');
    }
    $body = '--' . $boundary . "\r\n" .
        "Content-Disposition: form-data; name=\"action\"\r\n\r\npreview_import\r\n" .
        '--' . $boundary . "\r\n" .
        "Content-Disposition: form-data; name=\"assignment_id\"\r\n\r\n" . $assignmentId . "\r\n" .
        '--' . $boundary . "\r\n" .
        'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n" .
        "Content-Type: text/csv\r\n\r\n" . $contents . "\r\n" .
        '--' . $boundary . "--\r\n";
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Accept: application/json',
                'Content-Type: multipart/form-data; boundary=' . $boundary,
                'X-CSRF-Token: ' . $session['csrf_token'],
                'Cookie: ' . $session['cookie'],
            ]),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);
    $response = @file_get_contents($base . '/api/results.php', false, $context);
    if (!is_string($response)) {
        throw new RuntimeException('Multipart results upload failed.');
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match)) {
            $status = (int)$match[1];
        }
    }
    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid multipart results response: ' . $response);
    }
    return [$status, $data];
}

function resultTestLogin(string $base, string $userId, string $password): array
{
    [$csrfStatus, $csrfData, $csrfHeaders] = resultTestRequest($base, null, '/api/csrf.php');
    $cookie = '';
    foreach ($csrfHeaders as $header) {
        if (stripos($header, 'Set-Cookie: AU_SUCCESS_SESSION=') === 0) {
            $cookie = explode(';', trim(substr($header, strlen('Set-Cookie: '))))[0];
            break;
        }
    }
    if ($csrfStatus !== 200 || empty($csrfData['csrf_token']) || $cookie === '') {
        throw new RuntimeException('Could not obtain a CSRF token for a test login.');
    }
    [$status, $data, $headers] = resultTestRequest($base, null, '/api/login.php', 'POST', [
        'user_id' => $userId,
        'password' => $password,
    ], $cookie, (string)$csrfData['csrf_token']);
    if ($status !== 200 || empty($data['success']) || empty($data['csrf_token'])) {
        throw new RuntimeException(
            'Test account login failed for ' . $userId . ' (HTTP ' . $status . '): ' .
            (string)($data['message'] ?? 'No login response message.')
        );
    }
    foreach ($headers as $header) {
        if (stripos($header, 'Set-Cookie: AU_SUCCESS_SESSION=') === 0) {
            $cookie = explode(';', trim(substr($header, strlen('Set-Cookie: '))))[0];
            break;
        }
    }
    if ($cookie === '') {
        throw new RuntimeException('Login did not create an authenticated test session.');
    }
    return ['cookie' => $cookie, 'csrf_token' => $data['csrf_token']];
}

try {
    $pdo->beginTransaction();
    $insertUser = $pdo->prepare(
        'INSERT INTO users (user_id, full_name, email, role, password_hash, status)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($users as $key => $userId) {
        $insertUser->execute([
            $userId,
            '[TEST ONLY] Results workflow ' . $key,
            'invalid-' . strtolower($userId),
            $roles[$key],
            password_hash($password, PASSWORD_DEFAULT),
            'Active',
        ]);
    }
    $pdo->commit();

    $serverSocket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$serverSocket) {
        throw new RuntimeException('Could not reserve a local test server port: ' . $error);
    }
    $address = stream_socket_get_name($serverSocket, false);
    fclose($serverSocket);
    $port = (int)substr((string)$address, strrpos((string)$address, ':') + 1);
    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__)],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $serverLog, 'a'],
            2 => ['file', $serverLog, 'a'],
        ],
        $pipes,
        dirname(__DIR__),
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($server)) {
        throw new RuntimeException('Could not start the local PHP test server.');
    }
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
    if (!$ready) {
        throw new RuntimeException('Local PHP test server did not become ready.');
    }

    $sessions = [];
    foreach ($users as $key => $userId) {
        $sessions[$key] = resultTestLogin($base, $userId, $password);
    }

    $registry = $sessions['registry'];
    [$status] = resultTestApi($base, $registry, [
        'action' => 'save_settings',
        'coursework_max' => 40,
        'examination_max' => 60,
        'grade_scale' => '',
    ]);
    testAssert($status === 200, 'Registry can configure test-only assessment maxima without inventing grade boundaries');

    $courseCode = 'TST' . $suffix;
    $bulkCourseCode = 'BULK' . $suffix;
    [$status, $response] = resultTestApi($base, $registry, [
        'action' => 'create_assignment',
        'student_user_id' => $users['student'],
        'lecturer_user_id' => $users['lecturer'],
        'hod_user_id' => $users['hod'],
        'department_code' => 'TEST-ONLY',
        'course_code' => $courseCode,
        'course_name' => '[TEST ONLY] Results workflow course',
        'academic_year' => '2098/2099',
        'semester' => 'TEST-' . $suffix,
    ]);
    testAssert($status === 201, 'Registry can allocate a clearly labelled test course to student, lecturer and HOD');
    $assignmentId = (int)$response['assignment_id'];

    [$status, $response] = resultTestApi($base, $registry, [
        'action' => 'create_assignment',
        'student_user_id' => $users['student'],
        'lecturer_user_id' => $users['lecturer'],
        'hod_user_id' => $users['hod'],
        'department_code' => 'TEST-ONLY',
        'course_code' => $bulkCourseCode,
        'course_name' => '[TEST ONLY] Bulk results workflow course',
        'academic_year' => '2098/2099',
        'semester' => 'TEST-' . $suffix,
    ]);
    testAssert($status === 201, 'Registry allocates the first student in the bulk test class');
    $bulkAssignmentId = (int)$response['assignment_id'];

    [$status, $response] = resultTestApi($base, $registry, [
        'action' => 'create_assignment',
        'student_user_id' => $users['other_student'],
        'lecturer_user_id' => $users['lecturer'],
        'hod_user_id' => $users['hod'],
        'department_code' => 'TEST-ONLY',
        'course_code' => $bulkCourseCode,
        'course_name' => '[TEST ONLY] Bulk results workflow course',
        'academic_year' => '2098/2099',
        'semester' => 'TEST-' . $suffix,
    ]);
    testAssert($status === 201, 'Registry allocates a complete test class for bulk import');
    $bulkSecondAssignmentId = (int)$response['assignment_id'];

    $lecturer = $sessions['lecturer'];
    $templateContext = stream_context_create([
        'http' => ['header' => 'Cookie: ' . $lecturer['cookie'] . "\r\n"],
    ]);
    $template = file_get_contents(
        $base . '/api/results.php?action=download_template&assignment_id=' . $bulkAssignmentId,
        false,
        $templateContext
    );
    testAssert(
        is_string($template) &&
        str_getcsv((string)strtok((string)$template, "\r\n")) ===
            ['Student ID', 'Course Code', 'Coursework Mark', 'Examination Mark'] &&
        str_contains($template, $users['student']) &&
        str_contains($template, $users['other_student']),
        'Lecturer template downloads the full assigned class roster with the required columns'
    );

    $invalidCsv = tempnam(sys_get_temp_dir(), 'aussh-results-invalid-');
    $temporaryFiles[] = $invalidCsv;
    file_put_contents($invalidCsv, implode("\r\n", [
        'Student ID,Course Code,Coursework Mark,Examination Mark',
        $users['student'] . ',WRONG,41,70',
        $users['other_student'] . ',' . $bulkCourseCode . ',20,30',
        'TESTRES_UNKNOWN,' . $bulkCourseCode . ',10,20',
        $users['other_student'] . ',' . $bulkCourseCode . ',20,20',
    ]));
    [$status, $response] = resultTestUpload($base, $lecturer, $bulkAssignmentId, $invalidCsv, 'TEST_ONLY_invalid.csv');
    testAssert($status === 201 && $response['import']['valid_rows'] === 0 &&
        $response['import']['invalid_rows'] === 4,
        'Bulk preview parses CSV and identifies invalid student, course, marks and duplicate class rows');
    $invalidImportId = (int)$response['import']['id'];
    $importIds[] = $invalidImportId;
    $rowErrors = implode(' ', array_merge(...array_map(
        static fn(array $row): array => $row['validation_errors'],
        $response['rows']
    )));
    testAssert(
        str_contains($rowErrors, 'Course Code must match') &&
        str_contains($rowErrors, 'Coursework Mark must be a number') &&
        str_contains($rowErrors, 'Examination Mark must be a number') &&
        str_contains($rowErrors, 'active student account') &&
        str_contains($rowErrors, 'more than once'),
        'Invalid row preview explains every validation error without silently skipping rows'
    );
    [$status] = resultTestApi($base, $lecturer, [
        'action' => 'import_drafts', 'import_token' => $response['import']['import_token'],
    ]);
    testAssert($status === 422, 'Invalid class preview cannot import even its otherwise valid rows');

    $missingCsv = tempnam(sys_get_temp_dir(), 'aussh-results-missing-');
    $temporaryFiles[] = $missingCsv;
    file_put_contents($missingCsv, implode("\r\n", [
        'Student ID,Course Code,Coursework Mark,Examination Mark',
        $users['other_student'] . ',' . $bulkCourseCode . ',30,45',
    ]));
    [$status, $response] = resultTestUpload($base, $lecturer, $bulkAssignmentId, $missingCsv, 'TEST_ONLY_missing.csv');
    $importIds[] = (int)$response['import']['id'];
    testAssert($status === 201 && $response['import']['invalid_rows'] === 1 &&
        $response['rows'][1]['row_type'] === 'missing' &&
        str_contains(implode(' ', $response['rows'][1]['validation_errors']), 'missing from this class upload'),
        'Bulk preview clearly identifies students omitted from the class roster');

    $validCsv = tempnam(sys_get_temp_dir(), 'aussh-results-valid-');
    $temporaryFiles[] = $validCsv;
    file_put_contents($validCsv, implode("\r\n", [
        'Student ID,Course Code,Coursework Mark,Examination Mark',
        $users['student'] . ',' . $bulkCourseCode . ',35,50',
        $users['other_student'] . ',' . $bulkCourseCode . ',30,45',
    ]));
    $xlsxFile = tempnam(sys_get_temp_dir(), 'aussh-results-xlsx-');
    $temporaryFiles[] = $xlsxFile;
    file_put_contents($xlsxFile, 'TEST ONLY: not an XLSX workbook');
    [$status, $response] = resultTestUpload($base, $lecturer, $bulkAssignmentId, $xlsxFile, 'TEST_ONLY.xlsx');
    testAssert($status === 415 && str_contains($response['message'], 'PhpSpreadsheet is not installed'),
        'XLSX uploads fail explicitly with CSV conversion guidance when PhpSpreadsheet is unavailable');
    [$status, $response] = resultTestUpload($base, $lecturer, $bulkAssignmentId, $validCsv, 'TEST_ONLY_valid.csv');
    testAssert($status === 201 && $response['import']['valid_rows'] === 2 &&
        $response['import']['invalid_rows'] === 0,
        'A complete, valid class CSV previews without errors');
    $validImportId = (int)$response['import']['id'];
    $validImportToken = (string)$response['import']['import_token'];
    $importIds[] = $validImportId;
    [$status, $response] = resultTestApi($base, $lecturer, [
        'action' => 'import_drafts', 'import_token' => $validImportToken,
    ]);
    testAssert($status === 200 && $response['result_count'] === 2,
        'Lecturer confirms valid bulk rows into MySQL as drafts');
    $lecturerData = resultTestRequest($base, $lecturer, '/api/results.php')[1];
    $importResultIds = array_values(array_map(
        static fn(array $row): int => (int)$row['id'],
        array_filter($lecturerData['results'], static fn(array $row): bool => (int)$row['import_id'] === $validImportId)
    ));
    testAssert(count($importResultIds) === 2 &&
        count(array_filter(
            $lecturerData['results'],
            static fn(array $row): bool => in_array((int)$row['id'], $importResultIds, true) &&
                $row['workflow_status'] === 'Draft'
        )) === 2,
        'Bulk imported results remain drafts until the lecturer confirms HOD submission');
    [$status, $duplicatePreview] = resultTestUpload(
        $base, $lecturer, $bulkAssignmentId, $validCsv, 'TEST_ONLY_duplicate.csv'
    );
    $importIds[] = (int)$duplicatePreview['import']['id'];
    $duplicateErrors = implode(' ', array_merge(...array_map(
        static fn(array $row): array => $row['validation_errors'],
        $duplicatePreview['rows']
    )));
    testAssert($status === 201 && $duplicatePreview['import']['invalid_rows'] === 2 &&
        str_contains($duplicateErrors, 'A result already exists'),
        'Bulk preview detects existing duplicate student/course/semester results');
    [$status, $resumeData] = resultTestApi($base, $lecturer, [
        'action' => 'get_import', 'import_token' => $validImportToken,
    ]);
    testAssert($status === 200 && count($resumeData['rows']) === 2,
        'Lecturer can securely resume an audited import preview');
    [$status] = resultTestApi($base, $lecturer, [
        'action' => 'submit_import', 'import_token' => $validImportToken, 'confirmed' => false,
    ]);
    testAssert($status === 422, 'Bulk HOD submission requires explicit lecturer confirmation');
    [$status, $response] = resultTestApi($base, $lecturer, [
        'action' => 'submit_import', 'import_token' => $validImportToken, 'confirmed' => true,
    ]);
    testAssert($status === 200 && $response['result_count'] === 2,
        'Lecturer confirmation submits the whole bulk batch to the HOD without publishing it');
    $hodData = resultTestRequest($base, $sessions['hod'], '/api/results.php')[1];
    testAssert(count(array_filter(
        $hodData['results'],
        static fn(array $row): bool => in_array((int)$row['id'], $importResultIds, true) &&
            $row['workflow_status'] === 'Submitted'
    )) === 2, 'Bulk confirmed results appear in the assigned HOD approval queue');

    [$status, $response] = resultTestApi($base, $sessions['admin'], [
        'action' => 'get_import', 'import_token' => $validImportToken,
    ]);
    testAssert($status === 403, 'Import preview tokens cannot be used by unrelated roles');

    [$status] = resultTestApi($base, $lecturer, [
        'action' => 'save_draft',
        'assignment_id' => $assignmentId,
        'coursework_mark' => 41,
        'examination_mark' => 50,
    ]);
    testAssert($status === 422, 'Marks above the configured component maximum are rejected');
    [$status, $response] = resultTestApi($base, $lecturer, [
        'action' => 'save_draft',
        'assignment_id' => $assignmentId,
        'coursework_mark' => 35,
        'examination_mark' => 50,
    ]);
    testAssert($status === 200, 'Lecturer can save an assigned result draft');
    $resultId = (int)$response['result_id'];
    [$status] = resultTestApi($base, $registry, [
        'action' => 'save_settings',
        'coursework_max' => 50,
        'examination_max' => 50,
        'grade_scale' => '',
    ]);
    testAssert($status === 409, 'Marking rules cannot be changed after result entry begins');

    [$status, $studentData] = resultTestRequest($base, $sessions['student'], '/api/results.php');
    testAssert($status === 200 && count($studentData['results']) === 0, 'Student cannot see a draft or any unpublished result');

    [$status] = resultTestApi($base, $lecturer, [
        'action' => 'save_draft',
        'assignment_id' => $assignmentId,
        'coursework_mark' => 34,
        'examination_mark' => 50,
    ]);
    testAssert($status === 409, 'Duplicate student/course/year/semester results are rejected');

    [$lecturerPublishStatus] = resultTestApi($base, $lecturer, [
        'action' => 'publish', 'academic_year' => '2098/2099', 'semester' => 'TEST-' . $suffix, 'confirmed' => true,
    ]);
    [$hodPublishStatus] = resultTestApi($base, $sessions['hod'], [
        'action' => 'publish', 'academic_year' => '2098/2099', 'semester' => 'TEST-' . $suffix, 'confirmed' => true,
    ]);
    testAssert($lecturerPublishStatus === 403 && $hodPublishStatus === 403, 'Lecturers and HODs cannot publish official results');

    [$status] = resultTestApi($base, $lecturer, ['action' => 'submit', 'result_id' => $resultId]);
    testAssert($status === 200, 'Lecturer can submit a result to the HOD');

    [$status, $response] = resultTestApi($base, $sessions['other_hod'], [
        'action' => 'review', 'result_id' => $resultId, 'decision' => 'approve',
    ]);
    testAssert($status === 403, 'HOD cannot review a result outside the assigned department allocation');

    [$status] = resultTestApi($base, $registry, [
        'action' => 'publish', 'academic_year' => '2098/2099', 'semester' => 'TEST-' . $suffix, 'confirmed' => true,
    ]);
    testAssert($status === 409, 'Registry cannot publish a submitted but unapproved result');

    [$status, $response] = resultTestApi($base, $sessions['hod'], [
        'action' => 'review', 'result_id' => $resultId, 'decision' => 'return', 'reason' => 'TEST ONLY: verify corrected coursework mark.',
    ]);
    testAssert($status === 200 && $response['status'] === 'Returned', 'Assigned HOD can return a result with a recorded reason');

    [$status] = resultTestApi($base, $lecturer, [
        'action' => 'save_draft', 'assignment_id' => $assignmentId, 'result_id' => $resultId,
        'coursework_mark' => 38, 'examination_mark' => 55,
    ]);
    testAssert($status === 200, 'Lecturer can correct and save a returned result');
    [$status] = resultTestApi($base, $lecturer, ['action' => 'submit', 'result_id' => $resultId]);
    testAssert($status === 200, 'Lecturer can resubmit the corrected result');
    [$status, $response] = resultTestApi($base, $sessions['hod'], [
        'action' => 'review', 'result_id' => $resultId, 'decision' => 'approve',
    ]);
    testAssert($status === 200 && $response['status'] === 'Approved', 'Assigned HOD can approve and forward a resubmitted result');
    [$status, $registryData] = resultTestRequest($base, $registry, '/api/results.php');
    $approvedRow = array_values(array_filter(
        $registryData['results'],
        static fn(array $row): bool => (int)$row['id'] === $resultId
    ))[0] ?? null;
    testAssert($status === 200 && $approvedRow &&
        $approvedRow['hod_reviewer_id'] === $users['hod'] &&
        !empty($approvedRow['hod_reviewed_at']),
        'HOD reviewer identity and review date are recorded');

    [$status] = resultTestApi($base, $sessions['hod'], [
        'action' => 'publish', 'academic_year' => '2098/2099', 'semester' => 'TEST-' . $suffix, 'confirmed' => true,
    ]);
    testAssert($status === 403, 'HOD cannot publish an approved result');
    [$status, $studentData] = resultTestRequest($base, $sessions['student'], '/api/results.php');
    testAssert($status === 200 && count($studentData['results']) === 0,
        'Student cannot see a HOD-approved result before Registry publication');

    [$status, $response] = resultTestApi($base, $registry, [
        'action' => 'publish', 'academic_year' => '2098/2099',
        'semester' => 'TEST-' . $suffix, 'confirmed' => true,
    ]);
    testAssert($status === 200 && (int)$response['result_count'] === 1, 'Registry can publish an HOD-approved semester batch');
    $batchId = (int)$response['batch_id'];
    [$status, $registryData] = resultTestRequest($base, $registry, '/api/results.php');
    $publishedRow = array_values(array_filter(
        $registryData['results'],
        static fn(array $row): bool => (int)$row['id'] === $resultId
    ))[0] ?? null;
    testAssert($status === 200 && $publishedRow &&
        $publishedRow['registry_publisher_id'] === $users['registry'] &&
        (int)$publishedRow['publication_batch_id'] === $batchId &&
        !empty($publishedRow['publication_date']),
        'Publication officer, date and batch are persisted');

    [$status, $studentData] = resultTestRequest($base, $sessions['student'], '/api/results.php');
    testAssert($status === 200 && count($studentData['results']) === 1 &&
        (float)$studentData['results'][0]['total_mark'] === 93.0 &&
        $studentData['results'][0]['grade'] === null,
        'Student sees their published result with correct total and no invented grade');
    [$status, $otherStudentData] = resultTestRequest($base, $sessions['other_student'], '/api/results.php');
    testAssert($status === 200 && count($otherStudentData['results']) === 0, "Student cannot view another student's published result");

    [$status] = resultTestApi($base, $lecturer, [
        'action' => 'save_draft', 'assignment_id' => $assignmentId, 'result_id' => $resultId,
        'coursework_mark' => 39, 'examination_mark' => 55,
    ]);
    testAssert($status === 409, 'Lecturer cannot edit an approved or published result');

    [$status] = resultTestApi($base, $registry, [
        'action' => 'correct_published', 'result_id' => $resultId,
        'coursework_mark' => 39, 'examination_mark' => 55, 'reason' => '',
    ]);
    testAssert($status === 422, 'Registry correction requires a reason');
    [$status] = resultTestApi($base, $registry, [
        'action' => 'correct_published', 'result_id' => $resultId,
        'coursework_mark' => 39, 'examination_mark' => 55,
        'reason' => 'TEST ONLY: correction validation.',
    ]);
    testAssert($status === 200, 'Registry can correct a published result through the audited correction action');

    [$status, $adminData] = resultTestRequest($base, $sessions['admin'], '/api/results.php');
    testAssert($status === 200 && $adminData['counts']['published'] === 1 &&
        count($adminData['batches']) === 1 && count($adminData['audit']) > 0 &&
        !array_key_exists('results', $adminData),
        'Administrator can view counts, publication history and audit without bulk student marks');
    [$status] = resultTestApi($base, $sessions['admin'], [
        'action' => 'review', 'result_id' => $resultId, 'decision' => 'approve',
    ]);
    testAssert($status === 403, 'Administrator cannot bypass the HOD approval process');
    [$status] = resultTestApi($base, $sessions['admin'], [
        'action' => 'publish', 'academic_year' => '2098/2099', 'semester' => 'TEST-' . $suffix, 'confirmed' => true,
    ]);
    testAssert($status === 403, 'Administrator cannot publish results');

    $noticeQuery = $pdo->prepare(
        "SELECT recipient_user_id, title FROM portal_notifications
         WHERE record_type = 'academic_result' AND record_id = ?"
    );
    $noticeQuery->execute([$resultId]);
    $recipients = array_column($noticeQuery->fetchAll(PDO::FETCH_ASSOC), 'recipient_user_id');
    testAssert(
        in_array($users['hod'], $recipients, true) &&
        in_array($users['lecturer'], $recipients, true) &&
        in_array($users['student'], $recipients, true) &&
        in_array($users['registry'], $recipients, true),
        'Submission, HOD decisions, Registry approval and publication create in-app notifications'
    );
    $auditQuery = $pdo->prepare(
        "SELECT COUNT(*) FROM portal_audit WHERE record_type = 'academic_result' AND record_id = ?"
    );
    $auditQuery->execute([$resultId]);
    $historyQuery = $pdo->prepare('SELECT COUNT(*) FROM portal_result_reviews WHERE result_id = ?');
    $historyQuery->execute([$resultId]);
    $returnQuery = $pdo->prepare(
        "SELECT reason FROM portal_result_reviews WHERE result_id = ? AND action = 'hod_returned' LIMIT 1"
    );
    $returnQuery->execute([$resultId]);
    $returnReason = $returnQuery->fetchColumn();
    $correctionQuery = $pdo->prepare(
        "SELECT before_values, after_values, reason FROM portal_result_reviews
         WHERE result_id = ? AND action = 'registry_correction' LIMIT 1"
    );
    $correctionQuery->execute([$resultId]);
    $correction = $correctionQuery->fetch(PDO::FETCH_ASSOC);
    testAssert((int)$auditQuery->fetchColumn() >= 5 && (int)$historyQuery->fetchColumn() >= 6,
        'Result actions and before/after correction history are recorded in audit tables');
    testAssert($returnReason === 'TEST ONLY: verify corrected coursework mark.' && $correction &&
        (float)json_decode($correction['before_values'], true)['total_mark'] === 93.0 &&
        (float)json_decode($correction['after_values'], true)['total_mark'] === 94.0 &&
        $correction['reason'] === 'TEST ONLY: correction validation.',
        'HOD return reason and Registry correction snapshots are preserved');
    $importAuditQuery = $pdo->prepare(
        "SELECT action FROM portal_audit
         WHERE record_type = 'academic_result_import' AND record_id = ? ORDER BY id"
    );
    $importAuditQuery->execute([$validImportId]);
    $importActions = $importAuditQuery->fetchAll(PDO::FETCH_COLUMN);
    $importRecordQuery = $pdo->prepare(
        'SELECT lecturer_user_id, file_sha256, import_status
         FROM portal_result_imports WHERE id = ?'
    );
    $importRecordQuery->execute([$validImportId]);
    $importRecord = $importRecordQuery->fetch(PDO::FETCH_ASSOC);
    testAssert(
        $importRecord && $importRecord['lecturer_user_id'] === $users['lecturer'] &&
        preg_match('/^[a-f0-9]{64}$/', $importRecord['file_sha256']) === 1 &&
        $importRecord['import_status'] === 'Submitted' &&
        in_array('bulk_import_previewed', $importActions, true) &&
        in_array('bulk_drafts_saved', $importActions, true) &&
        in_array('bulk_import_submitted', $importActions, true),
        'Import uploader, file fingerprint, and preview/draft/submission audit trail are recorded'
    );

    echo PHP_EOL . 'All ' . $testCount . ' academic results integration checks passed.' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'TEST FAILURE: ' . $error->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($importIds) {
        $placeholders = implode(',', array_fill(0, count($importIds), '?'));
        $pdo->prepare(
            "DELETE FROM portal_result_import_rows WHERE import_id IN ($placeholders)"
        )->execute($importIds);
    }
    $cleanupResultIds = array_values(array_unique(array_filter(array_merge([$resultId], $importResultIds))));
    foreach ($cleanupResultIds as $cleanupResultId) {
        $pdo->prepare('DELETE FROM portal_result_reviews WHERE result_id = ?')->execute([$cleanupResultId]);
        $pdo->prepare(
            "DELETE FROM portal_notifications WHERE record_type = 'academic_result' AND record_id = ?"
        )->execute([$cleanupResultId]);
        $pdo->prepare(
            "DELETE FROM portal_audit WHERE record_type = 'academic_result' AND record_id = ?"
        )->execute([$cleanupResultId]);
        $pdo->prepare('DELETE FROM portal_academic_results WHERE id = ?')->execute([$cleanupResultId]);
    }
    foreach ($importIds as $importId) {
        $pdo->prepare(
            "DELETE FROM portal_audit WHERE record_type = 'academic_result_import' AND record_id = ?"
        )->execute([$importId]);
        $pdo->prepare('DELETE FROM portal_result_imports WHERE id = ?')->execute([$importId]);
    }
    if ($batchId !== null) {
        $pdo->prepare(
            "DELETE FROM portal_audit WHERE record_type = 'academic_result_batch' AND record_id = ?"
        )->execute([$batchId]);
        $pdo->prepare('DELETE FROM portal_result_publication_batches WHERE id = ?')->execute([$batchId]);
    }
    foreach (array_filter([$assignmentId, $bulkAssignmentId, $bulkSecondAssignmentId]) as $cleanupAssignmentId) {
        $pdo->prepare(
            "DELETE FROM portal_audit WHERE record_type = 'academic_result_assignment' AND record_id = ?"
        )->execute([$cleanupAssignmentId]);
        $pdo->prepare('DELETE FROM portal_result_assignments WHERE id = ?')->execute([$cleanupAssignmentId]);
    }
    if ($settings) {
        $restore = $pdo->prepare(
            'UPDATE portal_result_settings
             SET coursework_max = ?, examination_max = ?, total_max = ?, grade_scale = ?, updated_by = ?, updated_at = ?
             WHERE id = 1'
        );
        $restore->execute([
            $settings['coursework_max'], $settings['examination_max'], $settings['total_max'],
            $settings['grade_scale'], $settings['updated_by'], $settings['updated_at'],
        ]);
    }
    $pdo->prepare(
        "DELETE FROM portal_audit
         WHERE record_type = 'academic_result_settings' AND record_id = 1 AND actor_user_id = ?"
    )->execute([$users['registry']]);
    $deleteUser = $pdo->prepare('DELETE FROM users WHERE user_id = ?');
    foreach ($users as $userId) {
        $deleteUser->execute([$userId]);
    }
    if (is_string($serverLog) && is_file($serverLog)) {
        unlink($serverLog);
    }
    foreach ($temporaryFiles as $temporaryFile) {
        if (is_string($temporaryFile) && is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
    }
}

if (isset($exitCode)) {
    exit($exitCode);
}
