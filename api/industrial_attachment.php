<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/industrial_attachment_lib.php';

$user = requireAuthentication();
$pdo = database();
$account = $pdo->prepare(
    "SELECT user_id, full_name, email, role
     FROM users
     WHERE user_id = ? AND status = 'Active'
     LIMIT 1"
);
$account->execute([$user['user_id']]);
$currentUser = $account->fetch(PDO::FETCH_ASSOC);
if (!$currentUser || $currentUser['role'] !== $user['role']) {
    jsonResponse(['success' => false, 'message' => 'Your account is no longer authorised. Sign in again.'], 403);
}
$user = array_merge($user, $currentUser);

function industrialAttachmentId(mixed $value): ?int
{
    $id = filter_var($value, FILTER_VALIDATE_INT);
    return $id !== false && $id !== null && $id > 0 ? (int)$id : null;
}

function industrialAttachmentIsManager(array $user): bool
{
    return in_array($user['role'] ?? '', ['admin', 'attachment_coordinator'], true);
}

function industrialAttachmentUsers(PDO $pdo, string $where, array $parameters): array
{
    $query = $pdo->prepare(
        "SELECT a.*, student.full_name AS student_name,
                COALESCE(profile.college, '') AS college,
                supervisor.full_name AS academic_supervisor_name,
                supervisor.email AS academic_supervisor_email
         FROM portal_industrial_attachments a
         INNER JOIN users student ON student.user_id = a.student_user_id
         LEFT JOIN portal_account_profiles profile ON profile.user_id = a.student_user_id
         LEFT JOIN users supervisor ON supervisor.user_id = a.academic_supervisor_user_id
         WHERE $where
         ORDER BY a.created_at DESC
         LIMIT 500"
    );
    $query->execute($parameters);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function industrialAttachmentEnrich(PDO $pdo, array $rows): array
{
    if (!$rows) {
        return [];
    }
    $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
    $marks = implode(',', array_fill(0, count($ids), '?'));

    $query = $pdo->prepare(
        "SELECT id, attachment_id, report_id, document_type, original_filename, mime_type,
                file_size, uploaded_at
         FROM portal_industrial_attachment_documents
         WHERE attachment_id IN ($marks)
         ORDER BY uploaded_at, id"
    );
    $query->execute($ids);
    $documents = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $document) {
        $attachmentId = (int)$document['attachment_id'];
        $document['id'] = (int)$document['id'];
        $document['file_size'] = (int)$document['file_size'];
        $documents[$attachmentId][] = $document;
    }

    $query = $pdo->prepare(
        "SELECT id, attachment_id, week_number, period_start, period_end, activities,
                skills_acquired, challenges, lessons_learnt, status, latest_feedback, submitted_at
         FROM portal_industrial_attachment_reports
         WHERE attachment_id IN ($marks)
         ORDER BY week_number DESC, submitted_at DESC, id DESC"
    );
    $query->execute($ids);
    $reports = $query->fetchAll(PDO::FETCH_ASSOC);
    $reportIds = [];
    foreach ($reports as &$report) {
        $report['id'] = (int)$report['id'];
        $report['week_number'] = (int)$report['week_number'];
        $report['documents'] = [];
        $reportIds[] = $report['id'];
        $documentsForReport = $documents[(int)$report['attachment_id']] ?? [];
        foreach ($documentsForReport as $document) {
            if ((int)($document['report_id'] ?? 0) === $report['id']) {
                $report['documents'][] = $document;
            }
        }
    }
    unset($report);

    $submissionsByReport = [];
    $historyByReport = [];
    if ($reportIds) {
        $reportMarks = implode(',', array_fill(0, count($reportIds), '?'));
        $query = $pdo->prepare(
            "SELECT report_id, version, submission_data, submitted_at
             FROM portal_industrial_attachment_report_submissions
             WHERE report_id IN ($reportMarks)
             ORDER BY version"
        );
        $query->execute($reportIds);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $submission) {
            $reportId = (int)$submission['report_id'];
            $submission['version'] = (int)$submission['version'];
            $submission['submission_data'] = json_decode((string)$submission['submission_data'], true) ?: [];
            $submissionsByReport[$reportId][] = $submission;
        }
        $query = $pdo->prepare(
            "SELECT report_id, actor_user_id, action, status, feedback, created_at
             FROM portal_industrial_attachment_report_history
             WHERE report_id IN ($reportMarks)
             ORDER BY created_at, id"
        );
        $query->execute($reportIds);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $entry) {
            $historyByReport[(int)$entry['report_id']][] = $entry;
        }
    }
    foreach ($reports as &$report) {
        $report['submissions'] = $submissionsByReport[$report['id']] ?? [];
        $report['history'] = $historyByReport[$report['id']] ?? [];
    }
    unset($report);

    $query = $pdo->prepare(
        "SELECT attachment_id, assessment_type, assessor_user_id, overall_feedback,
                practical_skills_feedback, score, submitted_at
         FROM portal_industrial_attachment_assessments
         WHERE attachment_id IN ($marks)"
    );
    $query->execute($ids);
    $assessments = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $assessment) {
        $assessment['score'] = $assessment['score'] === null ? null : (float)$assessment['score'];
        $assessments[(int)$assessment['attachment_id']][$assessment['assessment_type']] = $assessment;
    }

    $query = $pdo->prepare(
        "SELECT attachment_id, actor_user_id, action, from_status, to_status, comment, created_at
         FROM portal_industrial_attachment_history
         WHERE attachment_id IN ($marks)
         ORDER BY created_at, id"
    );
    $query->execute($ids);
    $history = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $entry) {
        $history[(int)$entry['attachment_id']][] = $entry;
    }

    $query = $pdo->prepare(
        "SELECT id, attachment_id, workplace_supervisor_user_id, attendance_date,
                attendance_status, supervisor_note, created_at, updated_at
         FROM portal_industrial_attachment_attendance
         WHERE attachment_id IN ($marks)
         ORDER BY attendance_date DESC"
    );
    $query->execute($ids);
    $attendance = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $entry) {
        $attendance[(int)$entry['attachment_id']][] = $entry;
    }

    $query = $pdo->prepare(
        "SELECT id, attachment_id, title, message, created_at
         FROM portal_industrial_attachment_announcements
         WHERE attachment_id IS NULL OR attachment_id IN ($marks)
         ORDER BY created_at DESC
         LIMIT 100"
    );
    $query->execute($ids);
    $announcements = $query->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $id = (int)$row['id'];
        $row['id'] = $id;
        $row['documents'] = $documents[$id] ?? [];
        $row['reports'] = array_values(array_filter(
            $reports,
            static fn(array $report): bool => (int)$report['attachment_id'] === $id
        ));
        $row['assessments'] = $assessments[$id] ?? [];
        $row['history'] = $history[$id] ?? [];
        $row['attendance'] = $attendance[$id] ?? [];
        $row['announcements'] = array_values(array_filter(
            $announcements,
            static fn(array $announcement): bool =>
                $announcement['attachment_id'] === null || (int)$announcement['attachment_id'] === $id
        ));
        $row['commencement_status'] = $row['approval_status'] === 'Approved'
            ? (
                date('Y-m-d') < $row['start_date']
                    ? 'Not Commenced'
                    : (date('Y-m-d') >= $row['expected_end_date'] ? 'Completed' : 'Active')
            )
            : $row['approval_status'];
    }
    unset($row);
    return $rows;
}

