<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/api/achievement_evidence_lib.php';

$pdo = database();
$databaseName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$allowedDatabase = getenv('AUSSH_EVIDENCE_TEST_DATABASE');
if (!is_string($allowedDatabase) || $allowedDatabase === '' || !hash_equals($allowedDatabase, $databaseName)) {
    fwrite(STDERR, "Set AUSSH_EVIDENCE_TEST_DATABASE to the exact non-production database name before running.\n");
    exit(2);
}
ensureAchievementEvidenceSchema($pdo);

$suffix = strtoupper(bin2hex(random_bytes(5)));
$password = 'TestOnly-' . $suffix . '!Pass';
$users = [
    'student' => 'TESTEVID_S_' . $suffix,
    'other_student' => 'TESTEVID_X_' . $suffix,
    'verifier' => 'TESTEVID_V_' . $suffix,
    'admin' => 'TESTEVID_A_' . $suffix,
];
$roles = [
    'student' => 'student',
    'other_student' => 'student',
    'verifier' => 'verifier',
    'admin' => 'admin',
];
$server = null;
$serverLog = tempnam(sys_get_temp_dir(), 'aussh-evidence-server-');
$temporaryFiles = [];
$recordIds = [];
$storagePaths = [];
$sessions = [];
$storageDirectory = '';
$storageDirectoryExisted = false;
$testCount = 0;

function evidenceAssert(bool $condition, string $message): void
{
    global $testCount;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $testCount++;
    echo 'PASS: ' . $message . PHP_EOL;
}

function evidenceRequest(
    string $base,
    ?array $session,
    string $path,
    string $method = 'GET',
    ?array $body = null,
    ?string $csrfOverride = null,
    ?string $cookieOverride = null
): array {
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
            'timeout' => 30,
        ],
    ]);
    $response = @file_get_contents($base . $path, false, $context);
    if (!is_string($response)) {
        throw new RuntimeException('HTTP request failed: ' . $path);
    }
    $status = evidenceHttpStatus($http_response_header ?? []);
    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON response from ' . $path . ': ' . $response);
    }
    return [$status, $data, $http_response_header ?? []];
}

function evidenceHttpStatus(array $headers): int
{
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match)) {
            return (int)$match[1];
        }
    }
    return 0;
}

function evidenceLogin(string $base, string $userId, string $password): array
{
    [$csrfStatus, $csrfData, $csrfHeaders] = evidenceRequest($base, null, '/api/csrf.php');
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
    [$status, $data, $headers] = evidenceRequest($base, null, '/api/login.php', 'POST', [
        'user_id' => $userId,
        'password' => $password,
    ], (string)$csrfData['csrf_token'], $cookie);
    if ($status !== 200 || empty($data['success']) || empty($data['csrf_token'])) {
        throw new RuntimeException('Test login failed for ' . $userId . ': ' . (string)($data['message'] ?? ''));
    }
    foreach ($headers as $header) {
        if (stripos($header, 'Set-Cookie: AU_SUCCESS_SESSION=') === 0) {
            $cookie = explode(';', trim(substr($header, strlen('Set-Cookie: '))))[0];
            break;
        }
    }
    return ['cookie' => $cookie, 'csrf_token' => (string)$data['csrf_token']];
}

