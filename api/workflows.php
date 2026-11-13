<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mailer.php';

$user = requireAuthentication();
$pdo = database();
$account = $pdo->prepare(
    "SELECT user_id, full_name, role
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

$pdo->exec("CREATE TABLE IF NOT EXISTS portal_records (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 record_type VARCHAR(32) NOT NULL,
 owner_user_id VARCHAR(64) NOT NULL,
 owner_name VARCHAR(120) NOT NULL,
 payload JSON NOT NULL,
 status VARCHAR(40) NOT NULL DEFAULT 'Pending',
 assigned_role VARCHAR(32) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_type_role_status (record_type, assigned_role, status),
 INDEX idx_owner (owner_user_id, record_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
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
$pdo->exec("CREATE TABLE IF NOT EXISTS portal_audit (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 actor_user_id VARCHAR(64) NOT NULL,
 action VARCHAR(60) NOT NULL,
 record_type VARCHAR(32) NOT NULL,
 record_id BIGINT UNSIGNED NOT NULL,
 details JSON NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_record (record_type, record_id), INDEX idx_actor (actor_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

ensureNotificationDedupeSchema($pdo);

function ensureNotificationDedupeSchema(PDO $pdo): void
{
    $column = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'portal_notifications'
           AND COLUMN_NAME = 'dedupe_key'"
    );
    if ((int)$column->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE portal_notifications ADD COLUMN dedupe_key VARCHAR(190) NULL');
    }

    $index = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'portal_notifications'
           AND INDEX_NAME = 'uq_portal_notifications_dedupe'"
    );
    if ((int)$index->fetchColumn() === 0) {
        $pdo->exec(
            'ALTER TABLE portal_notifications
             ADD UNIQUE KEY uq_portal_notifications_dedupe (dedupe_key)'
        );
    }
}

function allowedRolesForType(string $type): array
{
    return match ($type) {
        'achievement' => ['verifier', 'lecturer', 'hod', 'admin'],
        'recommendation' => ['lecturer', 'hod', 'admin'],
        'attachment' => ['supervisor', 'hod', 'admin'],
        'support' => ['chaplaincy', 'dean', 'admin'],
        'opportunity' => ['admin', 'hod', 'registry'],
        default => [],
    };
}

function addWorkflowNotification(
    PDO $pdo,
    string $recipientId,
    string $title,
    string $message,
    string $type,
    int $recordId,
    string $event
): void {
    $statement = $pdo->prepare(
        'INSERT IGNORE INTO portal_notifications
            (recipient_user_id, title, message, record_type, record_id, dedupe_key)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        $recipientId,
        $title,
        $message,
        $type,
        $recordId,
        $type . ':' . $event . ':' . $recordId . ':' . $recipientId,
    ]);
}

function usersForRoles(PDO $pdo, array $roles): array
{
    if (!$roles) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($roles), '?'));
    $statement = $pdo->prepare(
        "SELECT user_id, full_name, email
         FROM users
         WHERE status = 'Active' AND role IN ($marks)"
    );
    $statement->execute($roles);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function workflowEmails(array $recipients, string $subject, string $message, string $context, int $recordId): int
{
    $sent = 0;
    foreach ($recipients as $recipient) {
        if (
            !isset($recipient['email'], $recipient['full_name']) ||
            !filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)
        ) {
            continue;
        }
        try {
            sendWorkflowEmail(
                (string)$recipient['email'],
                (string)$recipient['full_name'],
                $subject,
                $message
            );
            $sent++;
        } catch (Throwable $error) {
            error_log('AUSSH workflow email delivery failed (' . $context . ', record ' . $recordId . ').');
        }
    }
    return $sent;
}