function industrialAttachmentDecisionRecipients(PDO $pdo): array
{
    $query = $pdo->prepare(
        "SELECT user_id FROM users
         WHERE status = 'Active' AND role IN ('admin', 'attachment_coordinator')"
    );
    $query->execute();
    return array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
}

function industrialAttachmentUploadsOrRespond(string $field, int $maximumFiles): array
{
    try {
        return industrialAttachmentCollectUploads($field, $maximumFiles);
    } catch (IndustrialAttachmentException $error) {
        jsonResponse(['success' => false, 'message' => $error->getMessage()], $error->httpStatus);
        return [];
    }
}

function industrialAttachmentRequireAssigned(PDO $pdo, int $attachmentId, array $user): array
{
    $query = $pdo->prepare(
        'SELECT * FROM portal_industrial_attachments WHERE id = ? LIMIT 1'
    );
    $query->execute([$attachmentId]);
    $record = $query->fetch(PDO::FETCH_ASSOC);
    if (!$record) {
        jsonResponse(['success' => false, 'message' => 'Attachment record not found.'], 404);
    }
    $owns = $user['role'] === 'student' && $record['student_user_id'] === $user['user_id'];
    $assigned = in_array($user['role'], ['lecturer', 'supervisor'], true) &&
        $record['academic_supervisor_user_id'] === $user['user_id'];
    $workplace = $user['role'] === 'workplace_supervisor' &&
        strtolower((string)$record['workplace_supervisor_email']) === strtolower((string)$user['email']);
    if (!$owns && !$assigned && !$workplace && !industrialAttachmentIsManager($user)) {
        jsonResponse(['success' => false, 'message' => 'You are not authorised to access this attachment record.'], 403);
    }
    return $record;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    $downloadId = industrialAttachmentId($_GET['download'] ?? null);
    if ($downloadId !== null) {
        $query = $pdo->prepare(
            'SELECT d.*, a.student_user_id, a.academic_supervisor_user_id,
                    a.workplace_supervisor_email
             FROM portal_industrial_attachment_documents d
             INNER JOIN portal_industrial_attachments a ON a.id = d.attachment_id
             WHERE d.id = ? LIMIT 1'
        );
        $query->execute([$downloadId]);
        $document = $query->fetch(PDO::FETCH_ASSOC);
        if (!$document) {
            jsonResponse(['success' => false, 'message' => 'Document not found.'], 404);
        }
        $owns = $user['role'] === 'student' && $document['student_user_id'] === $user['user_id'];
        $assigned = in_array($user['role'], ['lecturer', 'supervisor'], true) &&
            $document['academic_supervisor_user_id'] === $user['user_id'];
        $workplace = $user['role'] === 'workplace_supervisor' &&
            strtolower((string)$document['workplace_supervisor_email']) === strtolower((string)$user['email']);
        if (!$owns && !$assigned && !$workplace && !industrialAttachmentIsManager($user)) {
            jsonResponse(['success' => false, 'message' => 'You are not authorised to download this document.'], 403);
        }
        if (!preg_match('/^[a-f0-9]{64}$/', (string)$document['storage_key'])) {
            jsonResponse(['success' => false, 'message' => 'The stored document is unavailable.'], 404);
        }
        try {
            $path = industrialAttachmentStorageDirectory() . DIRECTORY_SEPARATOR . $document['storage_key'];
        } catch (IndustrialAttachmentException $error) {
            jsonResponse(['success' => false, 'message' => $error->getMessage()], $error->httpStatus);
        }
        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath)) {
            jsonResponse(['success' => false, 'message' => 'The stored document is unavailable.'], 404);
        }
        header('Content-Type: ' . $document['mime_type']);
        header('Content-Length: ' . (string)filesize($realPath));
        header('Content-Disposition: attachment; filename="' . rawurlencode($document['original_filename']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        readfile($realPath);
        exit;
    }

    if (
        !industrialAttachmentIsManager($user) &&
        !in_array($user['role'], ['student', 'lecturer', 'supervisor', 'workplace_supervisor'], true)
    ) {
        jsonResponse(['success' => false, 'message' => 'You are not authorised to view industrial attachment records.'], 403);
    }
    if (industrialAttachmentIsManager($user)) {
        $rows = industrialAttachmentUsers($pdo, '1 = 1', []);
    } elseif ($user['role'] === 'student') {
        $rows = industrialAttachmentUsers($pdo, 'a.student_user_id = ?', [$user['user_id']]);
    } elseif (in_array($user['role'], ['lecturer', 'supervisor'], true)) {
        $rows = industrialAttachmentUsers($pdo, 'a.academic_supervisor_user_id = ?', [$user['user_id']]);
    } else {
        $rows = industrialAttachmentUsers($pdo, 'LOWER(a.workplace_supervisor_email) = LOWER(?)', [$user['email']]);
    }
    $response = [
        'success' => true,
        'records' => industrialAttachmentEnrich($pdo, $rows),
        'current_user' => ['user_id' => $user['user_id'], 'role' => $user['role']],
    ];
    if (industrialAttachmentIsManager($user)) {
        $search = trim((string)($_GET['search'] ?? ''));
        if (mb_strlen($search, 'UTF-8') > 120) {
            jsonResponse(['success' => false, 'message' => 'Student search text is too long.'], 422);
        }
        $searchWhere = '';
        $searchParameters = [];
        if ($search !== '') {
            $searchWhere = ' AND (u.user_id LIKE ? OR u.full_name LIKE ? OR p.college LIKE ?)';
            $term = '%' . $search . '%';
            $searchParameters = [$term, $term, $term];
        }
        $students = $pdo->prepare(
            "SELECT u.user_id, u.full_name, COALESCE(p.college, '') AS college,
                    a.id AS attachment_id
             FROM users u
             LEFT JOIN portal_account_profiles p ON p.user_id = u.user_id
             LEFT JOIN portal_industrial_attachments a ON a.student_user_id = u.user_id
             WHERE u.status = 'Active' AND u.role = 'student' $searchWhere
             ORDER BY u.full_name LIMIT 100"
        );
        $students->execute($searchParameters);
        $response['students'] = $students->fetchAll(PDO::FETCH_ASSOC);
        $supervisors = $pdo->prepare(
            "SELECT user_id, full_name FROM users
             WHERE status = 'Active' AND role IN ('lecturer', 'supervisor')
             ORDER BY full_name"
        );
        $supervisors->execute();
        $response['supervisors'] = $supervisors->fetchAll(PDO::FETCH_ASSOC);
        $analytics = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(approval_status = 'Pending Approval') AS pending,
                    SUM(approval_status = 'Approved') AS approved,
                    SUM(approval_status = 'Rejected') AS rejected,
                    SUM(approval_status = 'Changes Requested') AS changes_requested,
                    SUM(approval_status = 'Approved' AND start_date <= CURRENT_DATE AND expected_end_date > CURRENT_DATE) AS active,
                    SUM(approval_status = 'Approved' AND expected_end_date <= CURRENT_DATE) AS completed,
                    SUM(approval_status = 'Approved' AND expected_end_date < CURRENT_DATE AND NOT EXISTS (
                        SELECT 1 FROM portal_industrial_attachment_assessments assessment
                        WHERE assessment.attachment_id = portal_industrial_attachments.id
                          AND assessment.assessment_type = 'Academic'
                    )) AS overdue
             FROM portal_industrial_attachments"
        );
        $analytics->execute();
        $response['analytics'] = $analytics->fetch(PDO::FETCH_ASSOC) ?: [];
        $organizations = $pdo->prepare(
            'SELECT organisation, COUNT(*) AS student_count
             FROM portal_industrial_attachments GROUP BY organisation ORDER BY student_count DESC, organisation'
        );
        $organizations->execute();
        $response['organization_counts'] = $organizations->fetchAll(PDO::FETCH_ASSOC);
        $counts = $pdo->prepare(
            "SELECT a.academic_supervisor_user_id AS user_id, u.full_name, COUNT(*) AS student_count
             FROM portal_industrial_attachments a
             INNER JOIN users u ON u.user_id = a.academic_supervisor_user_id
             GROUP BY a.academic_supervisor_user_id, u.full_name ORDER BY u.full_name"
        );
        $counts->execute();
        $response['supervisor_counts'] = $counts->fetchAll(PDO::FETCH_ASSOC);
    }
    jsonResponse($response);
}

