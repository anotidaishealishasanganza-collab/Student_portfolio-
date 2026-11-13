<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/role_migrations.php';

$user = requireAuthentication();
$pdo = database();
ensureCareerAdvisorRole($pdo);

$account = $pdo->prepare(
    "SELECT user_id, full_name, role, email FROM users
     WHERE user_id = ? AND status = 'Active' LIMIT 1"
);
$account->execute([$user['user_id']]);
$activeUser = $account->fetch(PDO::FETCH_ASSOC);
if (!$activeUser || $activeUser['role'] !== $user['role']) {
    jsonResponse(['success' => false, 'message' => 'Your account is no longer authorised. Sign in again.'], 403);
}
$user = array_merge($user, $activeUser);

$staffRoles = [
    'lecturer', 'verifier', 'hod', 'registry', 'supervisor', 'chaplaincy',
    'dean', 'admin', 'career_advisor', 'career', 'careers', 'career_advising',
];
$managerRoles = ['admin', 'hod', 'dean', 'registry', 'career_advisor', 'career', 'careers', 'career_advising'];
$canReview = in_array($user['role'], $staffRoles, true);
$canPublish = $canReview;
$canPublishOpportunity = in_array(
    $user['role'],
    ['admin', 'hod', 'registry', 'career_advisor', 'career', 'careers', 'career_advising'],
    true
);

$pdo->exec("CREATE TABLE IF NOT EXISTS portal_engagement_items (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 item_type VARCHAR(24) NOT NULL,
 title VARCHAR(180) NOT NULL,
 description TEXT NOT NULL,
 category VARCHAR(64) NOT NULL,
 organisation VARCHAR(180) NULL,
 eligibility TEXT NULL,
 location VARCHAR(180) NULL,
 link_url VARCHAR(2048) NULL,
 target_role VARCHAR(32) NOT NULL DEFAULT 'student',
 due_at DATETIME NULL,
 file_key CHAR(64) NULL,
 original_name VARCHAR(255) NULL,
 mime_type VARCHAR(100) NULL,
 file_size BIGINT UNSIGNED NULL,
 download_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
 creator_user_id VARCHAR(64) NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'Published',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_type_status_due (item_type, status, due_at),
 INDEX idx_target (target_role, item_type, status),
 INDEX idx_file_key (file_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS portal_engagement_tracks (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id VARCHAR(64) NOT NULL,
 item_type VARCHAR(24) NOT NULL,
 item_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(24) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_engagement_track (user_id, item_type, item_id, action),
 INDEX idx_engagement_item (item_type, item_id, action),
 INDEX idx_engagement_user (user_id, item_type, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS portal_messages (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 sender_user_id VARCHAR(64) NOT NULL,
 recipient_user_id VARCHAR(64) NOT NULL,
 body TEXT NOT NULL,
 read_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_message_inbox (recipient_user_id, read_at, created_at),
 INDEX idx_message_thread (sender_user_id, recipient_user_id, created_at)
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
engagementEnsureNotificationDedupe($pdo);

function engagementEnsureNotificationDedupe(PDO $pdo): void
{
    $column = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_notifications'
           AND COLUMN_NAME = 'dedupe_key'"
    );
    if ((int)$column->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE portal_notifications ADD COLUMN dedupe_key VARCHAR(190) NULL');
    }
    $index = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_notifications'
           AND INDEX_NAME = 'uq_portal_notifications_dedupe'"
    );
    if ((int)$index->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE portal_notifications ADD UNIQUE KEY uq_portal_notifications_dedupe (dedupe_key)');
    }
}

function engagementNotification(PDO $pdo, string $recipient, string $title, string $message, string $category, int $id, string $event): void
{
    $statement = $pdo->prepare(
        'INSERT IGNORE INTO portal_notifications
            (recipient_user_id, title, message, record_type, record_id, dedupe_key)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        $recipient,
        $title,
        $message,
        $category,
        $id,
        $category . ':' . $event . ':' . $id . ':' . $recipient,
    ]);
}

function engagementText(mixed $value, int $limit, bool $required = false): ?string
{
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }
    $text = trim((string)$value);
    if (($required && $text === '') || mb_strlen($text, 'UTF-8') > $limit) {
        return null;
    }
    return $text;
}

function engagementDate(mixed $value): string|false|null
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_string($value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
        return false;
    }
    return $date->format('Y-m-d H:i:s');
}

function engagementStaffCanEdit(array $user, array $managerRoles): bool
{
    return in_array($user['role'], $managerRoles, true);
}

function engagementNotifyUsers(PDO $pdo, array $roles, string $title, string $message, string $category, int $id, string $event): void
{
    if (!$roles) {
        return;
    }
    $marks = implode(',', array_fill(0, count($roles), '?'));
    $query = $pdo->prepare(
        "SELECT user_id FROM users WHERE status = 'Active' AND role IN ($marks)"
    );
    $query->execute($roles);
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $recipientId) {
        engagementNotification($pdo, (string)$recipientId, $title, $message, $category, $id, $event);
    }
}