function requiredText(mixed $value, int $maxLength): ?string
{
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }
    $text = trim((string)$value);
    if ($text === '' || mb_strlen($text, 'UTF-8') > $maxLength) {
        return null;
    }
    return $text;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    $arguments = [];
    if ($user['role'] === 'admin') {
        $where = '1=1';
    } elseif (in_array($user['role'], ['student', 'alumni'], true)) {
        $where = "(r.owner_user_id = ? OR (r.record_type = 'opportunity' AND r.status = 'Published'))";
        $arguments[] = $user['user_id'];
    } else {
        $clauses = ['r.owner_user_id = ?'];
        $arguments[] = $user['user_id'];

        if (in_array($user['role'], allowedRolesForType('achievement'), true)) {
            $clauses[] = "r.record_type = 'achievement'";
        }
        if (in_array($user['role'], allowedRolesForType('recommendation'), true)) {
            if ($user['role'] === 'lecturer') {
                $clauses[] = "(r.record_type = 'recommendation' AND
                    JSON_UNQUOTE(JSON_EXTRACT(r.payload, '$.lecturer_user_id')) = ?)";
                $arguments[] = $user['user_id'];
            } else {
                $clauses[] = "r.record_type = 'recommendation'";
            }
        }

        foreach (['attachment', 'support'] as $type) {
            if (in_array($user['role'], allowedRolesForType($type), true)) {
                $clauses[] = "r.record_type = '$type'";
            }
        }
        $clauses[] = '(r.assigned_role = ? AND r.record_type NOT IN (\'achievement\', \'recommendation\', \'attachment\', \'support\'))';
        $arguments[] = $user['role'];
        $where = '(' . implode(' OR ', $clauses) . ')';
    }

    $statement = $pdo->prepare(
        "SELECT r.* FROM portal_records r
         WHERE $where
         ORDER BY r.created_at DESC LIMIT 1000"
    );
    $statement->execute($arguments);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['payload'] = json_decode((string)$row['payload'], true) ?: [];
        $row['id'] = (int)$row['id'];
    }
    unset($row);

    $notifications = $pdo->prepare(
        'SELECT id, title, message, record_type, record_id, is_read, created_at
         FROM portal_notifications
         WHERE recipient_user_id = ?
         ORDER BY created_at DESC LIMIT 100'
    );
    $notifications->execute([$user['user_id']]);

    $response = [
        'success' => true,
        'records' => $rows,
        'notifications' => $notifications->fetchAll(PDO::FETCH_ASSOC),
    ];
    if ($user['role'] === 'student') {
        $lecturers = $pdo->query(
            "SELECT user_id, full_name
             FROM users
             WHERE status = 'Active' AND role = 'lecturer'
               AND email IS NOT NULL AND email <> ''
             ORDER BY full_name"
        );
        $response['lecturers'] = $lecturers->fetchAll(PDO::FETCH_ASSOC);
    }
    jsonResponse($response);
}

if ($method !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}
verifyCsrf();
$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    jsonResponse(['success' => false, 'message' => 'Invalid request.'], 400);
}

