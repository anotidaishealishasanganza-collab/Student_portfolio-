<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/account_management_lib.php';

$user = requireAuthentication();
$pdo = database();

function resultFail(string $message, int $status = 422): never
{
    jsonResponse(['success' => false, 'message' => $message], $status);
}

function resultEnsureSchema(PDO $pdo): void
{
    $migration = dirname(__DIR__) . '/database/results_migration.sql';
    if (!is_file($migration) || !is_readable($migration)) {
        throw new RuntimeException('Academic results migration is unavailable.');
    }
    $contents = file_get_contents($migration);
    if (!is_string($contents)) {
        throw new RuntimeException('Academic results migration could not be read.');
    }
    foreach (explode(';', $contents) as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }
    resultEnsureForeignKeys($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_notifications (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        recipient_user_id VARCHAR(64) NOT NULL,
        title VARCHAR(180) NOT NULL,
        message TEXT NOT NULL,
        record_type VARCHAR(32) NULL,
        record_id BIGINT UNSIGNED NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        dedupe_key VARCHAR(190) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_recipient_read (recipient_user_id, is_read, created_at),
        UNIQUE KEY uq_portal_notifications_dedupe (dedupe_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $column = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_notifications'
           AND COLUMN_NAME = 'dedupe_key'"
    );
    $column->execute();
    if ((int)$column->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE portal_notifications ADD COLUMN dedupe_key VARCHAR(190) NULL');
    }
    $index = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_notifications'
           AND INDEX_NAME = 'uq_portal_notifications_dedupe'"
    );
    $index->execute();
    if ((int)$index->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE portal_notifications ADD UNIQUE KEY uq_portal_notifications_dedupe (dedupe_key)');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_audit (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        actor_user_id VARCHAR(64) NOT NULL,
        action VARCHAR(60) NOT NULL,
        record_type VARCHAR(32) NOT NULL,
        record_id BIGINT UNSIGNED NOT NULL,
        details JSON NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_record (record_type, record_id),
        INDEX idx_actor (actor_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function resultEnsureForeignKeys(PDO $pdo): void
{
    $userTable = $pdo->query(
        "SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'"
    )->fetch(PDO::FETCH_ASSOC);
    $userId = $pdo->query(
        "SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, COLLATION_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'user_id'"
    )->fetch(PDO::FETCH_ASSOC);
    $usersCompatible = $userTable &&
        $userTable['ENGINE'] === 'InnoDB' &&
        $userTable['TABLE_COLLATION'] === 'utf8mb4_unicode_ci' &&
        $userId &&
        $userId['DATA_TYPE'] === 'varchar' &&
        (int)$userId['CHARACTER_MAXIMUM_LENGTH'] === 64 &&
        $userId['COLLATION_NAME'] === 'utf8mb4_unicode_ci';
    if (!$usersCompatible) {
        error_log('AUSSH academic results: user foreign keys were not added because users.user_id is not schema-compatible.');
    }

    $existing = $pdo->query(
        "SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()"
    )->fetchAll(PDO::FETCH_COLUMN);
    $existing = array_fill_keys($existing, true);
    $constraints = [
        ['portal_academic_results', 'fk_result_assignment', 'assignment_id', 'portal_result_assignments', 'id'],
        ['portal_academic_results', 'fk_result_publication_batch', 'publication_batch_id', 'portal_result_publication_batches', 'id'],
        ['portal_result_reviews', 'fk_result_review_result', 'result_id', 'portal_academic_results', 'id'],
        ['portal_result_imports', 'fk_result_import_assignment', 'assignment_id', 'portal_result_assignments', 'id'],
        ['portal_result_import_rows', 'fk_result_import_row_import', 'import_id', 'portal_result_imports', 'id'],
        ['portal_result_import_rows', 'fk_result_import_row_assignment', 'assignment_id', 'portal_result_assignments', 'id'],
        ['portal_result_import_rows', 'fk_result_import_row_result', 'result_id', 'portal_academic_results', 'id'],
    ];
    if ($usersCompatible) {
        $constraints = array_merge($constraints, [
            ['portal_result_settings', 'fk_result_settings_user', 'updated_by', 'users', 'user_id'],
            ['portal_result_assignments', 'fk_result_assignment_lecturer', 'lecturer_user_id', 'users', 'user_id'],
            ['portal_result_assignments', 'fk_result_assignment_hod', 'hod_user_id', 'users', 'user_id'],
            ['portal_result_assignments', 'fk_result_assignment_student', 'student_user_id', 'users', 'user_id'],
            ['portal_result_assignments', 'fk_result_assignment_creator', 'created_by', 'users', 'user_id'],
            ['portal_result_publication_batches', 'fk_result_batch_publisher', 'publisher_user_id', 'users', 'user_id'],
            ['portal_academic_results', 'fk_result_student', 'student_user_id', 'users', 'user_id'],
            ['portal_academic_results', 'fk_result_lecturer', 'lecturer_user_id', 'users', 'user_id'],
            ['portal_academic_results', 'fk_result_hod_reviewer', 'hod_reviewer_id', 'users', 'user_id'],
            ['portal_academic_results', 'fk_result_registry_publisher', 'registry_publisher_id', 'users', 'user_id'],
            ['portal_result_reviews', 'fk_result_review_actor', 'actor_user_id', 'users', 'user_id'],
            ['portal_result_imports', 'fk_result_import_lecturer', 'lecturer_user_id', 'users', 'user_id'],
        ]);
    }
    foreach ($constraints as [$table, $name, $column, $referencedTable, $referencedColumn]) {
        if (isset($existing[$name])) {
            continue;
        }
        $pdo->exec(
            'ALTER TABLE `' . $table . '` ADD CONSTRAINT `' . $name . '` FOREIGN KEY (`' . $column . '`) ' .
            'REFERENCES `' . $referencedTable . '` (`' . $referencedColumn . '`) ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }
}

function resultJson(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function resultAudit(PDO $pdo, string $actor, string $action, string $type, int $id, array $details): void
{
    $statement = $pdo->prepare(
        'INSERT INTO portal_audit(actor_user_id, action, record_type, record_id, details)
         VALUES (?, ?, ?, ?, ?)'
    );
    $statement->execute([$actor, $action, $type, $id, resultJson($details)]);
}

function resultHistory(
    PDO $pdo,
    int $resultId,
    string $actor,
    string $action,
    ?string $previousStatus,
    ?string $newStatus,
    ?string $reason,
    ?array $before,
    ?array $after
): void {
    $statement = $pdo->prepare(
        'INSERT INTO portal_result_reviews
            (result_id, actor_user_id, action, previous_status, new_status, reason, before_values, after_values)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        $resultId, $actor, $action, $previousStatus, $newStatus, $reason,
        $before === null ? null : resultJson($before),
        $after === null ? null : resultJson($after),
    ]);
}

function resultNotify(PDO $pdo, string $recipient, string $title, string $message, int $resultId, string $event): void
{
    $statement = $pdo->prepare(
        'INSERT IGNORE INTO portal_notifications
            (recipient_user_id, title, message, record_type, record_id, dedupe_key)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        $recipient, $title, $message, 'academic_result', $resultId,
        'academic_result:' . $event . ':' . $resultId . ':' . $recipient,
    ]);
}

function resultEmail(PDO $pdo, string $recipientId, string $subject, string $message, int $recordId): void
{
    $statement = $pdo->prepare(
        "SELECT full_name, email FROM users
         WHERE user_id = ? AND status = 'Active' LIMIT 1"
    );
    $statement->execute([$recipientId]);
    $recipient = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$recipient || !filter_var($recipient['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        return;
    }
    try {
        sendWorkflowEmail((string)$recipient['email'], (string)$recipient['full_name'], $subject, $message);
    } catch (Throwable $error) {
        error_log('AUSSH academic results email delivery failed (record ' . $recordId . ').');
    }
}

function resultText(mixed $value, int $maxLength): ?string
{
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }
    $text = trim((string)$value);
    return $text === '' || mb_strlen($text, 'UTF-8') > $maxLength ? null : $text;
}

function resultYear(mixed $value): ?string
{
    if (!is_string($value) || !preg_match('/^(\d{4})\/(\d{4})$/', $value, $match)) {
        return null;
    }
    return (int)$match[2] === (int)$match[1] + 1 ? $value : null;
}

function resultMark(mixed $value, float $maximum): ?float
{
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }
    $value = trim((string)$value);
    if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $value)) {
        return null;
    }
    $mark = (float)$value;
    return is_finite($mark) && $mark >= 0 && $mark <= $maximum ? $mark : null;
}

function resultCsvRows(string $path): array
{
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        resultFail('The uploaded CSV file could not be opened.');
    }
    try {
        $header = fgetcsv($stream, 0, ',', '"', '');
        if (!is_array($header)) {
            resultFail('The CSV file is empty. Download a fresh template and try again.');
        }
        if (isset($header[0]) && is_string($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }
        $expected = ['student id', 'course code', 'coursework mark', 'examination mark'];
        $normalized = array_map(
            static fn($value): string => is_string($value) ? mb_strtolower(trim($value), 'UTF-8') : '',
            $header
        );
        if (count($normalized) !== count($expected) || $normalized !== $expected) {
            resultFail('CSV headers must be exactly: Student ID, Course Code, Coursework Mark, Examination Mark.');
        }
        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $line++;
            if ($cells === [null] || $cells === []) {
                continue;
            }
            if (count($rows) >= 1000) {
                resultFail('The CSV exceeds the 1,000-row import limit. Split the class into smaller uploads.', 413);
            }
            $hasExtraColumns = count($cells) > 4;
            $cells = array_pad(array_slice($cells, 0, 4), 4, null);
            $rows[] = [
                'row_number' => $line,
                'student_university_id' => is_string($cells[0]) ? trim($cells[0]) : '',
                'course_code' => is_string($cells[1]) ? trim($cells[1]) : '',
                'coursework_raw' => $cells[2],
                'examination_raw' => $cells[3],
                'has_extra_columns' => $hasExtraColumns,
            ];
        }
        if (!$rows) {
            resultFail('The CSV contains a header but no student rows.');
        }
        return $rows;
    } finally {
        fclose($stream);
    }
}

function resultGrade(float $total, mixed $scale): ?string
{
    if (!is_array($scale)) {
        return null;
    }
    foreach ($scale as $range) {
        if (
            is_array($range) &&
            isset($range['min'], $range['max'], $range['grade']) &&
            $total >= (float)$range['min'] &&
            $total <= (float)$range['max']
        ) {
            return (string)$range['grade'];
        }
    }
    return null;
}

function resultSnapshot(array $row): array
{
    return [
        'coursework_mark' => (float)$row['coursework_mark'],
        'examination_mark' => (float)$row['examination_mark'],
        'total_mark' => (float)$row['total_mark'],
        'grade' => $row['grade'],
    ];
}

function resultSettings(PDO $pdo): array
{
    $row = $pdo->query('SELECT coursework_max, examination_max, total_max, grade_scale FROM portal_result_settings WHERE id = 1')
        ->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Academic results settings have not been initialized.');
    }
    return [
        'coursework_max' => $row['coursework_max'] === null ? null : (float)$row['coursework_max'],
        'examination_max' => $row['examination_max'] === null ? null : (float)$row['examination_max'],
        'total_max' => (float)$row['total_max'],
        'grade_scale' => $row['grade_scale'] === null ? null : json_decode((string)$row['grade_scale'], true),
        'grading_configured' => $row['grade_scale'] !== null,
    ];
}