function engagementMail(array $recipient, string $subject, string $message, string $context, int $id): bool
{
    if (empty($recipient['email']) || !filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    try {
        sendWorkflowEmail((string)$recipient['email'], (string)$recipient['full_name'], $subject, $message);
        return true;
    } catch (Throwable $error) {
        error_log('AUSSH engagement email delivery failed (' . $context . ', item ' . $id . ').');
        return false;
    }
}

function engagementItemPayload(array $input, string $type): array|false
{
    $title = engagementText($input['title'] ?? null, 180, true);
    $description = engagementText($input['description'] ?? null, 12000, true);
    $category = engagementText($input['category'] ?? null, 64, true);
    $dueAt = engagementDate($input['due_at'] ?? null);
    if ($title === null || $description === null || $category === null || $dueAt === false ||
        ($type === 'deadline' && $dueAt === null)) {
        return false;
    }
    $allowedCategories = [
        'deadline' => ['Academic deadline', 'Application deadline', 'Workshop', 'Seminar', 'Event', 'Submission reminder'],
        'announcement' => ['Announcement', 'Event', 'Success story'],
    ];
    if (!in_array($category, $allowedCategories[$type] ?? [], true)) {
        return false;
    }
    $targetRole = engagementText($input['target_role'] ?? 'student', 32, true);
    if (!in_array($targetRole, ['student', 'alumni', 'all'], true)) {
        return false;
    }
    return [
        'title' => $title,
        'description' => $description,
        'category' => $category,
        'organisation' => engagementText($input['organisation'] ?? null, 180) ?? '',
        'eligibility' => engagementText($input['eligibility'] ?? null, 4000) ?? '',
        'location' => engagementText($input['location'] ?? null, 180) ?? '',
        'link_url' => engagementText($input['link_url'] ?? null, 2048) ?? '',
        'target_role' => $targetRole,
        'due_at' => $dueAt,
    ];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    $audience = $user['role'] === 'alumni' ? 'alumni' : 'student';
    $items = $pdo->prepare(
        "SELECT i.*,
            EXISTS(SELECT 1 FROM portal_engagement_tracks t
                   WHERE t.user_id = ? AND t.item_type = i.item_type AND t.item_id = i.id
                     AND t.action = 'saved') AS is_saved,
            EXISTS(SELECT 1 FROM portal_engagement_tracks t
                   WHERE t.user_id = ? AND t.item_type = i.item_type AND t.item_id = i.id
                     AND t.action = 'applied') AS is_applied,
            EXISTS(SELECT 1 FROM portal_engagement_tracks t
                   WHERE t.user_id = ? AND t.item_type = i.item_type AND t.item_id = i.id
                     AND t.action = 'tracked') AS is_tracked,
            EXISTS(SELECT 1 FROM portal_engagement_tracks t
                   WHERE t.user_id = ? AND t.item_type = i.item_type AND t.item_id = i.id
                     AND t.action = 'completed') AS is_completed,
            (SELECT COUNT(*) FROM portal_engagement_tracks t
             WHERE t.item_type = i.item_type AND t.item_id = i.id AND t.action = 'applied') AS application_count
         FROM portal_engagement_items i
         WHERE i.status = 'Published'
           AND (i.target_role = 'all' OR i.target_role = ?
                OR ? NOT IN ('student','alumni'))
         ORDER BY COALESCE(i.due_at, i.created_at) ASC LIMIT 500"
    );
    $itemArguments = array_fill(0, 4, $user['user_id']);
    $itemArguments[] = $audience;
    $itemArguments[] = $user['role'];
    $items->execute($itemArguments);
    $publishedItems = $items->fetchAll(PDO::FETCH_ASSOC);
    foreach ($publishedItems as &$item) {
        $item['id'] = (int)$item['id'];
        $item['is_saved'] = (bool)$item['is_saved'];
        $item['is_applied'] = (bool)$item['is_applied'];
        $item['is_tracked'] = (bool)$item['is_tracked'];
        $item['is_completed'] = (bool)$item['is_completed'];
        $item['application_count'] = (int)$item['application_count'];
    }
    unset($item);

    $dueSoon = $pdo->prepare(
        "SELECT i.id, i.title, i.due_at, t.user_id
         FROM portal_engagement_items i
         INNER JOIN portal_engagement_tracks t
           ON t.item_type = 'deadline' AND t.item_id = i.id AND t.action = 'tracked'
         LEFT JOIN portal_engagement_tracks done
           ON done.user_id = t.user_id AND done.item_type = t.item_type
          AND done.item_id = t.item_id AND done.action = 'completed'
         WHERE i.item_type = 'deadline' AND i.status = 'Published'
           AND i.due_at BETWEEN CURRENT_TIMESTAMP AND DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 3 DAY)
           AND (i.target_role = 'all' OR i.target_role = ?)
           AND done.id IS NULL"
    );
    $dueSoon->execute([$audience]);
    foreach ($dueSoon->fetchAll(PDO::FETCH_ASSOC) as $deadline) {
        engagementNotification(
            $pdo,
            (string)$deadline['user_id'],
            'Deadline approaching',
            $deadline['title'] . ' is due ' . date('M j, Y', strtotime($deadline['due_at'])) . '.',
            'deadline',
            (int)$deadline['id'],
            'reminder-' . date('Y-m-d')
        );
    }

    $opportunityQuery = $pdo->prepare(
        "SELECT r.id, r.owner_user_id, r.owner_name, r.status, r.created_at, r.payload,
            EXISTS(SELECT 1 FROM portal_engagement_tracks t
                   WHERE t.user_id = ? AND t.item_type = 'opportunity'
                     AND t.item_id = r.id AND t.action = 'saved') AS is_saved,
            EXISTS(SELECT 1 FROM portal_engagement_tracks t
                   WHERE t.user_id = ? AND t.item_type = 'opportunity'
                     AND t.item_id = r.id AND t.action = 'applied') AS is_applied,
            (SELECT COUNT(*) FROM portal_engagement_tracks t
             WHERE t.item_type = 'opportunity' AND t.item_id = r.id AND t.action = 'applied') AS application_count
         FROM portal_records r
         WHERE r.record_type = 'opportunity' AND r.status = 'Published'
         ORDER BY r.created_at DESC LIMIT 300"
    );
    $opportunityQuery->execute([$user['user_id'], $user['user_id']]);
    $opportunities = $opportunityQuery->fetchAll(PDO::FETCH_ASSOC);
    foreach ($opportunities as &$opportunity) {
        $opportunity['payload'] = json_decode((string)$opportunity['payload'], true) ?: [];
        $opportunity['id'] = (int)$opportunity['id'];
        $opportunity['is_saved'] = (bool)$opportunity['is_saved'];
        $opportunity['is_applied'] = (bool)$opportunity['is_applied'];
        $opportunity['application_count'] = (int)$opportunity['application_count'];
    }
    unset($opportunity);

    $messageQuery = $pdo->prepare(
        "SELECT m.id, m.sender_user_id, s.full_name AS sender_name,
                m.recipient_user_id, r.full_name AS recipient_name,
                m.body, m.read_at, m.created_at
         FROM portal_messages m
         INNER JOIN users s ON s.user_id = m.sender_user_id
         INNER JOIN users r ON r.user_id = m.recipient_user_id
         WHERE m.sender_user_id = ? OR m.recipient_user_id = ?
         ORDER BY m.created_at DESC LIMIT 200"
    );
    $messageQuery->execute([$user['user_id'], $user['user_id']]);
    $messages = $messageQuery->fetchAll(PDO::FETCH_ASSOC);

    $contacts = [];
    if ($user['role'] === 'student' || $user['role'] === 'alumni') {
        $roles = $staffRoles;
        $marks = implode(',', array_fill(0, count($roles), '?'));
        $contactQuery = $pdo->prepare(
            "SELECT user_id, full_name, role FROM users
             WHERE status = 'Active' AND role IN ($marks) ORDER BY full_name LIMIT 300"
        );
        $contactQuery->execute($roles);
        $contacts = $contactQuery->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($canReview) {
        $contactQuery = $pdo->query(
            "SELECT user_id, full_name, role FROM users
             WHERE status = 'Active' AND role IN ('student','alumni')
             ORDER BY full_name LIMIT 500"
        );
        $contacts = $contactQuery->fetchAll(PDO::FETCH_ASSOC);
    }

    $feedQuery = $pdo->query(
        "SELECT r.id, r.owner_name, r.created_at, r.payload, 'achievement' AS feed_type,
                'Achievement' AS category
         FROM portal_records r
         WHERE r.record_type = 'achievement' AND r.status = 'Verified'
           AND JSON_UNQUOTE(JSON_EXTRACT(r.payload, '$.share_activity')) = 'true'
         UNION ALL
         SELECT i.id, u.full_name AS owner_name, i.created_at,
                JSON_OBJECT('title',i.title,'description',i.description,'location',i.location) AS payload,
                i.item_type AS feed_type, i.category
         FROM portal_engagement_items i
         INNER JOIN users u ON u.user_id = i.creator_user_id
         WHERE i.item_type = 'announcement' AND i.status = 'Published'
           AND (i.target_role = 'all' OR i.target_role = 'student')
         UNION ALL
         SELECT i.id, u.full_name AS owner_name, i.created_at,
                JSON_OBJECT('title',i.title,'description',i.description,'location',i.location) AS payload,
                'event' AS feed_type, i.category
         FROM portal_engagement_items i
         INNER JOIN users u ON u.user_id = i.creator_user_id
         WHERE i.item_type = 'deadline' AND i.category IN ('Workshop','Seminar','Event')
           AND i.status = 'Published' AND (i.target_role = 'all' OR i.target_role = 'student')
         ORDER BY created_at DESC LIMIT 100"
    );
    $feed = $feedQuery->fetchAll(PDO::FETCH_ASSOC);
    foreach ($feed as &$feedItem) {
        $feedItem['payload'] = json_decode((string)$feedItem['payload'], true) ?: [];
    }
    unset($feedItem);

    $resourceQuery = $pdo->prepare(
        "SELECT id, title, description, category, original_name, mime_type,
                file_size, download_count, creator_user_id, created_at
         FROM portal_engagement_items
         WHERE item_type = 'resource' AND status = 'Published'
           AND (target_role = 'all' OR target_role = ?)
         ORDER BY created_at DESC LIMIT 300"
    );
    $resourceQuery->execute([$audience]);

    $notificationsQuery = $pdo->prepare(
        'SELECT id, title, message, record_type, record_id, is_read, created_at
         FROM portal_notifications WHERE recipient_user_id = ?
         ORDER BY created_at DESC LIMIT 100'
    );
    $notificationsQuery->execute([$user['user_id']]);
    $response = [
        'success' => true,
        'items' => $publishedItems,
        'opportunities' => $opportunities,
        'resources' => $resourceQuery->fetchAll(PDO::FETCH_ASSOC),
        'messages' => $messages,
        'contacts' => $contacts,
        'feed' => $feed,
        'notifications' => $notificationsQuery->fetchAll(PDO::FETCH_ASSOC),
        'permissions' => [
            'can_publish' => $canPublish,
            'can_message' => $canReview || in_array($user['role'], ['student', 'alumni'], true),
        ],
    ];
    jsonResponse($response);
}

if ($method !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}
verifyCsrf();

$action = (string)($_POST['action'] ?? '');
$jsonInput = null;
if ($action === '') {
    $jsonInput = json_decode((string)file_get_contents('php://input'), true);
    $action = is_array($jsonInput) ? (string)($jsonInput['action'] ?? '') : '';
}
$input = is_array($jsonInput) ? $jsonInput : $_POST;

if ($action === 'save_item') {
    if (!$canPublish) {
        jsonResponse(['success' => false, 'message' => 'Only authorised staff can publish deadlines and announcements.'], 403);
    }
    $type = $input['item_type'] ?? '';
    if (!in_array($type, ['deadline', 'announcement'], true)) {
        jsonResponse(['success' => false, 'message' => 'Invalid engagement item type.'], 422);
    }
    $data = engagementItemPayload($input, $type);
    if ($data === false) {
        jsonResponse(['success' => false, 'message' => 'Check the title, description, category, audience and date.'], 422);
    }
    $id = isset($input['id']) ? filter_var($input['id'], FILTER_VALIDATE_INT) : 0;
    if ($id === false) {
        jsonResponse(['success' => false, 'message' => 'Invalid item ID.'], 422);
    }
    if ($id) {
        $find = $pdo->prepare('SELECT creator_user_id, item_type FROM portal_engagement_items WHERE id = ?');
        $find->execute([$id]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);
        if (!$existing || $existing['item_type'] !== $type || !$canPublish) {
            jsonResponse(['success' => false, 'message' => 'Item not found or not authorised.'], 404);
        }
        $update = $pdo->prepare(
            'UPDATE portal_engagement_items
             SET title=?, description=?, category=?, organisation=?, eligibility=?, location=?,
                 link_url=?, target_role=?, due_at=?
             WHERE id=? AND item_type=?'
        );
        $update->execute([
            $data['title'], $data['description'], $data['category'], $data['organisation'],
            $data['eligibility'], $data['location'], $data['link_url'], $data['target_role'],
            $data['due_at'], $id, $type,
        ]);
        jsonResponse(['success' => true, 'id' => $id]);
    }
    $insert = $pdo->prepare(
        'INSERT INTO portal_engagement_items
            (item_type,title,description,category,organisation,eligibility,location,link_url,target_role,due_at,creator_user_id)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    $insert->execute([
        $type, $data['title'], $data['description'], $data['category'], $data['organisation'],
        $data['eligibility'], $data['location'], $data['link_url'], $data['target_role'],
        $data['due_at'], $user['user_id'],
    ]);
    $id = (int)$pdo->lastInsertId();
    $audienceRoles = $data['target_role'] === 'all'
        ? ['student', 'alumni']
        : [$data['target_role']];
    engagementNotifyUsers(
        $pdo,
        $audienceRoles,
        $type === 'deadline' ? 'New deadline or event' : 'New AU Student Success Hub announcement',
        $data['title'] . ' — ' . $data['description'],
        $type,
        $id,
        'published'
    );
    jsonResponse(['success' => true, 'id' => $id], 201);
}

if ($action === 'delete_item') {
    if (!$canPublish) {
        jsonResponse(['success' => false, 'message' => 'Only authorised staff can remove items.'], 403);
    }
    $id = isset($input['id']) ? filter_var($input['id'], FILTER_VALIDATE_INT) : 0;
    if (!$id || $id < 1) {
        jsonResponse(['success' => false, 'message' => 'Invalid item ID.'], 422);
    }
    $query = $pdo->prepare('SELECT item_type, file_key FROM portal_engagement_items WHERE id = ?');
    $query->execute([$id]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        jsonResponse(['success' => false, 'message' => 'Item not found.'], 404);
    }
    if (!in_array($row['item_type'], ['deadline', 'announcement', 'resource'], true)) {
        jsonResponse(['success' => false, 'message' => 'This item cannot be removed here.'], 422);
    }
    $pdo->prepare('DELETE FROM portal_engagement_items WHERE id = ?')->execute([$id]);
    if ($row['file_key']) {
        $root = getenv('USERPROFILE') . DIRECTORY_SEPARATOR . 'aussh_private' . DIRECTORY_SEPARATOR . 'resources';
        $path = $root . DIRECTORY_SEPARATOR . $row['file_key'];
        if (is_file($path)) {
            unlink($path);
        }
    }
    jsonResponse(['success' => true]);
}

if ($action === 'save_opportunity') {
    if (!$canPublishOpportunity) {
        jsonResponse(['success' => false, 'message' => 'Only authorised Career Advising and university staff can publish opportunities.'], 403);
    }
    $id = isset($input['id']) ? filter_var($input['id'], FILTER_VALIDATE_INT) : 0;
    if ($id === false) {
        jsonResponse(['success' => false, 'message' => 'Invalid opportunity ID.'], 422);
    }
    $title = engagementText($input['title'] ?? null, 180, true);
    $organisation = engagementText($input['organisation'] ?? null, 180, true);
    $description = engagementText($input['description'] ?? null, 12000, true);
    $eligibility = engagementText($input['eligibility'] ?? null, 4000, true);
    $category = engagementText($input['category'] ?? null, 64, true);
    $location = engagementText($input['location'] ?? null, 180);
    $link = engagementText($input['link_url'] ?? null, 2048);
    $closingDate = $input['closing_date'] ?? null;
    $categories = ['Internship', 'Scholarship', 'Employment', 'Graduate programme', 'Volunteering', 'Competition'];
    $validLink = $link !== null && $link !== ''
        && filter_var($link, FILTER_VALIDATE_URL)
        && in_array(strtolower((string)parse_url($link, PHP_URL_SCHEME)), ['http', 'https'], true);
    if (
        $title === null || $organisation === null || $description === null ||
        $eligibility === null || !in_array($category, $categories, true) ||
        $location === null || $link === null || !$validLink ||
        !is_string($closingDate) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $closingDate) ||
        !checkdate((int)substr($closingDate, 5, 2), (int)substr($closingDate, 8, 2), (int)substr($closingDate, 0, 4))
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
        'deadline' => $closingDate,
    ];
    if ($id) {
        $existing = $pdo->prepare(
            "SELECT id, owner_user_id FROM portal_records
             WHERE id = ? AND record_type = 'opportunity'"
        );
        $existing->execute([$id]);
        $record = $existing->fetch(PDO::FETCH_ASSOC);
        if (!$record) {
            jsonResponse(['success' => false, 'message' => 'Opportunity not found.'], 404);
        }
        $pdo->prepare('UPDATE portal_records SET payload = ? WHERE id = ?')->execute([
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $id,
        ]);
        jsonResponse(['success' => true, 'id' => $id]);
    }
    $insert = $pdo->prepare(
        "INSERT INTO portal_records
            (record_type,owner_user_id,owner_name,payload,status,assigned_role)
         VALUES ('opportunity',?,?,?,'Published','student')"
    );
    $insert->execute([
        $user['user_id'],
        $user['full_name'],
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
    $id = (int)$pdo->lastInsertId();
    engagementNotifyUsers(
        $pdo,
        ['student', 'alumni'],
        'New opportunity published',
        $title . ' at ' . $organisation . ' closes ' . $closingDate . '.',
        'opportunity',
        $id,
        'published'
    );
    jsonResponse(['success' => true, 'id' => $id], 201);
}

if ($action === 'delete_opportunity') {
    if (!$canPublishOpportunity) {
        jsonResponse(['success' => false, 'message' => 'Only authorised staff can remove opportunities.'], 403);
    }
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        jsonResponse(['success' => false, 'message' => 'Invalid opportunity ID.'], 422);
    }
    $delete = $pdo->prepare(
        "DELETE FROM portal_records WHERE id = ? AND record_type = 'opportunity'"
    );
    $delete->execute([$id]);
    jsonResponse(['success' => true, 'deleted' => $delete->rowCount() > 0]);
}

if ($action === 'track') {
    $type = $input['item_type'] ?? '';
    $trackAction = $input['track_action'] ?? '';
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if (
        !is_string($type) || !in_array($type, ['deadline', 'opportunity'], true) ||
        !is_string($trackAction) || !in_array($trackAction, ['tracked', 'untrack', 'completed', 'saved', 'unsave', 'applied'], true) ||
        !$id || $id < 1 || !in_array($user['role'], ['student', 'alumni'], true)
    ) {
        jsonResponse(['success' => false, 'message' => 'Invalid tracking request.'], 422);
    }
    if (($type === 'deadline' && !in_array($trackAction, ['tracked', 'untrack', 'completed'], true)) ||
        ($type === 'opportunity' && !in_array($trackAction, ['saved', 'unsave', 'applied'], true))) {
        jsonResponse(['success' => false, 'message' => 'This action is not available for that item.'], 422);
    }
    if ($type === 'deadline') {
        $itemQuery = $pdo->prepare(
            "SELECT id, title, due_at FROM portal_engagement_items
             WHERE id = ? AND item_type = 'deadline' AND status = 'Published'
               AND (target_role = 'all' OR target_role = ?)"
        );
        $itemQuery->execute([$id, $user['role']]);
        $trackedItem = $itemQuery->fetch(PDO::FETCH_ASSOC);
    } else {
        $itemQuery = $pdo->prepare(
            "SELECT id, owner_user_id, payload FROM portal_records
             WHERE id = ? AND record_type = 'opportunity' AND status = 'Published'"
        );
        $itemQuery->execute([$id]);
        $trackedItem = $itemQuery->fetch(PDO::FETCH_ASSOC);
        if ($trackedItem) {
            $trackedItem['payload'] = json_decode((string)$trackedItem['payload'], true) ?: [];
        }
    }
    if (!$trackedItem) {
        jsonResponse(['success' => false, 'message' => 'Published item not found.'], 404);
    }
    if (
        $type === 'opportunity' &&
        $trackAction === 'applied' &&
        !empty($trackedItem['payload']['deadline']) &&
        $trackedItem['payload']['deadline'] < date('Y-m-d')
    ) {
        jsonResponse(['success' => false, 'message' => 'The application closing date has passed.'], 422);
    }
    if ($trackAction === 'untrack' || $trackAction === 'unsave') {
        $insert = $pdo->prepare(
            'DELETE FROM portal_engagement_tracks
             WHERE user_id = ? AND item_type = ? AND item_id = ? AND action = ?'
        );
        $insert->execute([$user['user_id'], $type, $id, $trackAction === 'untrack' ? 'tracked' : 'saved']);
    } else {
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO portal_engagement_tracks (user_id,item_type,item_id,action) VALUES (?,?,?,?)'
        );
        $insert->execute([$user['user_id'], $type, $id, $trackAction]);
    }
    if ($type === 'opportunity' && $trackAction === 'applied' && $insert->rowCount() > 0) {
        $owner = $pdo->prepare(
            "SELECT user_id FROM users WHERE user_id = ? AND status = 'Active' LIMIT 1"
        );
        $owner->execute([$trackedItem['owner_user_id']]);
        $ownerId = $owner->fetchColumn();
        if ($ownerId) {
            engagementNotification(
                $pdo,
                (string)$ownerId,
                'A student marked an opportunity as applied',
                'A student marked "' . ($trackedItem['payload']['title'] ?? 'an opportunity') . '" as applied.',
                'opportunity',
                $id,
                'application'
            );
        }
    }
    jsonResponse([
        'success' => true,
        'already_recorded' => !in_array($trackAction, ['untrack', 'unsave'], true) && $insert->rowCount() === 0,
    ]);
}

if ($action === 'read_all_notifications') {
    $update = $pdo->prepare(
        'UPDATE portal_notifications SET is_read = 1
         WHERE recipient_user_id = ? AND is_read = 0'
    );
    $update->execute([$user['user_id']]);
    jsonResponse(['success' => true, 'updated' => $update->rowCount()]);
}

if ($action === 'send_message') {
    if (!in_array($user['role'], array_merge($staffRoles, ['student', 'alumni']), true)) {
        jsonResponse(['success' => false, 'message' => 'Messaging is not available for this account.'], 403);
    }
    $recipientId = engagementText($input['recipient_user_id'] ?? null, 64, true);
    $body = engagementText($input['body'] ?? null, 5000, true);
    if ($recipientId === null || $body === null || hash_equals($recipientId, (string)$user['user_id'])) {
        jsonResponse(['success' => false, 'message' => 'Enter a valid recipient and message.'], 422);
    }
    $recipientQuery = $pdo->prepare(
        "SELECT user_id, full_name, role, email FROM users
         WHERE user_id = ? AND status = 'Active' LIMIT 1"
    );
    $recipientQuery->execute([$recipientId]);
    $recipient = $recipientQuery->fetch(PDO::FETCH_ASSOC);
    $recipientAllowed = $recipient && (
        (in_array($user['role'], ['student', 'alumni'], true) && in_array($recipient['role'], array_merge($staffRoles, ['career_officer']), true)) ||
        ($canReview && in_array($recipient['role'], ['student', 'alumni'], true))
    );
    if (!$recipientAllowed) {
        jsonResponse(['success' => false, 'message' => 'You cannot message this account.'], 403);
    }
    $pdo->beginTransaction();
    $insert = $pdo->prepare(
        'INSERT INTO portal_messages (sender_user_id,recipient_user_id,body) VALUES (?,?,?)'
    );
    $insert->execute([$user['user_id'], $recipientId, $body]);
    $id = (int)$pdo->lastInsertId();
    engagementNotification(
        $pdo,
        $recipientId,
        'New message from ' . $user['full_name'],
        mb_substr($body, 0, 240, 'UTF-8'),
        'message',
        $id,
        'received'
    );
    $pdo->commit();
    $emailSent = engagementMail(
        $recipient,
        'New AU Student Success Hub message',
        'You have a new message from ' . $user['full_name'] . ".\n\n"
            . mb_substr($body, 0, 500) . "\n\nSign in to the AU Student Success Hub to reply.",
        'message',
        $id
    );
    jsonResponse(['success' => true, 'id' => $id, 'email_sent' => $emailSent], 201);
}

if ($action === 'read_message') {
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        jsonResponse(['success' => false, 'message' => 'Invalid message.'], 422);
    }
    $update = $pdo->prepare(
        'UPDATE portal_messages SET read_at = CURRENT_TIMESTAMP
         WHERE id = ? AND recipient_user_id = ? AND read_at IS NULL'
    );
    $update->execute([$id, $user['user_id']]);
    jsonResponse(['success' => true]);
}

