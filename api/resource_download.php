<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$user = requireAuthentication();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    jsonResponse(['success' => false, 'message' => 'Resource not found.'], 404);
}

$pdo = database();
$account = $pdo->prepare(
    "SELECT role FROM users WHERE user_id = ? AND status = 'Active' LIMIT 1"
);
$account->execute([$user['user_id']]);
$role = $account->fetchColumn();
if (!$role || $role !== $user['role']) {
    jsonResponse(['success' => false, 'message' => 'Authentication required.'], 403);
}

$audience = $role === 'alumni' ? 'alumni' : 'student';
$query = $pdo->prepare(
    "SELECT id, original_name, mime_type, file_key, file_size
     FROM portal_engagement_items
     WHERE id = ? AND item_type = 'resource' AND status = 'Published'
       AND (target_role = 'all' OR target_role = ?)
     LIMIT 1"
);
$query->execute([$id, $audience]);
$resource = $query->fetch(PDO::FETCH_ASSOC);
if (!$resource || !preg_match('/^[a-f0-9]{64}$/', (string)$resource['file_key'])) {
    jsonResponse(['success' => false, 'message' => 'Resource not found.'], 404);
}

$profile = getenv('USERPROFILE');
if (!is_string($profile) || $profile === '') {
    jsonResponse(['success' => false, 'message' => 'Private resource storage is unavailable.'], 500);
}
$storage = realpath($profile . DIRECTORY_SEPARATOR . 'aussh_private' . DIRECTORY_SEPARATOR . 'resources');
$path = $storage
    ? realpath($storage . DIRECTORY_SEPARATOR . $resource['file_key'])
    : false;
if (!$storage || !$path || dirname($path) !== $storage || !is_file($path)) {
    jsonResponse(['success' => false, 'message' => 'Resource file is unavailable.'], 404);
}

$pdo->prepare(
    "UPDATE portal_engagement_items SET download_count = download_count + 1
     WHERE id = ? AND item_type = 'resource'"
)->execute([$id]);
$name = basename(str_replace(["\r", "\n", '"', '\\'], '', (string)$resource['original_name']));
$fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'AU_resource';
header('Content-Type: ' . $resource['mime_type']);
header('Content-Length: ' . (int)$resource['file_size']);
header('Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