resultEnsureSchema($pdo);
ensureAccountCollegeSchema($pdo);
$activeAccount = $pdo->prepare(
    "SELECT user_id, full_name, role, email FROM users
     WHERE user_id = ? AND status = 'Active' LIMIT 1"
);
$activeAccount->execute([$user['user_id']]);
$account = $activeAccount->fetch(PDO::FETCH_ASSOC);
if (!$account || $account['role'] !== $user['role']) {
    resultFail('Your account is no longer authorised. Sign in again.', 403);
}
$user = array_merge($user, $account);
$settings = resultSettings($pdo);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET' && ($_GET['action'] ?? '') === 'download_template') {
    if ($user['role'] !== 'lecturer') {
        resultFail('Only lecturers can download a class results template.', 403);
    }
    $assignmentId = filter_var($_GET['assignment_id'] ?? null, FILTER_VALIDATE_INT);
    if ($assignmentId === false || $assignmentId === null || $assignmentId < 1) {
        resultFail('Select an assigned course before downloading its class template.');
    }
    $anchorQuery = $pdo->prepare(
        'SELECT * FROM portal_result_assignments
         WHERE id = ? AND lecturer_user_id = ? AND is_active = 1 LIMIT 1'
    );
    $anchorQuery->execute([$assignmentId, $user['user_id']]);
    $anchor = $anchorQuery->fetch(PDO::FETCH_ASSOC);
    if (!$anchor) {
        resultFail('This course allocation is not assigned to you.', 403);
    }
    $rosterQuery = $pdo->prepare(
        "SELECT a.student_university_id, a.course_code
         FROM portal_result_assignments a
         JOIN users s ON s.user_id = a.student_user_id AND s.role = 'student' AND s.status = 'Active'
         WHERE a.lecturer_user_id = ? AND a.department_code = ? AND a.course_code = ?
           AND a.academic_year = ? AND a.semester = ? AND a.is_active = 1
         ORDER BY a.student_university_id"
    );
    $rosterQuery->execute([
        $user['user_id'], $anchor['department_code'], $anchor['course_code'],
        $anchor['academic_year'], $anchor['semester'],
    ]);
    $filenameParts = array_map(
        static fn(string $part): string => preg_replace('/[^A-Za-z0-9._-]+/', '_', $part),
        [$anchor['course_code'], $anchor['academic_year'], $anchor['semester']]
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="AUSSH_results_' . implode('_', $filenameParts) . '.csv"');
    header('Cache-Control: no-store');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Could not generate the class results template.');
    }
    fputcsv($output, ['Student ID', 'Course Code', 'Coursework Mark', 'Examination Mark'], ',', '"', '');
    foreach ($rosterQuery->fetchAll(PDO::FETCH_ASSOC) as $student) {
        fputcsv($output, [$student['student_university_id'], $student['course_code'], '', ''], ',', '"', '');
    }
    fclose($output);
    exit;
}