if ($method !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}
verifyCsrf();
$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
if (str_starts_with($contentType, 'multipart/form-data')) {
    $input = $_POST;
} else {
    $input = json_decode((string)file_get_contents('php://input'), true);
}
if (!is_array($input)) {
    jsonResponse(['success' => false, 'message' => 'Invalid request.'], 400);
}
$action = (string)($input['action'] ?? '');

if ($action === 'save_placement') {
    if (!industrialAttachmentIsManager($user)) {
        jsonResponse(['success' => false, 'message' => 'Only authorised attachment coordinators can manage placements.'], 403);
    }
    $recordId = !empty($input['id']) ? industrialAttachmentId($input['id']) : null;
    $studentId = industrialAttachmentRequiredText($input['student_user_id'] ?? null, 64);
    $supervisorId = industrialAttachmentRequiredText($input['academic_supervisor_user_id'] ?? null, 64);
    $organisation = industrialAttachmentRequiredText($input['organisation'] ?? null, 180);
    $location = industrialAttachmentRequiredText($input['organisation_location'] ?? null, 180);
    $address = industrialAttachmentRequiredText($input['physical_address'] ?? null, 500);
    $department = industrialAttachmentRequiredText($input['department'] ?? null, 180);
    $position = industrialAttachmentRequiredText($input['position_title'] ?? null, 180);
    $startDate = industrialAttachmentDate($input['start_date'] ?? null);
    $endDate = industrialAttachmentDate($input['expected_end_date'] ?? null);
    $workplaceName = industrialAttachmentRequiredText($input['workplace_supervisor_name'] ?? null, 120);
    $workplaceEmail = industrialAttachmentRequiredText($input['workplace_supervisor_email'] ?? null, 254);
    $workplacePhone = industrialAttachmentRequiredText($input['workplace_supervisor_phone'] ?? null, 40);
    if (
        ($input['id'] ?? '') !== '' && $recordId === null ||
        $studentId === null || $supervisorId === null || $organisation === null ||
        $location === null || $address === null || $department === null || $position === null ||
        $startDate === null || $endDate === null || $endDate < $startDate ||
        $workplaceName === null || $workplaceEmail === null ||
        !filter_var($workplaceEmail, FILTER_VALIDATE_EMAIL) || $workplacePhone === null
    ) {
        jsonResponse(['success' => false, 'message' => 'Complete all placement fields and enter valid dates and supervisor contact details.'], 422);
    }
    $letterFiles = industrialAttachmentUploadsOrRespond('acceptance_letter', 1);
    $studentQuery = $pdo->prepare(
        "SELECT user_id, full_name FROM users WHERE user_id = ? AND role = 'student' AND status = 'Active' LIMIT 1"
    );
    $studentQuery->execute([$studentId]);
    $student = $studentQuery->fetch(PDO::FETCH_ASSOC);
    $supervisorQuery = $pdo->prepare(
        "SELECT user_id, full_name FROM users
         WHERE user_id = ? AND role IN ('lecturer', 'supervisor') AND status = 'Active' LIMIT 1"
    );
    $supervisorQuery->execute([$supervisorId]);
    $academicSupervisor = $supervisorQuery->fetch(PDO::FETCH_ASSOC);
    if (!$student || !$academicSupervisor) {
        jsonResponse(['success' => false, 'message' => 'Select an active registered student and an active academic supervisor.'], 422);
    }

    $storedPaths = [];
    $pdo->beginTransaction();
    try {
        if ($recordId !== null) {
            $lookup = $pdo->prepare('SELECT * FROM portal_industrial_attachments WHERE id = ? FOR UPDATE');
            $lookup->execute([$recordId]);
            $old = $lookup->fetch(PDO::FETCH_ASSOC);
            if (!$old) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'message' => 'Placement not found.'], 404);
            }
            if ($old['student_user_id'] !== $studentId) {
                $duplicate = $pdo->prepare(
                    'SELECT id FROM portal_industrial_attachments WHERE student_user_id = ? AND id <> ? LIMIT 1'
                );
                $duplicate->execute([$studentId, $recordId]);
                if ($duplicate->fetchColumn()) {
                    $pdo->rollBack();
                    jsonResponse(['success' => false, 'message' => 'This student already has an attachment placement.'], 409);
                }
            }
            $pdo->prepare(
                'UPDATE portal_industrial_attachments SET student_user_id = ?, academic_supervisor_user_id = ?,
                    organisation = ?, organisation_location = ?, physical_address = ?, department = ?,
                    position_title = ?, start_date = ?, expected_end_date = ?, workplace_supervisor_name = ?,
                    workplace_supervisor_email = ?, workplace_supervisor_phone = ?,
                    approval_status = ?, approval_reason = ? WHERE id = ?'
            )->execute([
                $studentId, $supervisorId, $organisation, $location, $address, $department,
                $position, $startDate, $endDate, $workplaceName, $workplaceEmail, $workplacePhone,
                in_array($old['approval_status'], ['Rejected', 'Changes Requested', 'Approved'], true)
                    ? 'Pending Approval'
                    : $old['approval_status'],
                in_array($old['approval_status'], ['Rejected', 'Changes Requested', 'Approved'], true)
                    ? null
                    : $old['approval_reason'],
                $recordId,
            ]);
            $actionName = 'placement_updated';
            $fromStatus = $old['approval_status'];
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO portal_industrial_attachments
                    (student_user_id, academic_supervisor_user_id, organisation, organisation_location,
                     physical_address, department, position_title, start_date, expected_end_date,
                     workplace_supervisor_name, workplace_supervisor_email, workplace_supervisor_phone,
                     created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $studentId, $supervisorId, $organisation, $location, $address, $department,
                $position, $startDate, $endDate, $workplaceName, $workplaceEmail, $workplacePhone,
                $user['user_id'],
            ]);
            $recordId = (int)$pdo->lastInsertId();
            $actionName = 'placement_created';
            $fromStatus = null;
        }
        industrialAttachmentStoreUploads(
            $pdo, $recordId, null, 'Acceptance Letter', (string)$user['user_id'], $letterFiles, $storedPaths
        );
        $history = $pdo->prepare(
            'INSERT INTO portal_industrial_attachment_history
                (attachment_id, actor_user_id, action, from_status, to_status, details)
             VALUES (?, ?, ?, ?, \'Pending Approval\', ?)'
        );
        $history->execute([
            $recordId,
            $user['user_id'],
            $actionName,
            $fromStatus,
            json_encode(['student_user_id' => $studentId], JSON_THROW_ON_ERROR),
        ]);
        industrialAttachmentAudit($pdo, (string)$user['user_id'], $actionName, $recordId, [
            'student_user_id' => $studentId,
            'academic_supervisor_user_id' => $supervisorId,
        ]);
        industrialAttachmentNotification(
            $pdo,
            (string)$academicSupervisor['user_id'],
            'Industrial attachment placement awaiting review',
            $student['full_name'] . ' has an industrial attachment placement at ' . $organisation . ' awaiting your review.',
            $recordId
        );
        industrialAttachmentNotification(
            $pdo,
            (string)$student['user_id'],
            $actionName === 'placement_created'
                ? 'Industrial attachment placement registered'
                : 'Industrial attachment placement updated',
            $actionName === 'placement_created'
                ? 'Your placement at ' . $organisation . ' is registered and pending academic supervisor approval.'
                : 'Your placement details were updated and resubmitted for academic supervisor approval.',
            $recordId
        );
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        industrialAttachmentRemoveStoredFiles($storedPaths);
        if ($error instanceof IndustrialAttachmentException) {
            jsonResponse(['success' => false, 'message' => $error->getMessage()], $error->httpStatus);
        }
        if ($error instanceof PDOException && (string)$error->getCode() === '23000') {
            jsonResponse(['success' => false, 'message' => 'This student already has an attachment placement.'], 409);
        }
        error_log('Industrial attachment placement save failed: ' . get_class($error));
        jsonResponse(['success' => false, 'message' => 'The placement could not be saved. Please try again.'], 500);
    }
    jsonResponse(['success' => true, 'id' => $recordId], $actionName === 'placement_created' ? 201 : 200);
}