function evidenceMultipart(
    string $base,
    ?array $session,
    array $fields,
    array $files,
    ?string $csrfOverride = null
): array {
    $boundary = '----------------AUSSH' . bin2hex(random_bytes(12));
    $body = '';
    foreach ($fields as $name => $value) {
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
        $body .= (string)$value . "\r\n";
    }
    foreach ($files as $file) {
        $contents = file_get_contents($file['path']);
        if (!is_string($contents)) {
            throw new RuntimeException('Could not read a temporary upload file.');
        }
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="evidence_files[]"; filename="' .
            str_replace(['"', "\r", "\n"], '', $file['name']) . '"' . "\r\n";
        $body .= 'Content-Type: application/octet-stream' . "\r\n\r\n";
        $body .= $contents . "\r\n";
    }
    $body .= '--' . $boundary . "--\r\n";
    $headers = [
        'Accept: application/json',
        'Content-Type: multipart/form-data; boundary=' . $boundary,
    ];
    if ($session !== null || $csrfOverride !== null) {
        $headers[] = 'X-CSRF-Token: ' . ($csrfOverride ?? $session['csrf_token']);
    }
    if ($session !== null) {
        $headers[] = 'Cookie: ' . $session['cookie'];
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 45,
        ],
    ]);
    $response = @file_get_contents($base . '/api/workflows.php', false, $context);
    if (!is_string($response)) {
        throw new RuntimeException('Multipart evidence request failed.');
    }
    $status = evidenceHttpStatus($http_response_header ?? []);
    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid multipart evidence response: ' . $response);
    }
    return [$status, $data];
}

