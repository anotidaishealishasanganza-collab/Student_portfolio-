<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/achievement_evidence_lib.php';

$sessionUser = requireAuthentication();
$pdo = database();
ensureAchievementEvidenceSchema($pdo);

$account = $pdo->prepare(
    "SELECT user_id, role FROM users
     WHERE user_id = ? AND status = 'Active' LIMIT 1"
);
$account->execute([$sessionUser['user_id']]);
$user = $account->fetch(PDO::FETCH_ASSOC);
if (!$user || $user['role'] !== $sessionUser['role']) {
    jsonResponse(['success' => false, 'message' => 'Your account is no longer authorised. Sign in again.'], 403);
}

$attachmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$attachmentId || $attachmentId < 1) {
    jsonResponse(['success' => false, 'message' => 'Evidence file not found.'], 404);
}
$query = $pdo->prepare(
    'SELECT e.id, e.record_id, e.original_filename, e.storage_key, e.mime_type,
            e.file_size, r.owner_user_id
     FROM portal_achievement_evidence e
     INNER JOIN portal_records r ON r.id = e.record_id
     WHERE e.id = ? AND r.record_type = \'achievement\'
     LIMIT 1'
);
$query->execute([$attachmentId]);
$attachment = $query->fetch(PDO::FETCH_ASSOC);
if (!$attachment) {
    jsonResponse(['success' => false, 'message' => 'Evidence file not found.'], 404);
}

$authorizedStaffRoles = ['verifier', 'lecturer', 'hod', 'admin'];
$isOwner = hash_equals((string)$attachment['owner_user_id'], (string)$user['user_id']);
if (!$isOwner && !in_array($user['role'], $authorizedStaffRoles, true)) {
    jsonResponse(['success' => false, 'message' => 'Evidence file not found.'], 404);
}
if (!preg_match('/^[a-f0-9]{64}$/', (string)$attachment['storage_key'])) {
    jsonResponse(['success' => false, 'message' => 'Evidence file is unavailable.'], 404);
}

try {
    $storage = achievementEvidenceStorageDirectory();
} catch (AchievementEvidenceException $error) {
    jsonResponse(['success' => false, 'message' => $error->getMessage()], $error->httpStatus);
}
$path = realpath($storage . DIRECTORY_SEPARATOR . $attachment['storage_key']);
$actualSize = $path ? filesize($path) : false;
if (
    !$path || dirname($path) !== $storage || !is_file($path) || !is_readable($path) ||
    !is_int($actualSize) || $actualSize !== (int)$attachment['file_size']
) {
    jsonResponse(['success' => false, 'message' => 'Evidence file is unavailable.'], 404);
}

$mimeTypes = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
if (!in_array($attachment['mime_type'], $mimeTypes, true)) {
    jsonResponse(['success' => false, 'message' => 'Evidence file type is unavailable.'], 404);
}
$preview = ($_GET['preview'] ?? '') === '1' &&
    in_array($attachment['mime_type'], ['application/pdf', 'image/jpeg', 'image/png'], true);
$shouldAudit = true;
if ($preview) {
    $recentPreview = $pdo->prepare(
        "SELECT 1 FROM portal_audit
         WHERE actor_user_id = ? AND action = 'evidence_previewed'
           AND record_type = 'achievement' AND record_id = ?
           AND JSON_UNQUOTE(JSON_EXTRACT(details, '$.attachment_id')) = ?
           AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 HOUR)
         LIMIT 1"
    );
    $recentPreview->execute([$user['user_id'], (int)$attachment['record_id'], (string)$attachment['id']]);
    $shouldAudit = $recentPreview->fetchColumn() === false;
}
if ($shouldAudit) {
    $pdo->prepare(
        'INSERT INTO portal_audit(actor_user_id, action, record_type, record_id, details)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $user['user_id'],
        $preview ? 'evidence_previewed' : 'evidence_downloaded',
        'achievement',
        (int)$attachment['record_id'],
        json_encode([
            'attachment_id' => (int)$attachment['id'],
            'preview' => $preview,
        ], JSON_THROW_ON_ERROR),
    ]);
}

$originalName = basename(str_replace(["\r", "\n", '"', '\\'], ['', '', '', '/'], (string)$attachment['original_filename']));
$fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName) ?: 'AU_achievement_evidence';
header('Content-Type: ' . $attachment['mime_type']);
header('Content-Length: ' . (int)$attachment['file_size']);
header(
    'Content-Disposition: ' . ($preview ? 'inline' : 'attachment') .
    '; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($originalName)
);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Cross-Origin-Resource-Policy: same-origin');
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, no-store');
if ($preview) {
    header("Content-Security-Policy: sandbox; default-src 'none';");
}
readfile($path);