$action = (string)($input['action'] ?? '');
if ($action === 'create') {
    $type = $input['type'] ?? null;
    $payload = $input['payload'] ?? null;
    if (
        !is_string($type) ||
        !in_array($type, ['achievement', 'recommendation', 'attachment', 'support', 'opportunity'], true) ||
        !is_array($payload)
    ) {
        jsonResponse(['success' => false, 'message' => 'Invalid record.'], 422);
    }
    if ($user['role'] !== 'student' && !($type === 'opportunity' && in_array($user['role'], ['admin', 'hod', 'registry', 'career_advisor'], true))) {
        jsonResponse(['success' => false, 'message' => 'Only authorised accounts can submit this record.'], 403);
    }

    if ($type === 'achievement') {
        $category = requiredText($payload['type'] ?? null, 32);
        $year = requiredText($payload['year'] ?? null, 32);
        $title = requiredText($payload['title'] ?? null, 100);
        $detail = requiredText($payload['detail'] ?? null, 4000);
        $evidence = $payload['evidence'] ?? '';
        if (!is_string($evidence) || mb_strlen(trim($evidence), 'UTF-8') > 500) {
            jsonResponse(['success' => false, 'message' => 'Invalid achievement evidence reference.'], 422);
        }
        if (
            !in_array($category, ['Achievement', 'Leadership', 'Activity', 'Service'], true) ||
            !in_array($year, ['Year 1', 'Year 2', 'Year 3', 'Year 4', 'Year 5'], true) ||
            $title === null || $detail === null
        ) {
            jsonResponse(['success' => false, 'message' => 'Complete the achievement category, year, title and description.'], 422);
        }
        $payload = [
            'type' => $category,
            'year' => $year,
            'title' => $title,
            'detail' => $detail,
            'evidence' => trim($evidence),
            'share_activity' => ($payload['share_activity'] ?? false) === true,
        ];
    } elseif ($type === 'recommendation') {
        $lecturerId = requiredText($payload['lecturer_user_id'] ?? null, 64);
        $purpose = requiredText($payload['purpose'] ?? null, 120);
        $context = $payload['context'] ?? '';
        if ($lecturerId === null || $purpose === null || !is_string($context) || mb_strlen(trim($context), 'UTF-8') > 4000) {
            jsonResponse(['success' => false, 'message' => 'Select a lecturer and provide a valid recommendation purpose.'], 422);
        }
        $lecturerQuery = $pdo->prepare(
            "SELECT user_id, full_name, email FROM users
             WHERE user_id = ? AND role = 'lecturer' AND status = 'Active'
               AND email IS NOT NULL AND email <> ''
             LIMIT 1"
        );
        $lecturerQuery->execute([$lecturerId]);
        $lecturer = $lecturerQuery->fetch(PDO::FETCH_ASSOC);
        if (!$lecturer || !filter_var($lecturer['email'], FILTER_VALIDATE_EMAIL)) {
            jsonResponse(['success' => false, 'message' => 'The selected lecturer is not available for recommendation requests.'], 422);
        }
        $payload = [
            'lecturer_user_id' => $lecturer['user_id'],
            'lecturer_name' => $lecturer['full_name'],
            'to' => $lecturer['full_name'],
            'purpose' => $purpose,
            'context' => trim($context),
        ];
    } elseif ($type === 'opportunity') {
        $title = requiredText($payload['title'] ?? null, 180);
        $organisation = requiredText($payload['organisation'] ?? null, 180);
        $description = requiredText($payload['description'] ?? null, 12000);
        $eligibility = requiredText($payload['eligibility'] ?? null, 4000);
        $category = requiredText($payload['category'] ?? null, 64);
        $location = requiredText($payload['location'] ?? '', 180);
        $link = requiredText($payload['application_url'] ?? null, 2048);
        $deadline = $payload['deadline'] ?? null;
        if (
            $title === null || $organisation === null || $description === null ||
            $eligibility === null || $location === null ||
            !in_array($category, ['Internship', 'Scholarship', 'Employment', 'Graduate programme', 'Volunteering', 'Competition'], true) ||
            !is_string($link) || !filter_var($link, FILTER_VALIDATE_URL) ||
            !in_array(strtolower((string)parse_url($link, PHP_URL_SCHEME)), ['http', 'https'], true) ||
            !is_string($deadline) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline) ||
            !checkdate((int)substr($deadline, 5, 2), (int)substr($deadline, 8, 2), (int)substr($deadline, 0, 4))
        ) {
            jsonResponse(['success' => false, 'message' => 'Complete all required opportunity details and enter a valid application link and closing date.'], 422);
        }
        $payload = [
            'title' => $title,
            'organisation' => $organisation,
            'description' => $description,
            'eligibility' => $eligibility,
            'category' => $category,
            'location' => $location,
            'application_url' => $link,
            'deadline' => $deadline,
        ];
    }

    $assignedRole = [
        'achievement' => 'verifier',
        'recommendation' => 'lecturer',
        'attachment' => 'supervisor',
        'support' => 'chaplaincy',
        'opportunity' => 'student',
    ][$type];
    $status = $type === 'opportunity' ? 'Published' : 'Pending';
    $recipients = [];
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'INSERT INTO portal_records
                (record_type, owner_user_id, owner_name, payload, status, assigned_role)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $type,
            $user['user_id'],
            $user['full_name'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $status,
            $assignedRole,
        ]);
        $recordId = (int)$pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO portal_audit(actor_user_id, action, record_type, record_id, details)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $user['user_id'],
            'created',
            $type,
            $recordId,
            json_encode(['status' => $status], JSON_THROW_ON_ERROR),
        ]);

        if ($type === 'recommendation') {
            $recipients = [$lecturer];
            addWorkflowNotification(
                $pdo,
                (string)$lecturer['user_id'],
                'New recommendation request',
                $user['full_name'] . ' requested a recommendation for ' . $payload['purpose'] . '.',
                $type,
                $recordId,
                'submitted'
            );
        } elseif ($type === 'achievement') {
            $recipients = usersForRoles($pdo, allowedRolesForType($type));
            foreach ($recipients as $recipient) {
                addWorkflowNotification(
                    $pdo,
                    (string)$recipient['user_id'],
                    'New achievement awaiting verification',
                    $user['full_name'] . ' submitted "' . $payload['title'] . '" for review.',
                    $type,
                    $recordId,
                    'submitted'
                );
            }
        } elseif ($type === 'opportunity') {
            foreach (usersForRoles($pdo, ['student', 'alumni']) as $recipient) {
                addWorkflowNotification(
                    $pdo,
                    (string)$recipient['user_id'],
                    'New career opportunity published',
                    $user['full_name'] . ' published a new opportunity.',
                    $type,
                    $recordId,
                    'published'
                );
            }
        } else {
            foreach (usersForRoles($pdo, allowedRolesForType($type)) as $recipient) {
                addWorkflowNotification(
                    $pdo,
                    (string)$recipient['user_id'],
                    'New ' . $type . ' submission',
                    $user['full_name'] . ' submitted a ' . $type . ' for review.',
                    $type,
                    $recordId,
                    'submitted'
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

    $emailsSent = 0;
    if ($type === 'achievement') {
        $emailsSent = workflowEmails(
            $recipients,
            'Achievement submitted for verification',
            $user['full_name'] . ' submitted "' . $payload['title'] . '" for verification in the AU Student Success Hub.',
            'achievement-submitted',
            $recordId
        );
    } elseif ($type === 'recommendation') {
        $emailsSent = workflowEmails(
            $recipients,
            'Recommendation request from ' . $user['full_name'],
            $user['full_name'] . ' requested a recommendation for ' . $payload['purpose'] . '.'
                . ($payload['context'] !== '' ? "\n\nAdditional context: " . $payload['context'] : ''),
            'recommendation-requested',
            $recordId
        );
    }
    jsonResponse([
        'success' => true,
        'id' => $recordId,
        'status' => $status,
        'emails_sent' => $emailsSent,
        'email_count' => in_array($type, ['achievement', 'recommendation'], true)
            ? max(1, count($recipients))
            : 0,
    ], 201);
}

if ($action === 'decide') {
    $recordId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    $status = $input['status'] ?? null;
    $comment = $input['comment'] ?? '';
    if (
        $recordId === false || $recordId === null || $recordId < 1 ||
        !is_string($status) || !is_string($comment) || mb_strlen(trim($comment), 'UTF-8') > 4000
    ) {
        jsonResponse(['success' => false, 'message' => 'Invalid decision.'], 422);
    }

    $pdo->beginTransaction();
    $statement = $pdo->prepare('SELECT * FROM portal_records WHERE id = ? FOR UPDATE');
    $statement->execute([$recordId]);
    $record = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$record) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'Record not found.'], 404);
    }
    if (!in_array($user['role'], allowedRolesForType($record['record_type']), true) && $user['role'] !== 'admin') {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'Not authorised to review this record.'], 403);
    }
    if ($record['record_type'] === 'recommendation') {
        $payload = json_decode((string)$record['payload'], true) ?: [];
        $assignedLecturer = (string)($payload['lecturer_user_id'] ?? '');
        if (
            $user['role'] === 'lecturer' &&
            ($assignedLecturer === '' || !hash_equals($assignedLecturer, (string)$user['user_id']))
        ) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Only the selected lecturer can submit this recommendation.'], 403);
        }
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'Use the recommendation submission action to issue a letter.'], 422);
    }
    $allowedStatuses = $record['record_type'] === 'achievement'
        ? ['Verified', 'Needs changes', 'Rejected']
        : ['Verified', 'Needs changes', 'Approved', 'Rejected', 'Issued', 'In progress', 'Completed'];
    if (!in_array($status, $allowedStatuses, true) || $record['status'] !== 'Pending') {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'This record cannot receive that decision.'], 422);
    }
    $comment = trim($comment);
    if ($record['record_type'] === 'achievement' && $status === 'Rejected' && $comment === '') {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'A rejection reason is required.'], 422);
    }

    $payload = json_decode((string)$record['payload'], true) ?: [];
    $payload['review_comment'] = $comment;
    $payload['reviewer'] = $user['full_name'];
    $payload['reviewed_at'] = date(DATE_ATOM);
    $pdo->prepare('UPDATE portal_records SET status = ?, payload = ? WHERE id = ?')->execute([
        $status,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        $recordId,
    ]);
    $pdo->prepare(
        'INSERT INTO portal_audit(actor_user_id, action, record_type, record_id, details)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $user['user_id'],
        'status_changed',
        $record['record_type'],
        $recordId,
        json_encode(['status' => $status, 'comment' => $comment], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
    $isAchievement = $record['record_type'] === 'achievement';
    $title = $isAchievement
        ? ($status === 'Verified' ? 'Achievement approved' : ($status === 'Rejected' ? 'Achievement rejected' : 'Achievement needs changes'))
        : 'Your ' . ucfirst($record['record_type']) . ' was updated';
    $message = $isAchievement
        ? '"' . ($payload['title'] ?? 'Achievement') . '" was ' . strtolower($status) . '.'
            . ($comment !== '' ? ' Reason or comment: ' . $comment : '')
        : 'Status: ' . $status . ($comment !== '' ? ' — ' . $comment : '');
    addWorkflowNotification(
        $pdo,
        (string)$record['owner_user_id'],
        $title,
        $message,
        (string)$record['record_type'],
        (int)$record['id'],
        'decision-' . strtolower(str_replace(' ', '-', $status))
    );
    $ownerQuery = $pdo->prepare(
        "SELECT user_id, full_name, email FROM users
         WHERE user_id = ? AND status = 'Active' LIMIT 1"
    );
    $ownerQuery->execute([$record['owner_user_id']]);
    $owner = $ownerQuery->fetch(PDO::FETCH_ASSOC);
    $pdo->commit();

    $emailsSent = 0;
    if ($isAchievement && $owner) {
        $subject = $status === 'Verified'
            ? 'Your achievement has been approved'
            : ($status === 'Rejected' ? 'Update on your achievement submission' : 'Your achievement needs changes');
        $emailMessage = '"' . ($payload['title'] ?? 'Achievement') . '" was ' . strtolower($status) . '.';
        if ($comment !== '') {
            $emailMessage .= "\n\n" . ($status === 'Rejected' ? 'Reason: ' : 'Staff comment: ') . $comment;
        }
        $emailsSent = workflowEmails([$owner], $subject, $emailMessage, 'achievement-decision', $recordId);
    }
    jsonResponse([
        'success' => true,
        'status' => $status,
        'emails_sent' => $emailsSent,
        'email_count' => $isAchievement && $owner ? 1 : 0,
    ]);
}

if ($action === 'submit_recommendation') {
    $recordId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    $body = requiredText($input['body'] ?? null, 20000);
    if ($recordId === false || $recordId === null || $recordId < 1 || $body === null) {
        jsonResponse(['success' => false, 'message' => 'Enter the recommendation letter before submitting.'], 422);
    }

    $pdo->beginTransaction();
    $statement = $pdo->prepare('SELECT * FROM portal_records WHERE id = ? FOR UPDATE');
    $statement->execute([$recordId]);
    $record = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$record || $record['record_type'] !== 'recommendation') {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'Recommendation request not found.'], 404);
    }
    $payload = json_decode((string)$record['payload'], true) ?: [];
    $assignedLecturer = (string)($payload['lecturer_user_id'] ?? '');
    if (
        $user['role'] !== 'admin' &&
        $user['role'] !== 'hod' &&
        ($user['role'] !== 'lecturer' || $assignedLecturer === '' || !hash_equals($assignedLecturer, (string)$user['user_id']))
    ) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'Only the selected lecturer or authorised HOD/administrator staff can submit this recommendation.'], 403);
    }
    if ($record['status'] !== 'Pending') {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'This recommendation has already been submitted.'], 409);
    }

    $payload['body'] = $body;
    $payload['reviewer'] = $user['full_name'];
    $payload['reviewed_at'] = date(DATE_ATOM);
    $pdo->prepare('UPDATE portal_records SET status = ?, payload = ? WHERE id = ?')->execute([
        'Issued',
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        $recordId,
    ]);
    $pdo->prepare(
        'INSERT INTO portal_audit(actor_user_id, action, record_type, record_id, details)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $user['user_id'],
        'recommendation_submitted',
        'recommendation',
        $recordId,
        json_encode(['status' => 'Issued'], JSON_THROW_ON_ERROR),
    ]);
    addWorkflowNotification(
        $pdo,
        (string)$record['owner_user_id'],
        'Your recommendation is ready',
        'Your recommendation for ' . ($payload['purpose'] ?? 'your request') . ' has been submitted by ' . $user['full_name'] . '.',
        'recommendation',
        $recordId,
        'submitted'
    );
    $ownerQuery = $pdo->prepare(
        "SELECT user_id, full_name, email FROM users
         WHERE user_id = ? AND status = 'Active' LIMIT 1"
    );
    $ownerQuery->execute([$record['owner_user_id']]);
    $owner = $ownerQuery->fetch(PDO::FETCH_ASSOC);
    $pdo->commit();

    $emailsSent = $owner
        ? workflowEmails(
            [$owner],
            'Your recommendation is ready',
            'Your recommendation for ' . ($payload['purpose'] ?? 'your request') . ' has been submitted by ' . $user['full_name'] . '.',
            'recommendation-submitted',
            $recordId
        )
        : 0;
    jsonResponse([
        'success' => true,
        'status' => 'Issued',
        'emails_sent' => $emailsSent,
        'email_count' => $owner ? 1 : 0,
    ]);
}

if ($action === 'read_notification') {
    $notificationId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if ($notificationId === false || $notificationId === null || $notificationId < 1) {
        jsonResponse(['success' => false, 'message' => 'Invalid notification.'], 422);
    }
    $statement = $pdo->prepare(
        'UPDATE portal_notifications
         SET is_read = 1
         WHERE id = ? AND recipient_user_id = ?'
    );
    $statement->execute([$notificationId, $user['user_id']]);
    jsonResponse(['success' => true]);
}

jsonResponse(['success' => false, 'message' => 'Unknown action.'], 400);