if ($method === 'GET') {
    $baseSelect = "SELECT r.*,
                          (SELECT ir.import_id FROM portal_result_import_rows ir
                           WHERE ir.result_id = r.id LIMIT 1) AS import_id,
                          s.full_name AS student_name, l.full_name AS lecturer_name,
                          h.full_name AS hod_name, b.published_at AS batch_published_at,
                          COALESCE(sp.college, '') AS student_college
                   FROM portal_academic_results r
                   LEFT JOIN users s ON s.user_id = r.student_user_id
                   LEFT JOIN portal_account_profiles sp ON sp.user_id = r.student_user_id
                   LEFT JOIN users l ON l.user_id = r.lecturer_user_id
                   LEFT JOIN users h ON h.user_id = r.hod_reviewer_id
                   LEFT JOIN portal_result_publication_batches b ON b.id = r.publication_batch_id";
    $currentProfile = $pdo->prepare(
        'SELECT COALESCE(college, \'\') AS college, programme, study_year
         FROM portal_account_profiles WHERE user_id = ? LIMIT 1'
    );
    $currentProfile->execute([$user['user_id']]);
    $response = [
        'success' => true,
        'settings' => $settings,
        'current_user' => $currentProfile->fetch(PDO::FETCH_ASSOC) ?: [
            'college' => '',
            'programme' => '',
            'study_year' => '',
        ],
    ];
    if ($user['role'] === 'student') {
        $query = $pdo->prepare($baseSelect . "
            WHERE r.student_user_id = ? AND r.workflow_status = 'Published'
            ORDER BY r.academic_year DESC, r.semester, r.course_code");
        $query->execute([$user['user_id']]);
        $response['results'] = $query->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($user['role'] === 'lecturer') {
        $query = $pdo->prepare($baseSelect . ' WHERE r.lecturer_user_id = ? ORDER BY r.updated_at DESC');
        $query->execute([$user['user_id']]);
        $response['results'] = $query->fetchAll(PDO::FETCH_ASSOC);
        $assignments = $pdo->prepare(
            "SELECT a.id, a.student_user_id, a.student_university_id, s.full_name AS student_name,
                    COALESCE(p.college, '') AS student_college,
                    a.department_code, a.course_code, a.course_name, a.academic_year, a.semester
             FROM portal_result_assignments a
             JOIN users s ON s.user_id = a.student_user_id AND s.role = 'student' AND s.status = 'Active'
             LEFT JOIN portal_account_profiles p ON p.user_id = a.student_user_id
             WHERE a.lecturer_user_id = ? AND a.is_active = 1
             ORDER BY a.academic_year DESC, a.semester, s.full_name, a.course_code"
        );
        $assignments->execute([$user['user_id']]);
        $response['assignments'] = $assignments->fetchAll(PDO::FETCH_ASSOC);
        $imports = $pdo->prepare(
            'SELECT id, import_token, assignment_id, course_code, academic_year, semester, source_filename,
                    import_status, total_rows, valid_rows, invalid_rows, created_at, updated_at
             FROM portal_result_imports
             WHERE lecturer_user_id = ? ORDER BY created_at DESC LIMIT 25'
        );
        $imports->execute([$user['user_id']]);
        $response['imports'] = $imports->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($user['role'] === 'hod') {
        $query = $pdo->prepare(
            $baseSelect . " JOIN portal_result_assignments a ON a.id = r.assignment_id
             WHERE a.hod_user_id = ? ORDER BY r.updated_at DESC"
        );
        $query->execute([$user['user_id']]);
        $response['results'] = $query->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($user['role'] === 'registry') {
        $query = $pdo->query($baseSelect . ' ORDER BY r.updated_at DESC LIMIT 2000');
        $response['results'] = $query->fetchAll(PDO::FETCH_ASSOC);
        $response['batches'] = $pdo->query(
            "SELECT b.*, u.full_name AS publisher_name
             FROM portal_result_publication_batches b
             LEFT JOIN users u ON u.user_id = b.publisher_user_id
             ORDER BY b.published_at DESC LIMIT 500"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach (['student' => 'students', 'lecturer' => 'lecturers', 'hod' => 'hods'] as $role => $key) {
            $statement = $pdo->prepare(
                "SELECT u.user_id, u.full_name, COALESCE(p.college, '') AS college
                 FROM users u LEFT JOIN portal_account_profiles p ON p.user_id = u.user_id
                 WHERE u.role = ? AND u.status = 'Active' ORDER BY u.full_name"
            );
            $statement->execute([$role]);
            $response[$key] = $statement->fetchAll(PDO::FETCH_ASSOC);
        }
    } elseif ($user['role'] === 'admin') {
        $counts = $pdo->query(
            'SELECT workflow_status, COUNT(*) AS result_count FROM portal_academic_results GROUP BY workflow_status'
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $response['counts'] = [
            'pending' => (int)($counts['Submitted'] ?? 0),
            'approved' => (int)($counts['Approved'] ?? 0),
            'published' => (int)($counts['Published'] ?? 0),
            'returned' => (int)($counts['Returned'] ?? 0),
            'draft' => (int)($counts['Draft'] ?? 0),
        ];
        $response['batches'] = $pdo->query(
            "SELECT b.*, u.full_name AS publisher_name
             FROM portal_result_publication_batches b
             LEFT JOIN users u ON u.user_id = b.publisher_user_id
             ORDER BY b.published_at DESC LIMIT 500"
        )->fetchAll(PDO::FETCH_ASSOC);
        $response['audit'] = $pdo->query(
            "SELECT id, actor_user_id, action, record_type, record_id, details, created_at
             FROM portal_audit WHERE record_type IN ('academic_result', 'academic_result_batch',
                 'academic_result_assignment', 'academic_result_settings', 'academic_result_import')
             ORDER BY created_at DESC LIMIT 500"
        )->fetchAll(PDO::FETCH_ASSOC);
    } else {
        resultFail('You are not authorised to view academic results.', 403);
    }
    jsonResponse($response);
}

if ($method !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}
verifyCsrf();
$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
$input = str_starts_with($contentType, 'multipart/form-data')
    ? $_POST
    : json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    jsonResponse(['success' => false, 'message' => 'Invalid request.'], 400);
}
$action = (string)($input['action'] ?? '');

if ($action === 'save_settings') {
    if ($user['role'] !== 'registry') {
        resultFail('Only Registry staff can configure academic marking rules.', 403);
    }
    $courseworkMax = resultMark($input['coursework_max'] ?? null, 100);
    $examinationMax = resultMark($input['examination_max'] ?? null, 100);
    if (
        $courseworkMax === null || $examinationMax === null ||
        $courseworkMax <= 0 || $examinationMax <= 0 ||
        $courseworkMax + $examinationMax > 100
    ) {
        resultFail('Enter positive coursework and examination maxima whose combined total is at most 100.');
    }
    $scaleInput = trim((string)($input['grade_scale'] ?? ''));
    $scale = null;
    if ($scaleInput !== '') {
        $scale = json_decode($scaleInput, true);
        if (!is_array($scale) || !$scale) {
            resultFail('The optional grading scale must be a non-empty JSON array of min, max and grade entries.');
        }
        $ranges = [];
        foreach ($scale as $range) {
            if (
                !is_array($range) ||
                !isset($range['min'], $range['max']) ||
                !is_string($range['grade'] ?? null) ||
                trim($range['grade']) === '' || mb_strlen($range['grade'], 'UTF-8') > 12
            ) {
                resultFail('Each grade range must contain numeric min/max values and a grade label.');
            }
            $min = resultMark($range['min'], $courseworkMax + $examinationMax);
            $max = resultMark($range['max'], $courseworkMax + $examinationMax);
            if ($min === null || $max === null || $min > $max) {
                resultFail('Grade ranges must be within the configured maximum total and have min no greater than max.');
            }
            foreach ($ranges as [$priorMin, $priorMax]) {
                if ($min <= $priorMax && $max >= $priorMin) {
                    resultFail('Grade ranges cannot overlap.');
                }
            }
            $ranges[] = [$min, $max];
        }
    }
    $currentSettings = resultSettings($pdo);
    $resultCount = (int)$pdo->query('SELECT COUNT(*) FROM portal_academic_results')->fetchColumn();
    if ($resultCount > 0 && (
        $currentSettings['coursework_max'] !== $courseworkMax ||
        $currentSettings['examination_max'] !== $examinationMax ||
        $currentSettings['grade_scale'] !== $scale
    )) {
        resultFail('Marking rules cannot be changed after results have been entered. Configure approved rules before the first result.', 409);
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE portal_result_settings
             SET coursework_max = ?, examination_max = ?, total_max = 100, grade_scale = ?, updated_by = ?
             WHERE id = 1'
        )->execute([
            $courseworkMax, $examinationMax,
            $scale === null ? null : resultJson($scale),
            $user['user_id'],
        ]);
        resultAudit($pdo, $user['user_id'], 'marking_rules_updated', 'academic_result_settings', 1, [
            'coursework_max' => $courseworkMax,
            'examination_max' => $examinationMax,
            'grade_scale' => $scale,
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    jsonResponse(['success' => true, 'settings' => resultSettings($pdo)]);
}

if ($action === 'create_assignment') {
    if ($user['role'] !== 'registry') {
        resultFail('Only Registry staff can create academic course allocations.', 403);
    }
    $lecturerId = resultText($input['lecturer_user_id'] ?? null, 64);
    $hodId = resultText($input['hod_user_id'] ?? null, 64);
    $studentId = resultText($input['student_user_id'] ?? null, 64);
    $department = resultText($input['department_code'] ?? null, 64);
    $courseCode = resultText($input['course_code'] ?? null, 40);
    $courseName = resultText($input['course_name'] ?? null, 180);
    $academicYear = resultYear($input['academic_year'] ?? null);
    $semester = resultText($input['semester'] ?? null, 32);
    if (
        $lecturerId === null || $hodId === null || $studentId === null ||
        $department === null || $courseCode === null || $courseName === null ||
        $academicYear === null || $semester === null
    ) {
        resultFail('Complete all allocation fields and use an academic year such as 2026/2027.');
    }
    $roles = [];
    foreach ([$lecturerId => 'lecturer', $hodId => 'hod', $studentId => 'student'] as $id => $role) {
        $lookup = $pdo->prepare(
            "SELECT user_id FROM users WHERE user_id = ? AND role = ? AND status = 'Active' LIMIT 1"
        );
        $lookup->execute([$id, $role]);
        if (!$lookup->fetchColumn()) {
            resultFail('Each allocation must reference an active student, lecturer and HOD account.');
        }
        $roles[$role] = $id;
    }
    $existingAllocation = $pdo->prepare(
        'SELECT id FROM portal_result_assignments
         WHERE student_user_id = ? AND course_code = ? AND academic_year = ? AND semester = ?
           AND is_active = 1 LIMIT 1'
    );
    $existingAllocation->execute([$studentId, $courseCode, $academicYear, $semester]);
    if ($existingAllocation->fetchColumn()) {
        resultFail('This student already has an active allocation for the course and semester.', 409);
    }
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'INSERT INTO portal_result_assignments
                (lecturer_user_id, hod_user_id, student_user_id, student_university_id,
                 department_code, course_code, course_name, academic_year, semester, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $roles['lecturer'], $roles['hod'], $roles['student'], $roles['student'],
            $department, $courseCode, $courseName, $academicYear, $semester, $user['user_id'],
        ]);
        $id = (int)$pdo->lastInsertId();
        resultAudit($pdo, $user['user_id'], 'course_allocation_created', 'academic_result_assignment', $id, [
            'lecturer_user_id' => $lecturerId,
            'hod_user_id' => $hodId,
            'student_user_id' => $studentId,
            'department_code' => $department,
            'course_code' => $courseCode,
            'academic_year' => $academicYear,
            'semester' => $semester,
        ]);
        $pdo->commit();
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((string)$error->getCode() === '23000') {
            resultFail('This course allocation already exists.', 409);
        }
        throw $error;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    jsonResponse(['success' => true, 'assignment_id' => $id], 201);
}

if ($action === 'save_draft') {
    if ($user['role'] !== 'lecturer') {
        resultFail('Only lecturers can enter or edit result drafts.', 403);
    }
    if ($settings['coursework_max'] === null || $settings['examination_max'] === null) {
        resultFail('Registry must configure the official assessment maxima before results can be entered.', 409);
    }
    $assignmentId = filter_var($input['assignment_id'] ?? null, FILTER_VALIDATE_INT);
    $coursework = resultMark($input['coursework_mark'] ?? null, $settings['coursework_max']);
    $examination = resultMark($input['examination_mark'] ?? null, $settings['examination_max']);
    if ($assignmentId === false || $assignmentId === null || $assignmentId < 1 || $coursework === null || $examination === null) {
        resultFail('Select an assigned course and enter marks within the configured component maxima.');
    }
    $total = round($coursework + $examination, 2);
    if ($total > $settings['total_max']) {
        resultFail('The total mark cannot exceed 100.');
    }
    $assignmentQuery = $pdo->prepare(
        "SELECT a.* FROM portal_result_assignments a
         JOIN users s ON s.user_id = a.student_user_id AND s.role = 'student' AND s.status = 'Active'
         WHERE a.id = ? AND a.lecturer_user_id = ? AND a.is_active = 1 LIMIT 1"
    );
    $assignmentQuery->execute([$assignmentId, $user['user_id']]);
    $assignment = $assignmentQuery->fetch(PDO::FETCH_ASSOC);
    if (!$assignment) {
        resultFail('This course allocation is not assigned to you.', 403);
    }
    $resultId = null;
    if (array_key_exists('result_id', $input) && $input['result_id'] !== null) {
        $resultId = filter_var($input['result_id'], FILTER_VALIDATE_INT);
        if ($resultId === false || $resultId === null || $resultId < 1) {
            resultFail('Invalid result draft identifier.');
        }
    }
    $grade = resultGrade($total, $settings['grade_scale']);
    $pdo->beginTransaction();
    try {
        if ($resultId === null) {
            $statement = $pdo->prepare(
                "INSERT INTO portal_academic_results
                    (assignment_id, student_user_id, student_university_id, lecturer_user_id, course_code,
                     course_name, academic_year, semester, coursework_mark, examination_mark, total_mark, grade)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $statement->execute([
                $assignmentId, $assignment['student_user_id'], $assignment['student_university_id'],
                $user['user_id'], $assignment['course_code'], $assignment['course_name'],
                $assignment['academic_year'], $assignment['semester'], $coursework, $examination, $total, $grade,
            ]);
            $resultId = (int)$pdo->lastInsertId();
            resultHistory($pdo, $resultId, $user['user_id'], 'draft_saved', null, 'Draft', null, null, [
                'coursework_mark' => $coursework, 'examination_mark' => $examination,
                'total_mark' => $total, 'grade' => $grade,
            ]);
            resultAudit($pdo, $user['user_id'], 'draft_saved', 'academic_result', $resultId, [
                'status' => 'Draft', 'total_mark' => $total,
            ]);
        } else {
            $existingQuery = $pdo->prepare(
                "SELECT * FROM portal_academic_results WHERE id = ? AND lecturer_user_id = ? FOR UPDATE"
            );
            $existingQuery->execute([$resultId, $user['user_id']]);
            $existing = $existingQuery->fetch(PDO::FETCH_ASSOC);
            if (!$existing || !in_array($existing['workflow_status'], ['Draft', 'Returned'], true) ||
                (int)$existing['assignment_id'] !== $assignmentId) {
                $pdo->rollBack();
                resultFail('Only your draft or returned result can be edited.', 409);
            }
            $before = resultSnapshot($existing);
            $statement = $pdo->prepare(
                "UPDATE portal_academic_results
                 SET coursework_mark = ?, examination_mark = ?, total_mark = ?, grade = ?
                 WHERE id = ?"
            );
            $statement->execute([$coursework, $examination, $total, $grade, $resultId]);
            $status = (string)$existing['workflow_status'];
            resultHistory($pdo, $resultId, $user['user_id'], 'draft_updated', $status, $status, null, $before, [
                'coursework_mark' => $coursework, 'examination_mark' => $examination,
                'total_mark' => $total, 'grade' => $grade,
            ]);
            resultAudit($pdo, $user['user_id'], 'draft_updated', 'academic_result', $resultId, [
                'status' => $status, 'previous' => $before, 'total_mark' => $total,
            ]);
        }
        $pdo->commit();
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((string)$error->getCode() === '23000') {
            resultFail('A result already exists for this student, course, academic year and semester.', 409);
        }
        throw $error;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    jsonResponse(['success' => true, 'result_id' => $resultId]);
}

if ($action === 'preview_import') {
    if ($user['role'] !== 'lecturer') {
        resultFail('Only lecturers can upload class results.', 403);
    }
    if ($settings['coursework_max'] === null || $settings['examination_max'] === null) {
        resultFail('Registry must configure the official assessment maxima before results can be imported.', 409);
    }
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['name'], $file['size'])) {
        resultFail('Choose a CSV results file to preview.');
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        $status = in_array((int)$file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 422;
        resultFail('The results file could not be uploaded. Check its size and try again.', $status);
    }
    if ((int)$file['size'] < 1 || (int)$file['size'] > 5 * 1024 * 1024) {
        resultFail('CSV files must be no larger than 5 MB.', 413);
    }
    $sourceFilename = basename((string)$file['name']);
    $extension = strtolower((string)pathinfo($sourceFilename, PATHINFO_EXTENSION));
    if ($extension === 'xlsx') {
        resultFail('XLSX uploads are not enabled because PhpSpreadsheet is not installed. Save the workbook as CSV UTF-8 and upload the CSV file.', 415);
    }
    if ($extension !== 'csv') {
        resultFail('Upload a .csv file. Excel workbooks must first be saved as CSV UTF-8.', 415);
    }
    if (!is_uploaded_file((string)$file['tmp_name'])) {
        resultFail('The upload is invalid. Select the CSV file again.', 422);
    }
    $assignmentId = filter_var($input['assignment_id'] ?? null, FILTER_VALIDATE_INT);
    if ($assignmentId === false || $assignmentId === null || $assignmentId < 1) {
        resultFail('Select an assigned course, academic year and semester before uploading.');
    }
    $anchorQuery = $pdo->prepare(
        'SELECT * FROM portal_result_assignments
         WHERE id = ? AND lecturer_user_id = ? AND is_active = 1 LIMIT 1'
    );
    $anchorQuery->execute([$assignmentId, $user['user_id']]);
    $anchor = $anchorQuery->fetch(PDO::FETCH_ASSOC);
    if (!$anchor) {
        resultFail('This course allocation is not assigned to you.', 403);
    }
    $assignmentsQuery = $pdo->prepare(
        "SELECT a.id, a.student_user_id, a.student_university_id, a.course_code
         FROM portal_result_assignments a
         JOIN users s ON s.user_id = a.student_user_id AND s.role = 'student' AND s.status = 'Active'
         WHERE a.lecturer_user_id = ? AND a.department_code = ? AND a.course_code = ?
           AND a.academic_year = ? AND a.semester = ? AND a.is_active = 1
         ORDER BY a.student_university_id"
    );
    $assignmentsQuery->execute([
        $user['user_id'], $anchor['department_code'], $anchor['course_code'],
        $anchor['academic_year'], $anchor['semester'],
    ]);
    $roster = [];
    foreach ($assignmentsQuery->fetchAll(PDO::FETCH_ASSOC) as $assigned) {
        $key = mb_strtoupper((string)$assigned['student_university_id'], 'UTF-8');
        if (isset($roster[$key])) {
            resultFail('This course allocation has duplicate class roster entries. Contact Registry before importing.', 409);
        }
        $roster[$key] = $assigned;
    }
    if (!$roster) {
        resultFail('The selected course allocation has no active students.', 409);
    }
    $rows = resultCsvRows((string)$file['tmp_name']);
    $candidateIds = [];
    foreach ($rows as $row) {
        if ($row['student_university_id'] !== '') {
            $candidateIds[mb_strtoupper($row['student_university_id'], 'UTF-8')] = $row['student_university_id'];
        }
    }
    $accounts = [];
    if ($candidateIds) {
        $marks = implode(',', array_fill(0, count($candidateIds), '?'));
        $accountQuery = $pdo->prepare(
            "SELECT user_id FROM users WHERE role = 'student' AND status = 'Active' AND user_id IN ($marks)"
        );
        $accountQuery->execute(array_values($candidateIds));
        foreach ($accountQuery->fetchAll(PDO::FETCH_COLUMN) as $studentId) {
            $accounts[mb_strtoupper((string)$studentId, 'UTF-8')] = (string)$studentId;
        }
    }
    $assignedKeys = [];
    $seenRows = [];
    $normalizedRows = [];
    foreach ($rows as $index => $row) {
        $studentKey = mb_strtoupper($row['student_university_id'], 'UTF-8');
        $errors = [];
        $assigned = $roster[$studentKey] ?? null;
        if ($row['student_university_id'] === '' || !isset($accounts[$studentKey])) {
            $errors[] = 'Student ID does not match an active student account.';
        } elseif (!$assigned) {
            $errors[] = 'Student is not assigned to the selected lecturer, course, academic year and semester.';
        } else {
            $assignedKeys[$studentKey] = true;
        }
        $courseCode = $row['course_code'];
        if ($courseCode === '' || strcasecmp($courseCode, (string)$anchor['course_code']) !== 0) {
            $errors[] = 'Course Code must match the selected institution course allocation (' . $anchor['course_code'] . ').';
        }
        if ($row['has_extra_columns']) {
            $errors[] = 'This row contains extra columns. Use the four columns in the downloaded template.';
        }
        $coursework = resultMark($row['coursework_raw'], (float)$settings['coursework_max']);
        $examination = resultMark($row['examination_raw'], (float)$settings['examination_max']);
        if ($coursework === null) {
            $errors[] = 'Coursework Mark must be a number from 0 to ' . $settings['coursework_max'] . '.';
        }
        if ($examination === null) {
            $errors[] = 'Examination Mark must be a number from 0 to ' . $settings['examination_max'] . '.';
        }
        $total = $coursework !== null && $examination !== null
            ? round($coursework + $examination, 2)
            : null;
        if ($total !== null && $total > (float)$settings['total_max']) {
            $errors[] = 'Combined mark exceeds the maximum total of ' . $settings['total_max'] . '.';
        }
        if ($studentKey !== '') {
            $seenRows[$studentKey][] = $index;
        }
        $normalizedRows[] = [
            'row_number' => $row['row_number'],
            'row_type' => 'data',
            'assignment_id' => $assigned ? (int)$assigned['id'] : null,
            'student_user_id' => $assigned['student_user_id'] ?? ($accounts[$studentKey] ?? null),
            'student_university_id' => $row['student_university_id'] ?: null,
            'course_code' => $courseCode ?: (string)$anchor['course_code'],
            'coursework_mark' => $coursework,
            'examination_mark' => $examination,
            'total_mark' => $total,
            'validation_errors' => $errors,
            'row_status' => $errors ? 'Invalid' : 'Valid',
        ];
    }
    foreach ($seenRows as $indexes) {
        if (count($indexes) > 1) {
            foreach ($indexes as $index) {
                $normalizedRows[$index]['validation_errors'][] = 'Student appears more than once in this upload for the selected course and semester.';
                $normalizedRows[$index]['row_status'] = 'Invalid';
            }
        }
    }
    $assignedUserIds = array_column($roster, 'student_user_id');
    $existingResults = [];
    if ($assignedUserIds) {
        $marks = implode(',', array_fill(0, count($assignedUserIds), '?'));
        $existingQuery = $pdo->prepare(
            "SELECT student_user_id FROM portal_academic_results
             WHERE course_code = ? AND academic_year = ? AND semester = ?
               AND student_user_id IN ($marks)"
        );
        $existingQuery->execute(array_merge(
            [$anchor['course_code'], $anchor['academic_year'], $anchor['semester']],
            $assignedUserIds
        ));
        foreach ($existingQuery->fetchAll(PDO::FETCH_COLUMN) as $studentId) {
            $existingResults[mb_strtoupper((string)$studentId, 'UTF-8')] = true;
        }
    }
    foreach ($normalizedRows as &$row) {
        $studentKey = mb_strtoupper((string)($row['student_university_id'] ?? ''), 'UTF-8');
        if (isset($existingResults[$studentKey])) {
            $row['validation_errors'][] = 'A result already exists for this student, course, academic year and semester.';
            $row['row_status'] = 'Invalid';
        }
    }
    unset($row);
    $nextRowNumber = max(array_column($rows, 'row_number')) + 1;
    foreach ($roster as $studentKey => $assigned) {
        if (!isset($assignedKeys[$studentKey])) {
            $normalizedRows[] = [
                'row_number' => $nextRowNumber++,
                'row_type' => 'missing',
                'assignment_id' => (int)$assigned['id'],
                'student_user_id' => $assigned['student_user_id'],
                'student_university_id' => $assigned['student_university_id'],
                'course_code' => (string)$anchor['course_code'],
                'coursework_mark' => null,
                'examination_mark' => null,
                'total_mark' => null,
                'validation_errors' => ['Student is missing from this class upload. Include every student in the downloaded roster template.'],
                'row_status' => 'Invalid',
            ];
        }
    }
    $validRows = count(array_filter($normalizedRows, static fn(array $row): bool => $row['row_status'] === 'Valid'));
    $invalidRows = count($normalizedRows) - $validRows;
    $fileHash = hash_file('sha256', (string)$file['tmp_name']);
    if (!is_string($fileHash)) {
        resultFail('The uploaded CSV could not be fingerprinted for the import audit.');
    }
    $importToken = bin2hex(random_bytes(32));
    $pdo->beginTransaction();
    try {
        $insertImport = $pdo->prepare(
            'INSERT INTO portal_result_imports
                (import_token, lecturer_user_id, assignment_id, course_code, academic_year, semester,
                 source_filename, file_sha256, import_status, total_rows, valid_rows, invalid_rows)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertImport->execute([
            $importToken, $user['user_id'], $assignmentId, $anchor['course_code'],
            $anchor['academic_year'], $anchor['semester'],
            mb_substr($sourceFilename, 0, 255, 'UTF-8'), $fileHash, 'Previewed',
            count($normalizedRows), $validRows, $invalidRows,
        ]);
        $importId = (int)$pdo->lastInsertId();
        $insertRow = $pdo->prepare(
            'INSERT INTO portal_result_import_rows
                (import_id, csv_row_number, row_type, assignment_id, student_user_id, student_university_id,
                 course_code, coursework_mark, examination_mark, total_mark, validation_errors, row_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($normalizedRows as $row) {
            $insertRow->execute([
                $importId, $row['row_number'], $row['row_type'], $row['assignment_id'],
                $row['student_user_id'], $row['student_university_id'], $row['course_code'],
                $row['coursework_mark'], $row['examination_mark'], $row['total_mark'],
                $row['validation_errors'] ? resultJson($row['validation_errors']) : null,
                $row['row_status'],
            ]);
        }
        resultAudit($pdo, $user['user_id'], 'bulk_import_previewed', 'academic_result_import', $importId, [
            'filename' => mb_substr($sourceFilename, 0, 255, 'UTF-8'),
            'sha256' => $fileHash,
            'course_code' => $anchor['course_code'],
            'academic_year' => $anchor['academic_year'],
            'semester' => $anchor['semester'],
            'total_rows' => count($normalizedRows),
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    jsonResponse([
        'success' => true,
        'import' => [
            'id' => $importId,
            'import_token' => $importToken,
            'source_filename' => $sourceFilename,
            'course_code' => $anchor['course_code'],
            'academic_year' => $anchor['academic_year'],
            'semester' => $anchor['semester'],
            'import_status' => 'Previewed',
            'total_rows' => count($normalizedRows),
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
        ],
        'rows' => $normalizedRows,
        'can_import_drafts' => $invalidRows === 0,
    ], 201);
}

if ($action === 'get_import') {
    if ($user['role'] !== 'lecturer') {
        resultFail('Only lecturers can view their class import previews.', 403);
    }
    $token = $input['import_token'] ?? null;
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        resultFail('Invalid import reference.');
    }
    $query = $pdo->prepare(
        'SELECT * FROM portal_result_imports WHERE import_token = ? AND lecturer_user_id = ? LIMIT 1'
    );
    $query->execute([$token, $user['user_id']]);
    $job = $query->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        resultFail('Import not found.', 404);
    }
    $rows = $pdo->prepare(
        'SELECT csv_row_number, row_type, student_university_id, course_code, coursework_mark,
                examination_mark, total_mark, validation_errors, row_status, result_id
         FROM portal_result_import_rows WHERE import_id = ? ORDER BY csv_row_number'
    );
    $rows->execute([$job['id']]);
    $previewRows = $rows->fetchAll(PDO::FETCH_ASSOC);
    foreach ($previewRows as &$row) {
        $row['row_number'] = (int)$row['csv_row_number'];
        unset($row['csv_row_number']);
        $row['validation_errors'] = $row['validation_errors'] === null
            ? []
            : json_decode((string)$row['validation_errors'], true);
    }
    unset($row);
    jsonResponse(['success' => true, 'import' => $job, 'rows' => $previewRows]);
}

if ($action === 'import_drafts') {
    if ($user['role'] !== 'lecturer') {
        resultFail('Only lecturers can save class import results.', 403);
    }
    $token = $input['import_token'] ?? null;
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        resultFail('Invalid import reference.');
    }
    $pdo->beginTransaction();
    try {
        $jobQuery = $pdo->prepare(
            'SELECT * FROM portal_result_imports WHERE import_token = ? AND lecturer_user_id = ? FOR UPDATE'
        );
        $jobQuery->execute([$token, $user['user_id']]);
        $job = $jobQuery->fetch(PDO::FETCH_ASSOC);
        if (!$job || $job['import_status'] !== 'Previewed') {
            $pdo->rollBack();
            resultFail('Only a pending, validated preview can be imported as drafts.', 409);
        }
        if ((int)$job['invalid_rows'] !== 0 || (int)$job['valid_rows'] < 1) {
            $pdo->rollBack();
            resultFail('Resolve every invalid row and upload the corrected CSV before importing.', 422);
        }
        $rowQuery = $pdo->prepare(
            "SELECT * FROM portal_result_import_rows WHERE import_id = ? AND row_status = 'Valid' ORDER BY csv_row_number FOR UPDATE"
        );
        $rowQuery->execute([$job['id']]);
        $rows = $rowQuery->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== (int)$job['valid_rows']) {
            $pdo->rollBack();
            resultFail('The preview rows changed. Upload the corrected CSV again.', 409);
        }
        $assignmentIds = array_unique(array_column($rows, 'assignment_id'));
        foreach ($rows as $row) {
            $existing = $pdo->prepare(
                'SELECT id FROM portal_academic_results
                 WHERE student_user_id = ? AND course_code = ? AND academic_year = ? AND semester = ? LIMIT 1 FOR UPDATE'
            );
            $existing->execute([
                $row['student_user_id'], $job['course_code'], $job['academic_year'], $job['semester'],
            ]);
            if ($existing->fetchColumn()) {
                $pdo->rollBack();
                resultFail(
                    'A result was created after this preview for student ' . $row['student_university_id'] .
                    '. Upload the corrected CSV again.',
                    409
                );
            }
        }
        $grade = $pdo->prepare(
            'SELECT grade_scale FROM portal_result_settings WHERE id = 1'
        );
        $grade->execute();
        $gradeScaleRaw = $grade->fetchColumn();
        $gradeScale = $gradeScaleRaw === false || $gradeScaleRaw === null
            ? null
            : json_decode((string)$gradeScaleRaw, true);
        $insertResult = $pdo->prepare(
            "INSERT INTO portal_academic_results
                (assignment_id, student_user_id, student_university_id, lecturer_user_id, course_code,
                 course_name, academic_year, semester, coursework_mark, examination_mark, total_mark, grade)
             SELECT a.id, a.student_user_id, a.student_university_id, a.lecturer_user_id, a.course_code,
                    a.course_name, a.academic_year, a.semester, ?, ?, ?, ?
             FROM portal_result_assignments a
             WHERE a.id = ? AND a.lecturer_user_id = ? AND a.is_active = 1"
        );
        $updateRow = $pdo->prepare(
            "UPDATE portal_result_import_rows SET row_status = 'Draft', result_id = ?
             WHERE id = ? AND row_status = 'Valid'"
        );
        $resultIds = [];
        foreach ($rows as $row) {
            $total = (float)$row['total_mark'];
            $insertResult->execute([
                $row['coursework_mark'], $row['examination_mark'], $total,
                resultGrade($total, $gradeScale), $row['assignment_id'], $user['user_id'],
            ]);
            if ($insertResult->rowCount() !== 1) {
                throw new RuntimeException('An assigned student/course was no longer active during import.');
            }
            $resultId = (int)$pdo->lastInsertId();
            $resultIds[] = $resultId;
            $updateRow->execute([$resultId, $row['id']]);
            if ($updateRow->rowCount() !== 1) {
                throw new RuntimeException('A bulk import preview row changed during import.');
            }
            resultHistory($pdo, $resultId, $user['user_id'], 'bulk_draft_saved', null, 'Draft', null, null, [
                'import_id' => (int)$job['id'],
                'coursework_mark' => (float)$row['coursework_mark'],
                'examination_mark' => (float)$row['examination_mark'],
                'total_mark' => $total,
            ]);
            resultAudit($pdo, $user['user_id'], 'bulk_draft_saved', 'academic_result', $resultId, [
                'import_id' => (int)$job['id'], 'status' => 'Draft', 'total_mark' => $total,
            ]);
        }
        $pdo->prepare(
            "UPDATE portal_result_imports SET import_status = 'Drafts Saved' WHERE id = ?"
        )->execute([$job['id']]);
        resultAudit($pdo, $user['user_id'], 'bulk_drafts_saved', 'academic_result_import', (int)$job['id'], [
            'result_count' => count($resultIds), 'status' => 'Drafts Saved',
        ]);
        $pdo->commit();
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((string)$error->getCode() === '23000') {
            resultFail('A class result was added after the preview. Upload the corrected CSV again.', 409);
        }
        throw $error;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    jsonResponse(['success' => true, 'import_status' => 'Drafts Saved', 'result_count' => count($resultIds)]);
}

if ($action === 'submit_import') {
    if ($user['role'] !== 'lecturer') {
        resultFail('Only lecturers can submit class results to the HOD.', 403);
    }
    if (($input['confirmed'] ?? false) !== true) {
        resultFail('Confirm submission of all imported draft results to the HOD.');
    }
    $token = $input['import_token'] ?? null;
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        resultFail('Invalid import reference.');
    }
    $pdo->beginTransaction();
    try {
        $jobQuery = $pdo->prepare(
            'SELECT * FROM portal_result_imports WHERE import_token = ? AND lecturer_user_id = ? FOR UPDATE'
        );
        $jobQuery->execute([$token, $user['user_id']]);
        $job = $jobQuery->fetch(PDO::FETCH_ASSOC);
        if (!$job || $job['import_status'] !== 'Drafts Saved') {
            $pdo->rollBack();
            resultFail('Only imported draft results can be confirmed for HOD submission.', 409);
        }
        $rowsQuery = $pdo->prepare(
            "SELECT ir.id AS import_row_id, ir.result_id, r.*, a.hod_user_id
             FROM portal_result_import_rows ir
             JOIN portal_academic_results r ON r.id = ir.result_id
             JOIN portal_result_assignments a ON a.id = r.assignment_id
             WHERE ir.import_id = ? AND ir.row_status = 'Draft'
             ORDER BY ir.csv_row_number FOR UPDATE"
        );
        $rowsQuery->execute([$job['id']]);
        $rows = $rowsQuery->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== (int)$job['valid_rows'] || !$rows) {
            $pdo->rollBack();
            resultFail('The imported draft count does not match the preview. Contact Registry.', 409);
        }
        $updateResult = $pdo->prepare(
            "UPDATE portal_academic_results
             SET workflow_status = 'Submitted', submitted_at = NOW(), hod_reviewer_id = NULL,
                 hod_reviewed_at = NULL, returned_reason = NULL
             WHERE id = ? AND lecturer_user_id = ? AND workflow_status = 'Draft'"
        );
        $updateImportRow = $pdo->prepare(
            "UPDATE portal_result_import_rows SET row_status = 'Submitted'
             WHERE id = ? AND row_status = 'Draft'"
        );
        $hodIds = [];
        foreach ($rows as $row) {
            $updateResult->execute([$row['id'], $user['user_id']]);
            if ($updateResult->rowCount() !== 1) {
                throw new RuntimeException('A draft changed during bulk HOD submission.');
            }
            $updateImportRow->execute([$row['import_row_id']]);
            if ($updateImportRow->rowCount() !== 1) {
                throw new RuntimeException('A bulk import row changed during HOD submission.');
            }
            resultHistory($pdo, (int)$row['id'], $user['user_id'], 'bulk_submitted', 'Draft', 'Submitted', null, null, [
                'import_id' => (int)$job['id'], 'submitted_at' => date(DATE_ATOM),
            ]);
            resultAudit($pdo, $user['user_id'], 'bulk_submitted', 'academic_result', (int)$row['id'], [
                'import_id' => (int)$job['id'], 'status' => 'Submitted',
            ]);
            resultNotify(
                $pdo, (string)$row['hod_user_id'], 'Class results awaiting HOD approval',
                $job['course_code'] . ' result for ' . $row['student_university_id'] . ' is ready for review.',
                (int)$row['id'], 'bulk-submitted'
            );
            $hodIds[(string)$row['hod_user_id']] = true;
        }
        $pdo->prepare(
            "UPDATE portal_result_imports SET import_status = 'Submitted' WHERE id = ?"
        )->execute([$job['id']]);
        resultAudit($pdo, $user['user_id'], 'bulk_import_submitted', 'academic_result_import', (int)$job['id'], [
            'result_count' => count($rows), 'status' => 'Submitted',
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    foreach (array_keys($hodIds) as $hodId) {
        resultEmail($pdo, $hodId, 'Class results awaiting HOD approval',
            $job['course_code'] . ' class results are ready for review.', (int)$job['id']);
    }
    jsonResponse(['success' => true, 'import_status' => 'Submitted', 'result_count' => count($rows)]);
}

if ($action === 'submit') {
    if ($user['role'] !== 'lecturer') {
        resultFail('Only lecturers can submit results to the HOD.', 403);
    }
    $resultId = filter_var($input['result_id'] ?? null, FILTER_VALIDATE_INT);
    if ($resultId === false || $resultId === null || $resultId < 1) {
        resultFail('Select a result to submit.');
    }
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare(
            "SELECT r.*, a.hod_user_id FROM portal_academic_results r
             JOIN portal_result_assignments a ON a.id = r.assignment_id
             WHERE r.id = ? AND r.lecturer_user_id = ? AND a.is_active = 1 FOR UPDATE"
        );
        $query->execute([$resultId, $user['user_id']]);
        $result = $query->fetch(PDO::FETCH_ASSOC);
        if (!$result || !in_array($result['workflow_status'], ['Draft', 'Returned'], true)) {
            $pdo->rollBack();
            resultFail('Only your draft or returned result can be submitted.', 409);
        }
        $beforeStatus = (string)$result['workflow_status'];
        $pdo->prepare(
            "UPDATE portal_academic_results
             SET workflow_status = 'Submitted', submitted_at = NOW(), hod_reviewer_id = NULL,
                 hod_reviewed_at = NULL, returned_reason = NULL
             WHERE id = ?"
        )->execute([$resultId]);
        resultHistory($pdo, $resultId, $user['user_id'], 'submitted', $beforeStatus, 'Submitted', null, null, [
            'submitted_at' => date(DATE_ATOM),
        ]);
        resultAudit($pdo, $user['user_id'], 'submitted', 'academic_result', $resultId, [
            'previous_status' => $beforeStatus, 'status' => 'Submitted',
        ]);
        resultNotify(
            $pdo, (string)$result['hod_user_id'], 'Academic result awaiting approval',
            $result['course_code'] . ' result for ' . $result['student_university_id'] . ' is ready for review.',
            $resultId, 'submitted'
        );
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    resultEmail(
        $pdo, (string)$result['hod_user_id'], 'Academic result awaiting approval',
        'A result for ' . $result['course_code'] . ' is ready for HOD approval.',
        $resultId
    );
    jsonResponse(['success' => true, 'status' => 'Submitted']);
}

if ($action === 'review') {
    if ($user['role'] !== 'hod') {
        resultFail('Only authorised HODs can review submitted academic results.', 403);
    }
    $resultId = filter_var($input['result_id'] ?? null, FILTER_VALIDATE_INT);
    $decision = $input['decision'] ?? null;
    $reason = resultText($input['reason'] ?? '', 2000);
    if ($resultId === false || $resultId === null || $resultId < 1 || !in_array($decision, ['approve', 'return'], true)) {
        resultFail('Choose an approval decision.');
    }
    if ($decision === 'return' && $reason === null) {
        resultFail('A reason is required when returning a result for correction.');
    }
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare(
            "SELECT r.*, a.hod_user_id FROM portal_academic_results r
             JOIN portal_result_assignments a ON a.id = r.assignment_id
             WHERE r.id = ? FOR UPDATE"
        );
        $query->execute([$resultId]);
        $result = $query->fetch(PDO::FETCH_ASSOC);
        if (!$result || $result['hod_user_id'] !== $user['user_id']) {
            $pdo->rollBack();
            resultFail('This result is outside your assigned department review scope.', 403);
        }
        if ($result['workflow_status'] !== 'Submitted') {
            $pdo->rollBack();
            resultFail('Only submitted results can be reviewed.', 409);
        }
        $newStatus = $decision === 'approve' ? 'Approved' : 'Returned';
        $pdo->prepare(
            'UPDATE portal_academic_results
             SET workflow_status = ?, hod_reviewer_id = ?, hod_reviewed_at = NOW(), returned_reason = ?
             WHERE id = ?'
        )->execute([$newStatus, $user['user_id'], $decision === 'return' ? $reason : null, $resultId]);
        resultHistory($pdo, $resultId, $user['user_id'], $decision === 'approve' ? 'hod_approved' : 'hod_returned',
            'Submitted', $newStatus, $decision === 'return' ? $reason : null, null, null);
        resultAudit($pdo, $user['user_id'], $decision === 'approve' ? 'hod_approved' : 'hod_returned',
            'academic_result', $resultId, [
                'status' => $newStatus, 'reason' => $decision === 'return' ? $reason : null,
                'reviewed_at' => date(DATE_ATOM),
            ]);
        resultNotify(
            $pdo, (string)$result['lecturer_user_id'],
            $decision === 'approve' ? 'Academic result approved by HOD' : 'Academic result returned for correction',
            $result['course_code'] . ': ' . ($decision === 'approve' ? 'approved and forwarded to Registry.' : 'returned. Reason: ' . $reason),
            $resultId, 'hod-' . $decision
        );
        if ($decision === 'approve') {
            $registryUsers = $pdo->query(
                "SELECT user_id FROM users WHERE role = 'registry' AND status = 'Active'"
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($registryUsers as $registryId) {
                resultNotify(
                    $pdo, (string)$registryId, 'HOD-approved result awaiting publication',
                    $result['course_code'] . ' result for ' . $result['student_university_id'] . ' is ready for Registry.',
                    $resultId, 'approved-for-registry'
                );
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    resultEmail(
        $pdo, (string)$result['lecturer_user_id'],
        $decision === 'approve' ? 'Academic result approved' : 'Academic result returned for correction',
        $decision === 'approve'
            ? $result['course_code'] . ' was approved by the HOD and forwarded to Registry.'
            : $result['course_code'] . ' was returned. Reason: ' . $reason,
        $resultId
    );
    if ($decision === 'approve') {
        foreach ($registryUsers as $registryId) {
            resultEmail($pdo, (string)$registryId, 'HOD-approved result awaiting publication',
                $result['course_code'] . ' is awaiting Registry publication.', $resultId);
        }
    }
    jsonResponse(['success' => true, 'status' => $newStatus]);
}

if ($action === 'publish') {
    if ($user['role'] !== 'registry') {
        resultFail('Only Registry staff can officially publish academic results.', 403);
    }
    if (($input['confirmed'] ?? false) !== true) {
        resultFail('Confirm publication of the selected academic year and semester.');
    }
    $academicYear = resultYear($input['academic_year'] ?? null);
    $semester = resultText($input['semester'] ?? null, 32);
    if ($academicYear === null || $semester === null) {
        resultFail('Select a valid academic year and semester.');
    }
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare(
            "SELECT * FROM portal_academic_results
             WHERE workflow_status = 'Approved' AND academic_year = ? AND semester = ?
             ORDER BY id FOR UPDATE"
        );
        $query->execute([$academicYear, $semester]);
        $results = $query->fetchAll(PDO::FETCH_ASSOC);
        if (!$results) {
            $pdo->rollBack();
            resultFail('There are no HOD-approved results awaiting publication for this semester.', 409);
        }
        $publishedAt = date('Y-m-d H:i:s');
        $pdo->prepare(
            'INSERT INTO portal_result_publication_batches
                (academic_year, semester, publisher_user_id, published_at, result_count)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$academicYear, $semester, $user['user_id'], $publishedAt, count($results)]);
        $batchId = (int)$pdo->lastInsertId();
        $update = $pdo->prepare(
            "UPDATE portal_academic_results
             SET workflow_status = 'Published', publication_date = ?, registry_publisher_id = ?,
                 publication_batch_id = ?
             WHERE id = ? AND workflow_status = 'Approved'"
        );
        $students = [];
        foreach ($results as $result) {
            $update->execute([$publishedAt, $user['user_id'], $batchId, $result['id']]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('A result changed while publication was being processed.');
            }
            resultHistory($pdo, (int)$result['id'], $user['user_id'], 'published', 'Approved', 'Published', null, null, [
                'batch_id' => $batchId, 'published_at' => $publishedAt,
            ]);
            resultAudit($pdo, $user['user_id'], 'published', 'academic_result', (int)$result['id'], [
                'batch_id' => $batchId, 'published_at' => $publishedAt,
            ]);
            resultNotify(
                $pdo, (string)$result['student_user_id'], 'Academic results published',
                'Your ' . $academicYear . ' ' . $semester . ' results are now available in the AU Student Success Hub (AUSSH).',
                (int)$result['id'], 'published'
            );
            $students[(string)$result['student_user_id']] = true;
        }
        resultAudit($pdo, $user['user_id'], 'publication_batch_created', 'academic_result_batch', $batchId, [
            'academic_year' => $academicYear, 'semester' => $semester,
            'result_count' => count($results), 'published_at' => $publishedAt,
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    foreach (array_keys($students) as $studentId) {
        resultEmail($pdo, $studentId, 'Your academic results have been published',
            'Your ' . $academicYear . ' ' . $semester . ' results are available in the AU Student Success Hub (AUSSH).',
            $batchId);
    }
    jsonResponse(['success' => true, 'batch_id' => $batchId, 'result_count' => count($results)]);
}

if ($action === 'correct_published') {
    if ($user['role'] !== 'registry') {
        resultFail('Only Registry staff can make a controlled correction to a published result.', 403);
    }
    $resultId = filter_var($input['result_id'] ?? null, FILTER_VALIDATE_INT);
    $reason = resultText($input['reason'] ?? null, 2000);
    if ($resultId === false || $resultId === null || $resultId < 1 || $reason === null) {
        resultFail('Select a published result and provide the correction reason.');
    }
    if ($settings['coursework_max'] === null || $settings['examination_max'] === null) {
        resultFail('Registry marking maxima are not configured.', 409);
    }
    $coursework = resultMark($input['coursework_mark'] ?? null, $settings['coursework_max']);
    $examination = resultMark($input['examination_mark'] ?? null, $settings['examination_max']);
    if ($coursework === null || $examination === null) {
        resultFail('Corrected marks must be within the configured component maxima.');
    }
    $total = round($coursework + $examination, 2);
    if ($total > $settings['total_max']) {
        resultFail('The total mark cannot exceed 100.');
    }
    $grade = resultGrade($total, $settings['grade_scale']);
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT * FROM portal_academic_results WHERE id = ? FOR UPDATE');
        $query->execute([$resultId]);
        $result = $query->fetch(PDO::FETCH_ASSOC);
        if (!$result || $result['workflow_status'] !== 'Published') {
            $pdo->rollBack();
            resultFail('Only an officially published result can be corrected.', 409);
        }
        $before = resultSnapshot($result);
        $after = [
            'coursework_mark' => $coursework, 'examination_mark' => $examination,
            'total_mark' => $total, 'grade' => $grade,
        ];
        $pdo->prepare(
            'UPDATE portal_academic_results
             SET coursework_mark = ?, examination_mark = ?, total_mark = ?, grade = ?
             WHERE id = ?'
        )->execute([$coursework, $examination, $total, $grade, $resultId]);
        resultHistory($pdo, $resultId, $user['user_id'], 'registry_correction', 'Published', 'Published',
            $reason, $before, $after);
        resultAudit($pdo, $user['user_id'], 'registry_correction', 'academic_result', $resultId, [
            'reason' => $reason, 'before' => $before, 'after' => $after,
        ]);
        resultNotify(
            $pdo, (string)$result['student_user_id'], 'Published academic result corrected',
            'Registry corrected your published result for ' . $result['course_code'] . '. Reason: ' . $reason,
            $resultId, 'corrected-' . bin2hex(random_bytes(6))
        );
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    resultEmail($pdo, (string)$result['student_user_id'], 'A published academic result was corrected',
        'Registry corrected your published result for ' . $result['course_code'] . '. Reason: ' . $reason,
        $resultId);
    jsonResponse(['success' => true, 'status' => 'Published']);
}

jsonResponse(['success' => false, 'message' => 'Unknown academic results action.'], 422);