if ($action === 'update_resource') {
    if (!$canPublish) {
        jsonResponse(['success' => false, 'message' => 'Only authorised staff can update resources.'], 403);
    }
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    $title = engagementText($input['title'] ?? null, 180, true);
    $description = engagementText($input['description'] ?? null, 4000, true);
    $category = engagementText($input['category'] ?? null, 64, true);
    $allowedCategories = [
        'CV template', 'Cover letter template', 'Career guide',
        'Academic resource', 'Application guide', 'University document',
    ];
    if (!$id || $id < 1 || $title === null || $description === null || !in_array($category, $allowedCategories, true)) {
        jsonResponse(['success' => false, 'message' => 'Enter valid resource details.'], 422);
    }
    $update = $pdo->prepare(
        "UPDATE portal_engagement_items
         SET title = ?, description = ?, category = ?
         WHERE id = ? AND item_type = 'resource' AND status = 'Published'"
    );
    $update->execute([$title, $description, $category, $id]);
    jsonResponse(['success' => true, 'updated' => $update->rowCount() > 0]);
}

if ($action === 'upload_resource') {
    if (!$canPublish) {
        jsonResponse(['success' => false, 'message' => 'Only authorised staff can upload resources.'], 403);
    }
    $title = engagementText($input['title'] ?? null, 180, true);
    $description = engagementText($input['description'] ?? null, 4000, true);
    $category = engagementText($input['category'] ?? null, 64, true);
    $allowedCategories = [
        'CV template', 'Cover letter template', 'Career guide',
        'Academic resource', 'Application guide', 'University document',
    ];
    if ($title === null || $description === null || !in_array($category, $allowedCategories, true)) {
        jsonResponse(['success' => false, 'message' => 'Enter a valid title, description and resource category.'], 422);
    }
    if (!isset($_FILES['file']) || !is_array($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['success' => false, 'message' => 'Choose a valid resource file to upload.'], 422);
    }
    $file = $_FILES['file'];
    if ($file['size'] < 1 || $file['size'] > 10485760 || !is_uploaded_file($file['tmp_name'])) {
        jsonResponse(['success' => false, 'message' => 'Resource files must be between 1 byte and 10 MB.'], 422);
    }
    $originalName = basename(str_replace('\\', '/', (string)$file['name']));
    if (mb_strlen($originalName, 'UTF-8') > 255) {
        jsonResponse(['success' => false, 'message' => 'The file name is too long.'], 422);
    }
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = [
        'pdf' => ['application/pdf'],
        'docx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'pptx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'txt' => ['text/plain'],
    ];
    if (!isset($allowed[$extension]) || !in_array($mime, $allowed[$extension], true)) {
        jsonResponse(['success' => false, 'message' => 'Allowed resources: PDF, DOCX, XLSX, PPTX and TXT files only.'], 422);
    }
    $profile = getenv('USERPROFILE');
    if (!is_string($profile) || $profile === '') {
        jsonResponse(['success' => false, 'message' => 'Private resource storage is not configured.'], 500);
    }
    $storage = $profile . DIRECTORY_SEPARATOR . 'aussh_private' . DIRECTORY_SEPARATOR . 'resources';
    if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) {
        jsonResponse(['success' => false, 'message' => 'Private resource storage is unavailable.'], 500);
    }
    $fileKey = bin2hex(random_bytes(32));
    $storedPath = $storage . DIRECTORY_SEPARATOR . $fileKey;
    if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
        jsonResponse(['success' => false, 'message' => 'The resource file could not be saved.'], 500);
    }
    chmod($storedPath, 0600);
    try {
        $insert = $pdo->prepare(
            "INSERT INTO portal_engagement_items
                (item_type,title,description,category,target_role,file_key,original_name,mime_type,file_size,creator_user_id)
             VALUES ('resource',?,?,?,'all',?,?,?,?,?)"
        );
        $insert->execute([
            $title, $description, $category, $fileKey, $originalName,
            $mime, (int)$file['size'], $user['user_id'],
        ]);
    } catch (Throwable $error) {
        if (is_file($storedPath)) {
            unlink($storedPath);
        }
        throw $error;
    }
    jsonResponse(['success' => true, 'id' => (int)$pdo->lastInsertId()], 201);
}