if ($action === 'review_placement') {
    if (!in_array($user['role'], ['admin', 'lecturer', 'supervisor'], true)) {
        jsonResponse(['success' => false, 'message' => 'Only the assigned academic supervisor or an administrator can decide placements.'], 403);
    }
    $recordId = industrialAttachmentId($input['id'] ?? null);
    $status = $input['status'] ?? null;
    $comment = is_string($input['comment'] ?? null) ? trim($input['comment']) : '';
    if (
        $recordId === null || !in_array($status, ['Approved', 'Rejected', 'Changes Requested'], true) ||
        mb_strlen($comment, 'UTF-8') > 4000 ||
        ($status !== 'Approved' && $comment === '')
    ) {
        jsonResponse(['success' => false, 'message' => 'Choose a decision and provide an explanation when rejecting or requesting changes.'], 422);
    }
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT * FROM portal_industrial_attachments WHERE id = ? FOR UPDATE');
        $query->execute([$recordId]);
        $record = $query->fetch(PDO::FETCH_ASSOC);
        if (!$record) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Placement not found.'], 404);
        }
        if (
            $user['role'] !== 'admin' &&
            $record['academic_supervisor_user_id'] !== $user['user_id']
        ) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Only the assigned academic supervisor can decide this placement.'], 403);
        }
        if ($record['approval_status'] !== 'Pending Approval' && $record['approval_status'] !== 'Changes Requested') {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'This placement is not awaiting a decision.'], 409);
        }
        $pdo->prepare(
            'UPDATE portal_industrial_attachments SET approval_status = ?, approval_reason = ? WHERE id = ?'
        )->execute([$status, $comment !== '' ? $comment : null, $recordId]);
        $pdo->prepare(
            'INSERT INTO portal_industrial_attachment_history
                (attachment_id, actor_user_id, action, from_status, to_status, comment)
             VALUES (?, ?, \'placement_decision\', ?, ?, ?)'
        )->execute([$recordId, $user['user_id'], $record['approval_status'], $status, $comment]);
        industrialAttachmentAudit($pdo, (string)$user['user_id'], 'placement_decision', $recordId, [
            'from_status' => $record['approval_status'],
            'to_status' => $status,
            'comment' => $comment,
        ]);
        $title = $status === 'Approved'
            ? 'Industrial attachment placement approved'
            : ($status === 'Rejected' ? 'Industrial attachment placement rejected' : 'Changes requested for your placement');
        industrialAttachmentNotification(
            $pdo,
            (string)$record['student_user_id'],
            $title,
            'Your placement was ' . strtolower($status) . '.' . ($comment !== '' ? ' Reason or feedback: ' . $comment : ''),
            $recordId
        );
        foreach (industrialAttachmentDecisionRecipients($pdo) as $recipientId) {
            if ($recipientId !== $user['user_id']) {
                industrialAttachmentNotification(
                    $pdo,
                    $recipientId,
                    'Industrial attachment placement decision recorded',
                    $user['full_name'] . ' ' . strtolower($status) . ' a placement for student ' . $record['student_user_id'] . '.',
                    $recordId
                );
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Industrial attachment placement decision failed: ' . get_class($error));
        jsonResponse(['success' => false, 'message' => 'The placement decision could not be saved. Please try again.'], 500);
    }
    jsonResponse(['success' => true]);
}

if ($action === 'submit_report') {
    if ($user['role'] !== 'student') {
        jsonResponse(['success' => false, 'message' => 'Only the student assigned to a placement can submit a weekly report.'], 403);
    }
    $recordId = industrialAttachmentId($input['attachment_id'] ?? null);
    $weekNumber = filter_var($input['week_number'] ?? null, FILTER_VALIDATE_INT);
    $periodStart = industrialAttachmentDate($input['period_start'] ?? null);
    $periodEnd = industrialAttachmentDate($input['period_end'] ?? null);
    $activities = industrialAttachmentRequiredText($input['activities'] ?? null, 12000);
    $skills = industrialAttachmentRequiredText($input['skills_acquired'] ?? null, 6000);
    $challenges = industrialAttachmentRequiredText($input['challenges'] ?? null, 6000);
    $lessons = industrialAttachmentRequiredText($input['lessons_learnt'] ?? null, 6000);
    if (
        $recordId === null || $weekNumber === false || $weekNumber === null || $weekNumber < 1 || $weekNumber > 104 ||
        $periodStart === null || $periodEnd === null || $periodEnd < $periodStart ||
        $periodEnd > date('Y-m-d') ||
        $activities === null || $skills === null || $challenges === null || $lessons === null
    ) {
        jsonResponse(['success' => false, 'message' => 'Complete the report fields and enter a valid week number and reporting period.'], 422);
    }
    $files = industrialAttachmentUploadsOrRespond('documents', INDUSTRIAL_ATTACHMENT_MAX_REPORT_FILES);
    $storedPaths = [];
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT * FROM portal_industrial_attachments WHERE id = ? FOR UPDATE');
        $query->execute([$recordId]);
        $record = $query->fetch(PDO::FETCH_ASSOC);
        if (!$record || $record['student_user_id'] !== $user['user_id']) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Placement not found or not authorised.'], 404);
        }
        if (
            $record['approval_status'] !== 'Approved' ||
            $periodStart < $record['start_date'] || $periodEnd > $record['expected_end_date']
        ) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Reports can only be submitted for approved placement dates.'], 409);
        }
        $insert = $pdo->prepare(
            'INSERT INTO portal_industrial_attachment_reports
                (attachment_id, week_number, period_start, period_end, activities,
                 skills_acquired, challenges, lessons_learnt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $recordId, $weekNumber, $periodStart, $periodEnd,
            $activities, $skills, $challenges, $lessons,
        ]);
        $reportId = (int)$pdo->lastInsertId();
        $snapshot = [
            'week_number' => (int)$weekNumber,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'activities' => $activities,
            'skills_acquired' => $skills,
            'challenges' => $challenges,
            'lessons_learnt' => $lessons,
        ];
        $pdo->prepare(
            'INSERT INTO portal_industrial_attachment_report_submissions
                (report_id, version, submission_data, submitted_by)
             VALUES (?, 1, ?, ?)'
        )->execute([
            $reportId,
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $user['user_id'],
        ]);
        $pdo->prepare(
            'INSERT INTO portal_industrial_attachment_report_history
                (report_id, actor_user_id, action, status)
             VALUES (?, ?, \'submitted\', \'Pending Review\')'
        )->execute([$reportId, $user['user_id']]);
        industrialAttachmentStoreUploads(
            $pdo, $recordId, $reportId, 'Weekly Report Supporting Document',
            (string)$user['user_id'], $files, $storedPaths
        );
        industrialAttachmentAudit($pdo, (string)$user['user_id'], 'weekly_report_submitted', $recordId, [
            'report_id' => $reportId,
            'week_number' => (int)$weekNumber,
        ]);
        if ($record['academic_supervisor_user_id']) {
            industrialAttachmentNotification(
                $pdo,
                (string)$record['academic_supervisor_user_id'],
                'Weekly industrial attachment report submitted',
                $user['full_name'] . ' submitted the week ' . $weekNumber . ' attachment report for review.',
                $recordId
            );
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        industrialAttachmentRemoveStoredFiles($storedPaths);
        if ($error instanceof IndustrialAttachmentException) {
            jsonResponse(['success' => false, 'message' => $error->getMessage()], $error->httpStatus);
        }
        if ($error instanceof PDOException && (string)$error->getCode() === '23000') {
            jsonResponse(['success' => false, 'message' => 'A report has already been submitted for this week. Ask your supervisor to return it before resubmitting.'], 409);
        }
        error_log('Industrial attachment report submission failed: ' . get_class($error));
        jsonResponse(['success' => false, 'message' => 'The weekly report could not be submitted. Please try again.'], 500);
    }
    jsonResponse(['success' => true, 'id' => $reportId], 201);
}