function evidenceDownload(string $base, ?array $session, int $attachmentId, bool $preview = false): array
{
    $headers = [];
    if ($session !== null) {
        $headers[] = 'Cookie: ' . $session['cookie'];
    }
    $query = '/api/achievement_evidence_download.php?id=' . $attachmentId . ($preview ? '&preview=1' : '');
    $context = stream_context_create([
        'http' => [
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);
    $body = @file_get_contents($base . $query, false, $context);
    if (!is_string($body)) {
        throw new RuntimeException('Evidence download request failed.');
    }
    return [evidenceHttpStatus($http_response_header ?? []), $body, $http_response_header ?? []];
}

function evidenceTemporaryFile(string $contents, string $prefix): string
{
    $path = tempnam(sys_get_temp_dir(), $prefix);
    if (!is_string($path) || file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Could not create a temporary evidence file.');
    }
    $GLOBALS['temporaryFiles'][] = $path;
    return $path;
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
            '[TEST ONLY] Achievement evidence ' . $key,
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
    foreach ($users as $key => $userId) {
        $sessions[$key] = evidenceLogin($base, $userId, $password);
    }

    $pdf = evidenceTemporaryFile("%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n", 'aussh-evidence-pdf-');
    $jpegContents = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/AV//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/AV//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Al//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/IV//2gAMAwEAAgADAAAAEJ//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2gAMAwEAAgADAAAAEJ//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2gAMAwEAAgADAAAAEJ//2Q==', true);
    if (!is_string($jpegContents)) {
        throw new RuntimeException('Could not create the JPEG test fixture.');
    }
    $jpeg = evidenceTemporaryFile($jpegContents, 'aussh-evidence-jpg-');
    $docx = tempnam(sys_get_temp_dir(), 'aussh-evidence-docx-');
    if (!is_string($docx)) {
        throw new RuntimeException('Could not create the DOCX test fixture.');
    }
    $temporaryFiles[] = $docx;
    $archive = new ZipArchive();
    if ($archive->open($docx, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the DOCX test archive.');
    }
    $archive->addFromString(
        '[Content_Types].xml',
        '<?xml version="1.0"?><Types><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>'
    );
    $archive->addFromString('word/document.xml', '<?xml version="1.0"?><document/>');
    $archive->close();

    $newAchievement = [
        'type' => 'Achievement',
        'year' => 'Year 2',
        'title' => '[TEST ONLY] Evidence workflow',
        'detail' => 'Test achievement description.',
        'evidence' => 'Test certificate evidence description.',
        'share_activity' => false,
    ];
    $fields = ['action' => 'create', 'type' => 'achievement', 'payload' => json_encode($newAchievement, JSON_THROW_ON_ERROR)];
    $storageDirectory = (string)getenv('USERPROFILE') . DIRECTORY_SEPARATOR . 'aussh_private' .
        DIRECTORY_SEPARATOR . 'achievement_evidence';
    $storageDirectoryExisted = is_dir($storageDirectory);
    [$status, $response] = evidenceMultipart($base, $sessions['student'], $fields, [
        ['path' => $pdf, 'name' => 'TEST_ONLY_certificate.pdf'],
        ['path' => $jpeg, 'name' => 'TEST_ONLY_photo.jpg'],
        ['path' => $docx, 'name' => 'TEST_ONLY_letter.docx'],
    ]);
    evidenceAssert($status === 201, 'Student uploads PDF, JPG and DOCX evidence in one multipart achievement submission');
    $recordIds[] = (int)$response['id'];
    $recordId = (int)$response['id'];

    [$status, $studentData] = evidenceRequest($base, $sessions['student'], '/api/workflows.php');
    $record = array_values(array_filter(
        $studentData['records'],
        static fn(array $row): bool => (int)$row['id'] === $recordId
    ))[0] ?? null;
    evidenceAssert(
        $status === 200 && $record && count($record['attachments']) === 3 &&
        $record['payload']['evidence'] === $newAchievement['evidence'] &&
        !isset($record['attachments'][0]['storage_key']),
        'Student sees evidence description and safe attachment metadata without storage paths'
    );
    $attachmentIds = array_column($record['attachments'], 'id');
    $metadataQuery = $pdo->prepare(
        'SELECT original_filename, mime_type, file_size, uploader_user_id, storage_key
         FROM portal_achievement_evidence WHERE record_id = ? ORDER BY id'
    );
    $metadataQuery->execute([$recordId]);
    $metadata = $metadataQuery->fetchAll(PDO::FETCH_ASSOC);
    evidenceAssert(
        count($metadata) === 3 &&
        array_column($metadata, 'mime_type') === [
            'application/pdf', 'image/jpeg',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ] &&
        array_unique(array_column($metadata, 'uploader_user_id')) === [$users['student']] &&
        array_reduce($metadata, static fn(bool $valid, array $row): bool =>
            $valid && preg_match('/^[a-f0-9]{64}$/', $row['storage_key']) === 1,
            true
        ),
        'Attachment metadata records MIME, size, uploader and random server-side storage keys'
    );
    $privatePath = realpath($storageDirectory . DIRECTORY_SEPARATOR . $metadata[0]['storage_key']);
    $webRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__));
    evidenceAssert(
        $privatePath !== false && dirname($privatePath) === realpath($storageDirectory) &&
        ($webRoot === false || strncasecmp($privatePath, rtrim($webRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, strlen(rtrim($webRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) !== 0),
        'Uploaded evidence bytes exist outside the public web root'
    );

    [$status, $verifierData] = evidenceRequest($base, $sessions['verifier'], '/api/workflows.php');
    $verifierRecord = array_values(array_filter(
        $verifierData['records'],
        static fn(array $row): bool => (int)$row['id'] === $recordId
    ))[0] ?? null;
    evidenceAssert(
        $status === 200 && $verifierRecord && count($verifierRecord['attachments']) === 3,
        'Authorised verifier can view the submitted evidence and attachment list'
    );
    [$status, $download, $headers] = evidenceDownload($base, $sessions['verifier'], (int)$attachmentIds[0]);
    evidenceAssert(
        $status === 200 && str_starts_with($download, '%PDF-1.4') &&
        count(array_filter($headers, static fn(string $header): bool =>
            stripos($header, 'Content-Disposition: attachment;') === 0
        )) === 1,
        'Verifier can securely download the original PDF evidence'
    );
    [$status, $preview, $headers] = evidenceDownload($base, $sessions['student'], (int)$attachmentIds[0], true);
    evidenceAssert(
        $status === 200 && str_starts_with($preview, '%PDF-1.4') &&
        count(array_filter($headers, static fn(string $header): bool =>
            stripos($header, 'Content-Disposition: inline;') === 0
        )) === 1,
        'Owner can safely preview a PDF through the authenticated inline endpoint'
    );
    [$status, $otherResponse] = evidenceDownload($base, $sessions['other_student'], (int)$attachmentIds[0]);
    evidenceAssert($status === 404 && str_contains($otherResponse, 'Evidence file not found'),
        'Another student cannot access the private evidence');
    [$status] = evidenceDownload($base, null, (int)$attachmentIds[0]);
    evidenceAssert($status === 401, 'Unauthenticated users cannot download evidence');

    [$status, $optionalResponse] = evidenceRequest($base, $sessions['student'], '/api/workflows.php', 'POST', [
        'action' => 'create',
        'type' => 'achievement',
        'payload' => [
            'type' => 'Service',
            'year' => 'Year 1',
            'title' => '[TEST ONLY] Optional evidence',
            'detail' => 'Achievement without file attachment.',
            'evidence' => '',
        ],
    ]);
    $recordIds[] = (int)$optionalResponse['id'];
    evidenceAssert($status === 201, 'Student can submit an achievement without attachments');

    $invalid = evidenceTemporaryFile("<?php echo 'not evidence';", 'aussh-evidence-invalid-');
    [$status] = evidenceMultipart($base, $sessions['student'], $fields, [
        ['path' => $invalid, 'name' => 'TEST_ONLY_malware.pdf'],
    ]);
    evidenceAssert($status === 422, 'Invalid content types are rejected even when renamed with an allowed extension');

    $oversized = evidenceTemporaryFile(str_repeat('x', 10 * 1024 * 1024 + 1), 'aussh-evidence-large-');
    [$status, $oversizeResponse] = evidenceMultipart($base, $sessions['student'], $fields, [
        ['path' => $oversized, 'name' => 'TEST_ONLY_large.pdf'],
    ]);
    evidenceAssert($status === 413 && str_contains($oversizeResponse['message'], '10 MB'),
        'Evidence files larger than 10 MB are rejected');

    $sixFiles = [];
    for ($index = 0; $index < 6; $index++) {
        $sixFiles[] = ['path' => $pdf, 'name' => 'TEST_ONLY_extra_' . $index . '.pdf'];
    }
    [$status, $tooManyResponse] = evidenceMultipart($base, $sessions['student'], $fields, $sixFiles);
    evidenceAssert($status === 422 && str_contains($tooManyResponse['message'], 'five'),
        'More than five attachments are rejected');

    [$status] = evidenceMultipart($base, $sessions['student'], $fields, [
        ['path' => $pdf, 'name' => 'TEST_ONLY_csrf.pdf'],
    ], 'invalid-csrf-token');
    evidenceAssert($status === 419, 'Multipart evidence uploads require a valid CSRF token');

    [$status, $decision] = evidenceRequest($base, $sessions['verifier'], '/api/workflows.php', 'POST', [
        'action' => 'decide',
        'id' => $recordId,
        'status' => 'Needs changes',
        'comment' => 'TEST ONLY: provide a second certificate.',
    ]);
    evidenceAssert($status === 200 && $decision['status'] === 'Needs changes',
        'Verifier can request correction through the existing achievement workflow');

    [$status, $foreignUpload] = evidenceMultipart($base, $sessions['other_student'], [
        'action' => 'add_evidence',
        'id' => (string)$recordId,
        'evidence_description' => 'Unauthorised test upload.',
    ], [['path' => $jpeg, 'name' => 'TEST_ONLY_foreign.jpg']]);
    evidenceAssert($status === 404, 'A student cannot add evidence to another student’s achievement');

    [$status, $resubmission] = evidenceMultipart($base, $sessions['student'], [
        'action' => 'add_evidence',
        'id' => (string)$recordId,
        'evidence_description' => 'TEST ONLY: supplementary certificate.',
    ], [['path' => $jpeg, 'name' => 'TEST_ONLY_additional.jpg']]);
    evidenceAssert($status === 201 && $resubmission['status'] === 'Pending',
        'Student can upload additional evidence after correction and resubmit for verification');
    [$status, $studentData] = evidenceRequest($base, $sessions['student'], '/api/workflows.php');
    $record = array_values(array_filter(
        $studentData['records'],
        static fn(array $row): bool => (int)$row['id'] === $recordId
    ))[0] ?? null;
    evidenceAssert(
        $status === 200 && $record && count($record['attachments']) === 4 &&
        count($record['payload']['evidence_submissions']) === 2 &&
        $record['payload']['review_comment'] === 'TEST ONLY: provide a second certificate.',
        'Resubmission keeps prior files, prior submission history and reviewer feedback'
    );

    $auditQuery = $pdo->prepare(
        "SELECT COUNT(*) FROM portal_audit
         WHERE record_type = 'achievement' AND record_id = ?
           AND action IN ('evidence_uploaded','evidence_resubmitted','evidence_downloaded','evidence_previewed')"
    );
    $auditQuery->execute([$recordId]);
    evidenceAssert((int)$auditQuery->fetchColumn() >= 7,
        'Evidence uploads, correction resubmission, previews and downloads are audited');

    [$status, $unauthorizedAdd] = evidenceMultipart($base, $sessions['student'], [
        'action' => 'add_evidence',
        'id' => (string)$recordId,
        'evidence_description' => 'Evidence should be blocked outside correction.',
    ], [['path' => $pdf, 'name' => 'TEST_ONLY_not_returned.pdf']]);
    evidenceAssert($status === 409, 'Additional uploads are blocked unless the achievement was returned for changes');

    if (count($metadata) > 0) {
        foreach ($metadata as $row) {
            $storagePaths[] = $storageDirectory . DIRECTORY_SEPARATOR . $row['storage_key'];
        }
    }
    $newKeyQuery = $pdo->prepare('SELECT storage_key FROM portal_achievement_evidence WHERE record_id = ?');
    $newKeyQuery->execute([$recordId]);
    foreach ($newKeyQuery->fetchAll(PDO::FETCH_COLUMN) as $key) {
        $storagePaths[] = $storageDirectory . DIRECTORY_SEPARATOR . $key;
    }

    echo PHP_EOL . 'All ' . $testCount . ' achievement evidence integration checks passed.' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'TEST FAILURE: ' . $error->getMessage() . PHP_EOL);
    $failure = true;
} finally {
    if ($server !== null && is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (isset($failure)) {
        $idQuery = $pdo->prepare('SELECT id FROM portal_records WHERE owner_user_id IN (' .
            implode(',', array_fill(0, count($users), '?')) . ') AND record_type = \'achievement\'');
        $idQuery->execute(array_values($users));
        $recordIds = array_merge($recordIds, array_map('intval', $idQuery->fetchAll(PDO::FETCH_COLUMN)));
    }
    if ($recordIds) {
        $recordIds = array_values(array_unique(array_filter(array_map('intval', $recordIds))));
        $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
        $keys = $pdo->prepare(
            "SELECT storage_key FROM portal_achievement_evidence WHERE record_id IN ($placeholders)"
        );
        $keys->execute($recordIds);
        foreach ($keys->fetchAll(PDO::FETCH_COLUMN) as $key) {
            $path = $storageDirectory . DIRECTORY_SEPARATOR . $key;
            if (is_file($path)) {
                unlink($path);
            }
        }
        $pdo->prepare(
            "DELETE FROM portal_audit WHERE record_type = 'achievement' AND record_id IN ($placeholders)"
        )->execute($recordIds);
        $pdo->prepare(
            "DELETE FROM portal_notifications WHERE record_type = 'achievement' AND record_id IN ($placeholders)"
        )->execute($recordIds);
        $pdo->prepare("DELETE FROM portal_records WHERE id IN ($placeholders)")->execute($recordIds);
    }
    $deleteUsers = $pdo->prepare('DELETE FROM users WHERE user_id = ?');
    foreach ($users as $userId) {
        $deleteUsers->execute([$userId]);
    }
    foreach ($temporaryFiles as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    if (!$storageDirectoryExisted && is_dir($storageDirectory)) {
        $remaining = glob($storageDirectory . DIRECTORY_SEPARATOR . '*');
        if (!$remaining) {
            rmdir($storageDirectory);
        }
    }
    if (is_string($serverLog) && is_file($serverLog)) {
        unlink($serverLog);
    }
}
if (isset($failure)) {
    exit(1);
}
