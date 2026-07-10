<?php
require_once __DIR__ . '/includes/config.php';
SessionManager::start();
require_login();

$uid = $_SESSION['user_id'];
$fid = (int)($_GET['id'] ?? 0);

$fq = db()->prepare('SELECT * FROM files WHERE id=? AND user_id=? AND is_deleted=0');
$fq->execute([$fid, $uid]);
$file = $fq->fetch();

if (!$file) {
    http_response_code(404);
    die('File not found or access denied.');
}

$disk_path = UPLOAD_DIR . $file['stored_name'];
if (!file_exists($disk_path)) {
    http_response_code(404);
    die('Encrypted file is missing from storage.');
}

try {
    $cipher    = file_get_contents($disk_path);
    $plaintext = decrypt_file($cipher, $file['encryption_iv'], $file['encryption_tag']);
} catch (Throwable $e) {
    http_response_code(500);
    die('Decryption failed: ' . $e->getMessage());
}

log_activity('download', 'File: '.$file['original_name']);
AuditService::logDownload((int)$file['id'], (int)$uid);

// Output the decrypted file
header('Content-Type: ' . $file['mime_type']);
header('Content-Disposition: attachment; filename="' . addslashes($file['original_name']) . '"');
header('Content-Length: ' . strlen($plaintext));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
echo $plaintext;
exit;
