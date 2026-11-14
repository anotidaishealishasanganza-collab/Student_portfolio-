<?php
declare(strict_types=1);

const ACHIEVEMENT_EVIDENCE_MAX_FILE_SIZE = 10485760;
const ACHIEVEMENT_EVIDENCE_MAX_FILES = 5;

final class AchievementEvidenceException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}

function ensureAchievementEvidenceSchema(PDO $pdo): void
{
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_achievement_evidence (
     id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
     record_id BIGINT UNSIGNED NOT NULL,
     original_filename VARCHAR(255) NOT NULL,
     storage_key CHAR(64) NOT NULL,
     mime_type VARCHAR(100) NOT NULL,
     file_size BIGINT UNSIGNED NOT NULL,
     uploader_user_id VARCHAR(64) NOT NULL,
     uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
     UNIQUE KEY uq_achievement_evidence_storage (storage_key),
     INDEX idx_achievement_evidence_record (record_id, uploaded_at, id),
     CONSTRAINT fk_achievement_evidence_record
       FOREIGN KEY (record_id) REFERENCES portal_records(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function achievementEvidenceStorageDirectory(): string
{
    $profile = getenv('USERPROFILE');
    if (!is_string($profile) || $profile === '') {
        throw new AchievementEvidenceException('Private evidence storage is unavailable.', 500);
    }

    $directory = $profile . DIRECTORY_SEPARATOR . 'aussh_private' .
        DIRECTORY_SEPARATOR . 'achievement_evidence';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new AchievementEvidenceException('Private evidence storage could not be created.', 500);
    }
    $storage = realpath($directory);
    if ($storage === false || !is_dir($storage)) {
        throw new AchievementEvidenceException('Private evidence storage is unavailable.', 500);
    }

    $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($documentRoot !== false) {
        $rootPrefix = rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (
            strcasecmp($storage, rtrim($documentRoot, DIRECTORY_SEPARATOR)) === 0 ||
            strncasecmp($storage . DIRECTORY_SEPARATOR, $rootPrefix, strlen($rootPrefix)) === 0
        ) {
            throw new AchievementEvidenceException('Evidence storage must be outside the public web directory.', 500);
        }
    }
    return $storage;
}

function achievementEvidenceDocxIsValid(string $path): bool
{
    if (!class_exists(ZipArchive::class)) {
        return false;
    }
    $archive = new ZipArchive();
    if ($archive->open($path) !== true) {
        return false;
    }
    $typesStat = $archive->statName('[Content_Types].xml');
    $documentStat = $archive->statName('word/document.xml');
    $entryCount = $archive->numFiles;
    $expandedBytes = 0;
    for ($index = 0; $index < $entryCount; $index++) {
        $stat = $archive->statIndex($index);
        $expandedBytes += (int)($stat['size'] ?? 0);
        if ($entryCount > 5000 || $expandedBytes > 52428800) {
            $archive->close();
            return false;
        }
    }
    $contentTypes = $typesStat && (int)$typesStat['size'] <= 65536
        ? $archive->getFromName('[Content_Types].xml')
        : false;
    $archive->close();
    return is_string($contentTypes) && $documentStat !== false &&
        str_contains(
            $contentTypes,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml'
        );
}

function collectAchievementEvidenceUploads(bool $required = false, int $existingCount = 0): array
{
    $upload = $_FILES['evidence_files'] ?? null;
    if ($upload === null) {
        if ($required) {
            throw new AchievementEvidenceException('Select at least one evidence file.');
        }
        return [];
    }
    if (
        !is_array($upload) || !isset($upload['name'], $upload['type'], $upload['tmp_name'], $upload['error'], $upload['size']) ||
        !is_array($upload['name']) || !is_array($upload['type']) ||
        !is_array($upload['tmp_name']) || !is_array($upload['error']) || !is_array($upload['size'])
    ) {
        throw new AchievementEvidenceException('The evidence upload form is invalid.');
    }

    $count = count($upload['name']);
    if ($count + $existingCount > ACHIEVEMENT_EVIDENCE_MAX_FILES) {
        throw new AchievementEvidenceException('An achievement can have no more than five evidence files.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $validated = [];
    for ($index = 0; $index < $count; $index++) {
        $error = (int)$upload['error'][$index];
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            $message = match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'A selected file exceeds the server upload limit.',
                UPLOAD_ERR_PARTIAL => 'A selected file was only partially uploaded. Try again.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not receive a selected file.',
                default => 'A selected file could not be uploaded.',
            };
            throw new AchievementEvidenceException($message, 413);
        }

        $temporaryPath = $upload['tmp_name'][$index];
        $providedName = $upload['name'][$index];
        if (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath) || !is_string($providedName)) {
            throw new AchievementEvidenceException('A selected file is not a valid upload.');
        }
        $actualSize = filesize($temporaryPath);
        if (!is_int($actualSize) || $actualSize < 1) {
            throw new AchievementEvidenceException('Evidence files must not be empty.');
        }
        if ($actualSize > ACHIEVEMENT_EVIDENCE_MAX_FILE_SIZE) {
            throw new AchievementEvidenceException('Each evidence file must be 10 MB or smaller.', 413);
        }

        $originalName = basename(str_replace('\\', '/', $providedName));
        $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?? '';
        $originalName = trim(mb_substr($originalName, 0, 255, 'UTF-8'));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mime = $finfo->file($temporaryPath);
        if (!is_string($mime)) {
            throw new AchievementEvidenceException('The type of a selected file could not be verified.');
        }

        $accepted = match ($extension) {
            'pdf' => in_array($mime, ['application/pdf', 'application/x-pdf'], true)
                ? 'application/pdf' : null,
            'jpg', 'jpeg' => $mime === 'image/jpeg' ? 'image/jpeg' : null,
            'png' => $mime === 'image/png' ? 'image/png' : null,
            'doc' => in_array($mime, [
                'application/msword', 'application/vnd.ms-office', 'application/x-ole-storage',
            ], true) ? 'application/msword' : null,
            'docx' => achievementEvidenceDocxIsValid($temporaryPath) &&
                in_array($mime, [
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/zip', 'application/octet-stream',
                ], true)
                ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : null,
            default => null,
        };
        if ($accepted === null) {
            throw new AchievementEvidenceException(
                'Unsupported evidence file. Upload a matching PDF, JPG/JPEG, PNG, DOC or DOCX file.'
            );
        }

        $validated[] = [
            'temporary_path' => $temporaryPath,
            'original_filename' => $originalName !== '' ? $originalName : 'evidence.' . $extension,
            'mime_type' => $accepted,
            'file_size' => $actualSize,
        ];
    }
    if ($required && !$validated) {
        throw new AchievementEvidenceException('Select at least one evidence file.');
    }
    return $validated;
}

function storeAchievementEvidence(
    PDO $pdo,
    int $recordId,
    string $uploaderUserId,
    array $files,
    array &$storedPaths
): array {
    if (!$files) {
        return [];
    }
    $storage = achievementEvidenceStorageDirectory();
    $insert = $pdo->prepare(
        'INSERT INTO portal_achievement_evidence
            (record_id, original_filename, storage_key, mime_type, file_size, uploader_user_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $audit = $pdo->prepare(
        'INSERT INTO portal_audit(actor_user_id, action, record_type, record_id, details)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stored = [];
    foreach ($files as $file) {
        $storageKey = bin2hex(random_bytes(32));
        $path = $storage . DIRECTORY_SEPARATOR . $storageKey;
        if (!move_uploaded_file($file['temporary_path'], $path)) {
            throw new AchievementEvidenceException('A file could not be saved to private evidence storage.', 500);
        }
        $storedPaths[] = $path;
        $insert->execute([
            $recordId,
            $file['original_filename'],
            $storageKey,
            $file['mime_type'],
            $file['file_size'],
            $uploaderUserId,
        ]);
        $attachmentId = (int)$pdo->lastInsertId();
        $audit->execute([
            $uploaderUserId,
            'evidence_uploaded',
            'achievement',
            $recordId,
            json_encode([
                'attachment_id' => $attachmentId,
                'filename' => $file['original_filename'],
                'mime_type' => $file['mime_type'],
                'file_size' => $file['file_size'],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $stored[] = [
            'id' => $attachmentId,
            'original_filename' => $file['original_filename'],
            'mime_type' => $file['mime_type'],
            'file_size' => $file['file_size'],
        ];
    }
    return $stored;
}

function removeStoredAchievementEvidence(array $paths): void
{
    foreach ($paths as $path) {
        if (is_string($path) && is_file($path)) {
            unlink($path);
        }
    }
}