if ($action === 'analytics') {
    if (!$canReview) {
        jsonResponse(['success' => false, 'message' => 'Analytics are available to authorised staff only.'], 403);
    }
    $metrics = [];
    if (in_array($user['role'], $managerRoles, true)) {
        $metrics['registered_students'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM users WHERE role = 'student'"
        )->fetchColumn();
        $metrics['active_students'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'Active'"
        )->fetchColumn();
        $metrics['achievement_submissions'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM portal_records WHERE record_type = 'achievement'"
        )->fetchColumn();
        $metrics['pending_achievements'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM portal_records WHERE record_type = 'achievement' AND status = 'Pending'"
        )->fetchColumn();
        $metrics['approved_achievements'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM portal_records WHERE record_type = 'achievement' AND status = 'Verified'"
        )->fetchColumn();
        $metrics['rejected_achievements'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM portal_records WHERE record_type = 'achievement' AND status = 'Rejected'"
        )->fetchColumn();
        $metrics['recommendation_requests'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM portal_records WHERE record_type = 'recommendation'"
        )->fetchColumn();
        $metrics['completed_recommendations'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM portal_records WHERE record_type = 'recommendation' AND status = 'Issued'"
        )->fetchColumn();
        $metrics['published_opportunities'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM portal_records WHERE record_type = 'opportunity' AND status = 'Published'"
        )->fetchColumn();
        $metrics['opportunity_applications'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM portal_engagement_tracks WHERE item_type = 'opportunity' AND action = 'applied'"
        )->fetchColumn();
        $metrics['engagement_actions'] = (int)$pdo->query(
            'SELECT COUNT(*) FROM portal_engagement_tracks'
        )->fetchColumn();
    } else {
        if (in_array($user['role'], ['lecturer', 'verifier'], true)) {
            $metrics['pending_achievements'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM portal_records WHERE record_type = 'achievement' AND status = 'Pending'"
            )->fetchColumn();
            $metrics['verification_workload'] = $metrics['pending_achievements'];
        }
        if ($user['role'] === 'lecturer') {
            $recommendationQuery = $pdo->prepare(
                "SELECT COUNT(*) FROM portal_records r
                 WHERE r.record_type = 'recommendation' AND r.status = 'Pending'
                   AND JSON_UNQUOTE(JSON_EXTRACT(r.payload, '$.lecturer_user_id')) = ?"
            );
            $recommendationQuery->execute([$user['user_id']]);
            $metrics['recommendation_requests'] = (int)$recommendationQuery->fetchColumn();
        }
        if ($user['role'] === 'supervisor') {
            $metrics['verification_workload'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM portal_records WHERE record_type = 'attachment' AND status = 'Pending'"
            )->fetchColumn();
        }
        if ($user['role'] === 'chaplaincy') {
            $metrics['support_requests'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM portal_records WHERE record_type = 'support' AND status = 'Pending'"
            )->fetchColumn();
        }
        if (in_array($user['role'], ['career_advisor', 'career', 'careers', 'career_advising'], true)) {
            $metrics['published_opportunities'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM portal_records WHERE record_type = 'opportunity' AND status = 'Published'"
            )->fetchColumn();
            $metrics['opportunity_applications'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM portal_engagement_tracks WHERE item_type = 'opportunity' AND action = 'applied'"
            )->fetchColumn();
        }
    }
    $deadlineCount = $pdo->query(
        "SELECT COUNT(*) FROM portal_engagement_items
         WHERE item_type = 'deadline' AND status = 'Published'
           AND due_at >= CURRENT_TIMESTAMP"
    );
    $metrics['upcoming_deadlines'] = (int)$deadlineCount->fetchColumn();
    if (in_array($user['role'], $managerRoles, true)) {
        $metrics['verification_workload'] = $metrics['pending_achievements'];
    }
    jsonResponse(['success' => true, 'metrics' => $metrics, 'scope' => in_array($user['role'], $managerRoles, true) ? 'institutional' : 'assigned']);
}

jsonResponse(['success' => false, 'message' => 'Unknown action.'], 400);