if ($action === 'resubmit_report') {
    if ($user['role'] !== 'student') {
        jsonResponse(['success' => false, 'message' => 'Only the student who submitted a report can correct it.'], 403);
    }
    $reportId = industrialAttachmentId($input['report_id'] ?? null);
    $periodStart = industrialAttachmentDate($input['period_start'] ?? null);
    $periodEnd = industrialAttachmentDate($input['period_end'] ?? null);
    $activities = industrialAttachmentRequiredText($input['activities'] ?? null, 12000);
    $skills = industrialAttachmentRequiredText($input['skills_acquired'] ?? null, 6000);
    $challenges = industrialAttachmentRequiredText($input['challenges'] ?? null, 6000);
    $lessons = industrialAttachmentRequiredText($input['lessons_learnt'] ?? null, 6000);
    if (
        $reportId === null || $periodStart === null || $periodEnd === null || $periodEnd < $periodStart ||
        $activities === null || $skills === null || $challenges === null || $lessons === null
    ) {
        jsonResponse(['success' => false, 'message' => 'Complete the corrected report fields and enter a valid reporting period.'], 422);
    }
    $files = industrialAttachmentUploadsOrRespond('documents', INDUSTRIAL_ATTACHMENT_MAX_REPORT_FILES);
    $storedPaths = [];
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare(
            'SELECT r.*, a.student_user_id, a.start_date, a.expected_end_date,
                    a.academic_supervisor_user_id, a.approval_status
             FROM portal_industrial_attachment_reports r
             INNER JOIN portal_industrial_attachments a ON a.id = r.attachment_id
             WHERE r.id = ? FOR UPDATE'
        );
        $query->execute([$reportId]);
        $report = $query->fetch(PDO::FETCH_ASSOC);
        if (!$report || $report['student_user_id'] !== $user['user_id']) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Report not found or not authorised.'], 404);
        }
        if (
            $report['status'] !== 'Changes Requested' || $report['approval_status'] !== 'Approved' ||
            $periodStart < $report['start_date'] || $periodEnd > $report['expected_end_date'] ||
            $periodEnd > date('Y-m-d')
        ) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'This report is not open for correction or its period is outside the placement.'], 409);
        }
        $versionQuery = $pdo->prepare(
            'SELECT COALESCE(MAX(version), 0) + 1 FROM portal_industrial_attachment_report_submissions WHERE report_id = ?'
        );
        $versionQuery->execute([$reportId]);
        $version = (int)$versionQuery->fetchColumn();
        $snapshot = [
            'week_number' => (int)$report['week_number'],
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'activities' => $activities,
            'skills_acquired' => $skills,
            'challenges' => $challenges,
            'lessons_learnt' => $lessons,
        ];
        $pdo->prepare(
            'UPDATE portal_industrial_attachment_reports
             SET period_start = ?, period_end = ?, activities = ?, skills_acquired = ?,
                 challenges = ?, lessons_learnt = ?, status = \'Pending Review\', latest_feedback = NULL
             WHERE id = ?'
        )->execute([$periodStart, $periodEnd, $activities, $skills, $challenges, $lessons, $reportId]);
        $pdo->prepare(
            'INSERT INTO portal_industrial_attachment_report_submissions
                (report_id, version, submission_data, submitted_by)
             VALUES (?, ?, ?, ?)'
        )->execute([
            $reportId,
            $version,
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $user['user_id'],
        ]);
        $pdo->prepare(
            'INSERT INTO portal_industrial_attachment_report_history
                (report_id, actor_user_id, action, status)
             VALUES (?, ?, \'resubmitted\', \'Pending Review\')'
        )->execute([$reportId, $user['user_id']]);
        industrialAttachmentStoreUploads(
            $pdo, (int)$report['attachment_id'], $reportId, 'Weekly Report Supporting Document',
            (string)$user['user_id'], $files, $storedPaths
        );
        industrialAttachmentAudit($pdo, (string)$user['user_id'], 'weekly_report_resubmitted', (int)$report['attachment_id'], [
            'report_id' => $reportId,
            'version' => $version,
        ]);
        if ($report['academic_supervisor_user_id']) {
            industrialAttachmentNotification(
                $pdo,
                (string)$report['academic_supervisor_user_id'],
                'Corrected weekly report submitted',
                $user['full_name'] . ' resubmitted the week ' . $report['week_number'] . ' report.',
                (int)$report['attachment_id']
            );
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        industrialAttachmentRemoveStoredFiles($storedPaths);
        if ($error instanceof IndustrialAttachmentException) {
            jsonResponse(['success' => false, 'message' => $error->getMessage()], $error->httpStatus);
        }
        error_log('Industrial attachment report correction failed: ' . get_class($error));
        jsonResponse(['success' => false, 'message' => 'The report correction could not be submitted. Please try again.'], 500);
    }
    jsonResponse(['success' => true]);
}

