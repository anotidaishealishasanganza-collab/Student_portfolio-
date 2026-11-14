<?php
declare(strict_types=1);

function ensureAccountCollegeColumn(PDO $pdo): void
{
    $collegeColumn = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'portal_account_profiles'
           AND COLUMN_NAME = 'college'"
    );
    $collegeColumn->execute();
    if ((int)$collegeColumn->fetchColumn() > 0) {
        return;
    }

    $pdo->exec("ALTER TABLE portal_account_profiles
        ADD COLUMN college VARCHAR(180) NOT NULL DEFAULT '' AFTER user_id");
    $allowedColleges = array_map([$pdo, 'quote'], allowedAccountColleges());
    $collegeSqlList = implode(', ', $allowedColleges);
    $pdo->exec(
        "UPDATE portal_account_profiles
         SET college = CASE
             WHEN faculty IN ($collegeSqlList) AND department NOT IN ($collegeSqlList) THEN faculty
             WHEN department IN ($collegeSqlList) AND faculty NOT IN ($collegeSqlList) THEN department
             WHEN faculty = department AND faculty IN ($collegeSqlList) THEN faculty
             ELSE college
         END
         WHERE college = ''"
    );
}

function ensureAccountCollegeSchema(PDO $pdo): void
{
    $profileTable = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'portal_account_profiles'"
    );
    $profileTable->execute();
    if ((int)$profileTable->fetchColumn() === 0) {
        ensureAccountManagementSchema($pdo);
        return;
    }
    ensureAccountCollegeColumn($pdo);
}

function ensureAccountManagementSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_account_profiles (
     user_id VARCHAR(64) NOT NULL PRIMARY KEY,
     college VARCHAR(180) NOT NULL DEFAULT '',
     faculty VARCHAR(180) NOT NULL,
     department VARCHAR(180) NOT NULL,
     programme VARCHAR(180) NOT NULL,
     study_year VARCHAR(32) NOT NULL,
     student_verification_status ENUM('Pending','Verified','Rejected') NOT NULL DEFAULT 'Pending',
     verification_reason TEXT NULL,
     reviewed_by VARCHAR(64) NULL,
     reviewed_at DATETIME NULL,
     created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
     updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     INDEX idx_student_verification_status (student_verification_status),
     CONSTRAINT fk_account_profile_user FOREIGN KEY (user_id)
       REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensureAccountCollegeColumn($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_account_activation_tokens (
     id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
     user_id INT UNSIGNED NOT NULL,
     token_hash CHAR(64) NOT NULL,
     created_by VARCHAR(64) NOT NULL,
     expires_at DATETIME NOT NULL,
     used_at DATETIME NULL,
     created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
     UNIQUE KEY uq_account_activation_token (token_hash),
     INDEX idx_account_activation_user (user_id, used_at, expires_at),
     CONSTRAINT fk_account_activation_user FOREIGN KEY (user_id)
       REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_super_administrators (
     user_id VARCHAR(64) NOT NULL PRIMARY KEY,
     assigned_by VARCHAR(64) NOT NULL,
     created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
     CONSTRAINT fk_portal_super_admin_user FOREIGN KEY (user_id)
       REFERENCES users(user_id) ON DELETE CASCADE
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
     INDEX idx_record (record_type, record_id),
     INDEX idx_actor (actor_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $envIds = getenv('AUSSH_SUPER_ADMIN_USER_IDS');
    if (!is_string($envIds) || trim($envIds) === '') {
        return;
    }
    $ids = array_values(array_unique(array_filter(array_map(
        'trim',
        explode(',', $envIds)
    ), static fn(string $id): bool => $id !== '')));
    if (!$ids) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $accounts = $pdo->prepare(
        "SELECT id, user_id FROM users
         WHERE user_id IN ($placeholders) AND role = 'admin' AND status = 'Active'"
    );
    $accounts->execute($ids);
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO portal_super_administrators (user_id, assigned_by) VALUES (?, ?)'
    );
    foreach ($accounts->fetchAll(PDO::FETCH_ASSOC) as $account) {
        $insert->execute([$account['user_id'], $account['user_id']]);
        if ($insert->rowCount() > 0) {
            accountAudit($pdo, $account['user_id'], 'super_administrator_assigned', (int)$account['id'], [
                'user_id' => $account['user_id'],
                'assignment_source' => 'server_configuration',
            ]);
        }
    }
}

function allowedAccountColleges(): array
{
    return [
        'College of Business and Management Sciences',
        'College of Engineering and Applied Sciences',
        'College of Health, Agriculture and Natural Sciences',
        'College of Social Sciences, Theology, Humanities and Education',
        'School of Law',
    ];
}

function isAllowedAccountCollege(mixed $college): bool
{
    return is_string($college) && in_array($college, allowedAccountColleges(), true);
}

function accountAudit(
    PDO $pdo,
    string $actorUserId,
    string $action,
    int $recordId,
    array $details = []
): void {
    $statement = $pdo->prepare(
        'INSERT INTO portal_audit(actor_user_id, action, record_type, record_id, details)
         VALUES (?, ?, \'user_account\', ?, ?)'
    );
    $statement->execute([
        $actorUserId,
        $action,
        $recordId,
        json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
}

function isDesignatedSuperAdministrator(PDO $pdo, string $userId): bool
{
    $query = $pdo->prepare(
        "SELECT 1 FROM portal_super_administrators s
         INNER JOIN users u ON u.user_id = s.user_id
         WHERE s.user_id = ? AND u.role = 'admin' AND u.status = 'Active'
         LIMIT 1"
    );
    $query->execute([$userId]);
    return $query->fetchColumn() !== false;
}

function createAccountActivation(PDO $pdo, int $userDbId, string $actorUserId): string
{
    $pdo->prepare(
        'UPDATE portal_account_activation_tokens SET used_at = CURRENT_TIMESTAMP
         WHERE user_id = ? AND used_at IS NULL'
    )->execute([$userDbId]);
    $token = bin2hex(random_bytes(32));
    $pdo->prepare(
        'INSERT INTO portal_account_activation_tokens
            (user_id, token_hash, created_by, expires_at)
         VALUES (?, ?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR))'
    )->execute([$userDbId, hash('sha256', $token), $actorUserId]);
    return $token;
}

function sendAccountActivation(string $email, string $fullName, string $token): bool
{
    $profile = getenv('USERPROFILE');
    $mailConfigPath = is_string($profile) && $profile !== ''
        ? $profile . DIRECTORY_SEPARATOR . 'aussh_private' . DIRECTORY_SEPARATOR . 'mail_config.php'
        : '';
    if ($mailConfigPath === '' || !is_file($mailConfigPath)) {
        return false;
    }
    ob_start();
    try {
        $mailConfig = require $mailConfigPath;
    } finally {
        ob_end_clean();
    }
    $appUrl = is_array($mailConfig) ? rtrim((string)($mailConfig['app_url'] ?? ''), '/') : '';
    if (!filter_var($appUrl, FILTER_VALIDATE_URL)) {
        return false;
    }

    require_once __DIR__ . '/mailer.php';
    $activationLink = $appUrl . '/#account-activation=' . rawurlencode($token);
    try {
        sendWorkflowEmail(
            $email,
            $fullName,
            'Activate your AU Student Success Hub (AUSSH) account',
            "An administrator created your AU Student Success Hub (AUSSH) account. Use this link to activate it and establish your own password:\n\n" .
                $activationLink . "\n\nThis single-use link expires in 24 hours. If you did not expect this invitation, contact Africa University support."
        );
        return true;
    } catch (Throwable $error) {
        error_log('AUSSH account activation email delivery failed.');
        return false;
    } finally {
        unset($activationLink, $token);
    }
}

function accountNotify(PDO $pdo, string $recipient, string $title, string $message, int $userDbId, string $event): void
{
    $pdo->prepare(
        'INSERT IGNORE INTO portal_notifications
            (recipient_user_id, title, message, record_type, record_id)
         VALUES (?, ?, ?, \'account\', ?)'
    )->execute([
        $recipient,
        $title,
        $message,
        $userDbId,
    ]);
}
