<?php
declare(strict_types=1);

const INDUSTRIAL_ATTACHMENT_MAX_FILE_SIZE = 10485760;
const INDUSTRIAL_ATTACHMENT_MAX_REPORT_FILES = 3;

final class IndustrialAttachmentException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}

function industrialAttachmentDate(mixed $value): ?string
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (
        !$date ||
        ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) ||
        $date->format('Y-m-d') !== $value
    ) {
        return null;
    }
    return $value;
}

function industrialAttachmentRequiredText(mixed $value, int $maxLength): ?string
{
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }
    $text = trim((string)$value);
    return $text !== '' && mb_strlen($text, 'UTF-8') <= $maxLength ? $text : null;
}

function industrialAttachmentStorageDirectory(): string
{
    $profile = getenv('USERPROFILE');
    if (!is_string($profile) || $profile === '') {
        throw new IndustrialAttachmentException('Private attachment storage is unavailable.', 500);
    }
    $directory = $profile . DIRECTORY_SEPARATOR . 'aussh_private' .
        DIRECTORY_SEPARATOR . 'industrial_attachment';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new IndustrialAttachmentException('Private attachment storage could not be created.', 500);
    }
    $storage = realpath($directory);
    if ($storage === false || !is_dir($storage)) {
        throw new IndustrialAttachmentException('Private attachment storage is unavailable.', 500);
    }
    $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($documentRoot !== false) {
        $root = rtrim($documentRoot, DIRECTORY_SEPARATOR);
        $prefix = $root . DIRECTORY_SEPARATOR;
        if (
            strcasecmp($storage, $root) === 0 ||
            strncasecmp($storage . DIRECTORY_SEPARATOR, $prefix, strlen($prefix)) === 0
        ) {
            throw new IndustrialAttachmentException('Attachment storage must be outside the public web directory.', 500);
        }
    }
    return $storage;
}

function industrialAttachmentCollectUploads(string $field, int $maximumFiles): array
{
    $upload = $_FILES[$field] ?? null;
    if ($upload === null) {
        return [];
    }
    if (!is_array($upload) || !isset($upload['name'], $upload['tmp_name'], $upload['error'], $upload['size'])) {
        throw new IndustrialAttachmentException('The document upload form is invalid.');
    }
    $names = is_array($upload['name']) ? $upload['name'] : [$upload['name']];
    $temporaryPaths = is_array($upload['tmp_name']) ? $upload['tmp_name'] : [$upload['tmp_name']];
    $errors = is_array($upload['error']) ? $upload['error'] : [$upload['error']];
    if (count($names) !== count($temporaryPaths) || count($names) !== count($errors)) {
        throw new IndustrialAttachmentException('The document upload form is invalid.');
    }
    if (count($names) > $maximumFiles) {
        throw new IndustrialAttachmentException('Select no more than ' . $maximumFiles . ' document(s).');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $files = [];
    foreach ($names as $index => $providedName) {
        if (!is_string($providedName)) {
            throw new IndustrialAttachmentException('The document upload form is invalid.');
        }
        $error = (int)($errors[$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            $status = in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 422;
            throw new IndustrialAttachmentException('A selected document could not be uploaded.', $status);
        }
        $temporaryPath = $temporaryPaths[$index] ?? null;
        if (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath) || !is_string($providedName)) {
            throw new IndustrialAttachmentException('A selected document is not a valid upload.');
        }
        $actualSize = filesize($temporaryPath);
        if (!is_int($actualSize) || $actualSize < 1) {
            throw new IndustrialAttachmentException('Documents must not be empty.');
        }
        if ($actualSize > INDUSTRIAL_ATTACHMENT_MAX_FILE_SIZE) {
            throw new IndustrialAttachmentException('Each document must be 10 MB or smaller.', 413);
        }

        $originalName = basename(str_replace('\\', '/', $providedName));
        $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?? '';
        $originalName = trim(mb_substr($originalName, 0, 255, 'UTF-8'));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mime = $finfo->file($temporaryPath);
        $accepted = match ($extension) {
            'pdf' => in_array($mime, ['application/pdf', 'application/x-pdf'], true) ? 'application/pdf' : null,
            'jpg', 'jpeg' => $mime === 'image/jpeg' ? 'image/jpeg' : null,
            'png' => $mime === 'image/png' ? 'image/png' : null,
            default => null,
        };
        if ($accepted === null) {
            throw new IndustrialAttachmentException('Upload a valid PDF, JPG/JPEG or PNG document.');
        }
        $files[] = [
            'temporary_path' => $temporaryPath,
            'original_filename' => $originalName !== '' ? $originalName : 'attachment.' . $extension,
            'mime_type' => $accepted,
            'file_size' => $actualSize,
        ];
    }
    return $files;
}

function industrialAttachmentStoreUploads(
    PDO $pdo,
    int $attachmentId,
    ?int $reportId,
    string $documentType,
    string $uploaderUserId,
    array $files,
    array &$storedPaths
): void {
    if (!$files) {
        return;
    }
    $directory = industrialAttachmentStorageDirectory();
    $insert = $pdo->prepare(
        'INSERT INTO portal_industrial_attachment_documents
            (attachment_id, report_id, document_type, original_filename, storage_key, mime_type, file_size, uploader_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($files as $file) {
        $storageKey = bin2hex(random_bytes(32));
        $target = $directory . DIRECTORY_SEPARATOR . $storageKey;
        if (!move_uploaded_file($file['temporary_path'], $target)) {
            throw new IndustrialAttachmentException('A document could not be stored securely.', 500);
        }
        $storedPaths[] = $target;
        $insert->execute([
            $attachmentId,
            $reportId,
            $documentType,
            $file['original_filename'],
            $storageKey,
            $file['mime_type'],
            $file['file_size'],
            $uploaderUserId,
        ]);
    }
}

function industrialAttachmentRemoveStoredFiles(array $paths): void
{
    foreach ($paths as $path) {
        if (is_string($path) && is_file($path)) {
            unlink($path);
        }
    }
}

function industrialAttachmentNotification(
    PDO $pdo,
    string $recipientUserId,
    string $title,
    string $message,
    int $attachmentId
): void {
    $statement = $pdo->prepare(
        'INSERT INTO portal_notifications
            (recipient_user_id, title, message, record_type, record_id)
         VALUES (?, ?, ?, \'industrial_attachment\', ?)'
    );
    $statement->execute([$recipientUserId, $title, $message, $attachmentId]);
}

function industrialAttachmentAudit(
    PDO $pdo,
    string $actorUserId,
    string $action,
    int $attachmentId,
    array $details = []
): void {
    $statement = $pdo->prepare(
        'INSERT INTO portal_audit(actor_user_id, action, record_type, record_id, details)
         VALUES (?, ?, \'industrial_attachment\', ?, ?)'
    );
    $statement->execute([
        $actorUserId,
        $action,
        $attachmentId,
        json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
}