if ($action === 'review_report') {
    if (!in_array($user['role'], ['admin', 'lecturer', 'supervisor'], true)) {
        jsonResponse(['success' => false, 'message' => 'Only an academic supervisor can review weekly reports.'], 403);
    }
    $reportId = industrialAttachmentId($input['report_id'] ?? null);
    $status = $input['status'] ?? null;
    $feedback = is_string($input['feedback'] ?? null) ? trim($input['feedback']) : '';
    if (
        $reportId === null || !in_array($status, ['Approved', 'Changes Requested'], true) ||
        mb_strlen($feedback, 'UTF-8') > 4000 || ($status === 'Changes Requested' && $feedback === '')
    ) {
        jsonResponse(['success' => false, 'message' => 'Choose a report decision and provide feedback when requesting corrections.'], 422);
    }
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare(
            'SELECT r.*, a.id AS attachment_id, a.student_user_id, a.academic_supervisor_user_id
             FROM portal_industrial_attachment_reports r
             INNER JOIN portal_industrial_attachments a ON a.id = r.attachment_id
             WHERE r.id = ? FOR UPDATE'
        );
        $query->execute([$reportId]);
        $report = $query->fetch(PDO::FETCH_ASSOC);
        if (!$report) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Report not found.'], 404);
        }
        if (
            $user['role'] !== 'admin' &&
            $report['academic_supervisor_user_id'] !== $user['user_id']
        ) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Only the assigned academic supervisor can review this report.'], 403);
        }
        if ($report['status'] !== 'Pending Review') {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'This report has already been reviewed.'], 409);
        }
        $pdo->prepare(
            'UPDATE portal_industrial_attachment_reports SET status = ?, latest_feedback = ? WHERE id = ?'
        )->execute([$status, $feedback !== '' ? $feedback : null, $reportId]);
        $pdo->prepare(
            'INSERT INTO portal_industrial_attachment_report_history
                (report_id, actor_user_id, action, status, feedback)
             VALUES (?, ?, \'reviewed\', ?, ?)'
        )->execute([$reportId, $user['user_id'], $status, $feedback]);
        industrialAttachmentAudit($pdo, (string)$user['user_id'], 'weekly_report_reviewed', (int)$report['attachment_id'], [
            'report_id' => $reportId,
            'status' => $status,
        ]);
        industrialAttachmentNotification(
            $pdo,
            (string)$report['student_user_id'],
            'Weekly attachment report reviewed',
            'Your week ' . $report['week_number'] . ' report was ' . strtolower($status) .
                ($feedback !== '' ? '. Supervisor feedback: ' . $feedback : '.'),
            (int)$report['attachment_id']
        );
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Industrial attachment report review failed: ' . get_class($error));
        jsonResponse(['success' => false, 'message' => 'The report decision could not be saved. Please try again.'], 500);
    }
    jsonResponse(['success' => true]);
}

if ($action === 'save_assessment') {
    $recordId = industrialAttachmentId($input['attachment_id'] ?? null);
    $type = $input['assessment_type'] ?? null;
    $overall = industrialAttachmentRequiredText($input['overall_feedback'] ?? null, 6000);
    $skills = industrialAttachmentRequiredText($input['practical_skills_feedback'] ?? null, 6000);
    $scoreInput = $input['score'] ?? '';
    $score = $scoreInput === '' ? null : filter_var($scoreInput, FILTER_VALIDATE_FLOAT);
    if (
        $recordId === null || !in_array($type, ['Academic', 'Workplace'], true) ||
        $overall === null || $skills === null ||
        ($scoreInput !== '' && ($score === false || $score < 0 || $score > 100))
    ) {
        jsonResponse(['success' => false, 'message' => 'Complete both assessment feedback fields and enter a score from 0 to 100 if applicable.'], 422);
    }
    if (
        ($type === 'Academic' && !in_array($user['role'], ['admin', 'lecturer', 'supervisor'], true)) ||
        ($type === 'Workplace' && !in_array($user['role'], ['admin', 'workplace_supervisor'], true))
    ) {
        jsonResponse(['success' => false, 'message' => 'You are not authorised to submit this type of assessment.'], 403);
    }
    $record = industrialAttachmentRequireAssigned($pdo, $recordId, $user);
    if (
        ($type === 'Academic' && $user['role'] !== 'admin' && $record['academic_supervisor_user_id'] !== $user['user_id']) ||
        ($type === 'Workplace' && $user['role'] !== 'admin' &&
            strtolower((string)$record['workplace_supervisor_email']) !== strtolower((string)$user['email']))
    ) {
        jsonResponse(['success' => false, 'message' => 'Only the assigned supervisor can submit this assessment.'], 403);
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO portal_industrial_attachment_assessments
                (attachment_id, assessment_type, assessor_user_id, overall_feedback, practical_skills_feedback, score)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE assessor_user_id = VALUES(assessor_user_id),
                overall_feedback = VALUES(overall_feedback),
                practical_skills_feedback = VALUES(practical_skills_feedback),
                score = VALUES(score), updated_at = CURRENT_TIMESTAMP'
        )->execute([$recordId, $type, $user['user_id'], $overall, $skills, $score]);
        industrialAttachmentAudit($pdo, (string)$user['user_id'], strtolower($type) . '_assessment_saved', $recordId);
        industrialAttachmentNotification(
            $pdo,
            (string)$record['student_user_id'],
            $type . ' attachment assessment updated',
            'Your ' . strtolower($type) . ' supervisor submitted or updated an attachment assessment.',
            $recordId
        );
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Industrial attachment assessment save failed: ' . get_class($error));
        jsonResponse(['success' => false, 'message' => 'The assessment could not be saved. Please try again.'], 500);
    }
    jsonResponse(['success' => true]);
}

if ($action === 'record_attendance') {
    if ($user['role'] !== 'workplace_supervisor') {
        jsonResponse(['success' => false, 'message' => 'Only the assigned workplace supervisor can confirm attendance.'], 403);
    }
    $recordId = industrialAttachmentId($input['attachment_id'] ?? null);
    $attendanceDate = industrialAttachmentDate($input['attendance_date'] ?? null);
    $status = $input['attendance_status'] ?? null;
    $note = is_string($input['supervisor_note'] ?? null) ? trim($input['supervisor_note']) : '';
    if (
        $recordId === null || $attendanceDate === null ||
        !in_array($status, ['Present', 'Absent', 'Leave'], true) ||
        mb_strlen($note, 'UTF-8') > 2000
    ) {
        jsonResponse(['success' => false, 'message' => 'Select a valid attendance date and status.'], 422);
    }
    $record = industrialAttachmentRequireAssigned($pdo, $recordId, $user);
    if (
        $record['approval_status'] !== 'Approved' ||
        $attendanceDate < $record['start_date'] || $attendanceDate > $record['expected_end_date'] ||
        $attendanceDate > date('Y-m-d')
    ) {
        jsonResponse(['success' => false, 'message' => 'Attendance can be confirmed only for an approved placement and a date within its period that has already started.'], 422);
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO portal_industrial_attachment_attendance
                (attachment_id, workplace_supervisor_user_id, attendance_date, attendance_status, supervisor_note)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE workplace_supervisor_user_id = VALUES(workplace_supervisor_user_id),
                attendance_status = VALUES(attendance_status), supervisor_note = VALUES(supervisor_note),
                updated_at = CURRENT_TIMESTAMP'
        )->execute([$recordId, $user['user_id'], $attendanceDate, $status, $note]);
        industrialAttachmentAudit($pdo, (string)$user['user_id'], 'workplace_attendance_recorded', $recordId, [
            'attendance_date' => $attendanceDate,
            'attendance_status' => $status,
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Industrial attachment attendance update failed: ' . get_class($error));
        jsonResponse(['success' => false, 'message' => 'Attendance could not be recorded. Please try again.'], 500);
    }
    jsonResponse(['success' => true]);
}

if ($action === 'publish_announcement') {
    if (!industrialAttachmentIsManager($user)) {
        jsonResponse(['success' => false, 'message' => 'Only an authorised attachment coordinator can publish announcements.'], 403);
    }
    $title = industrialAttachmentRequiredText($input['title'] ?? null, 180);
    $message = industrialAttachmentRequiredText($input['message'] ?? null, 4000);
    $recordId = !empty($input['attachment_id']) ? industrialAttachmentId($input['attachment_id']) : null;
    if ($title === null || $message === null || (!empty($input['attachment_id']) && $recordId === null)) {
        jsonResponse(['success' => false, 'message' => 'Enter an announcement title and message, and select a valid placement if needed.'], 422);
    }
    if ($recordId !== null) {
        $target = $pdo->prepare(
            'SELECT id, student_user_id FROM portal_industrial_attachments WHERE id = ?'
        );
        $target->execute([$recordId]);
        $targetRecord = $target->fetch(PDO::FETCH_ASSOC);
        if (!$targetRecord) {
            jsonResponse(['success' => false, 'message' => 'Placement not found.'], 404);
        }
        $targets = [$targetRecord];
    } else {
        $targetQuery = $pdo->prepare(
            "SELECT id, student_user_id FROM portal_industrial_attachments
             WHERE approval_status <> 'Rejected'"
        );
        $targetQuery->execute();
        $targets = $targetQuery->fetchAll(PDO::FETCH_ASSOC);
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO portal_industrial_attachment_announcements
                (attachment_id, title, message, created_by)
             VALUES (?, ?, ?, ?)'
        )->execute([$recordId, $title, $message, $user['user_id']]);
        $announcementId = (int)$pdo->lastInsertId();
        foreach ($targets as $targetRecord) {
            industrialAttachmentNotification(
                $pdo,
                (string)$targetRecord['student_user_id'],
                $title,
                $message,
                (int)$targetRecord['id']
            );
        }
        industrialAttachmentAudit($pdo, (string)$user['user_id'], 'announcement_published', $recordId ?? $announcementId, [
            'announcement_id' => $announcementId,
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Industrial attachment announcement failed: ' . get_class($error));
        jsonResponse(['success' => false, 'message' => 'The announcement could not be published. Please try again.'], 500);
    }
    jsonResponse(['success' => true, 'id' => $announcementId], 201);
}

jsonResponse(['success' => false, 'message' => 'Unknown industrial attachment action.'], 422);
