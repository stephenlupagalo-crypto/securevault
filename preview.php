<?php
require_once __DIR__ . '/includes/config.php';
SessionManager::start();
require_login();

$uid = $_SESSION['user_id'];
$fid = (int)($_GET['id'] ?? 0);
$raw = isset($_GET['raw']); // ?raw=1 streams the actual decrypted bytes for <img>/<embed> src

$fq = db()->prepare('SELECT * FROM files WHERE id=? AND user_id=? AND is_deleted=0');
$fq->execute([$fid, $uid]);
$file = $fq->fetch();

if (!$file) { http_response_code(404); die('File not found or access denied.'); }

$disk_path = UPLOAD_DIR . $file['stored_name'];
if (!file_exists($disk_path)) { http_response_code(404); die('File missing from storage.'); }

$previewable_image = str_starts_with($file['mime_type'], 'image/') && $file['mime_type'] !== 'image/svg+xml';
$previewable_pdf   = $file['mime_type'] === 'application/pdf';
$previewable_text  = str_starts_with($file['mime_type'], 'text/');
$previewable_audio = str_starts_with($file['mime_type'], 'audio/');
$previewable_video = str_starts_with($file['mime_type'], 'video/');

if ($raw) {
    // Stream raw decrypted bytes inline (used as the src for <img>/<embed>/<video>/<audio>)
    try {
        $cipher    = file_get_contents($disk_path);
        $plaintext = decrypt_file($cipher, $file['encryption_iv'], $file['encryption_tag']);
    } catch (Throwable $e) {
        http_response_code(500); die('Decryption failed.');
    }
    AuditService::logDownload((int)$file['id'], (int)$uid);
    header('Content-Type: ' . $file['mime_type']);
    header('Content-Disposition: inline; filename="' . addslashes($file['original_name']) . '"');
    header('Content-Length: ' . strlen($plaintext));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $plaintext;
    exit;
}

$page_title = 'Preview: ' . $file['original_name'];
require_once __DIR__ . '/includes/header.php';
?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px">
  <div>
    <div class="section-title"><?= e($file['original_name']) ?></div>
    <div class="section-sub" style="margin-bottom:0"><?= format_bytes((int)$file['file_size']) ?> · <?= e($file['mime_type']) ?></div>
  </div>
  <div style="display:flex;gap:8px">
    <a href="download.php?id=<?= $file['id'] ?>" class="btn btn-primary">⬇ Download</a>
    <a href="files.php" class="btn btn-ghost">← Back to Files</a>
  </div>
</div>

<div class="card" style="padding:20px;text-align:center">
<?php if ($previewable_image): ?>
  <img src="preview.php?id=<?= $file['id'] ?>&raw=1" style="max-width:100%;max-height:70vh;border-radius:10px">
<?php elseif ($previewable_pdf): ?>
  <embed src="preview.php?id=<?= $file['id'] ?>&raw=1" type="application/pdf" style="width:100%;height:75vh;border-radius:10px;border:1px solid var(--border)">
<?php elseif ($previewable_audio): ?>
  <audio controls style="width:100%"><source src="preview.php?id=<?= $file['id'] ?>&raw=1" type="<?= e($file['mime_type']) ?>"></audio>
<?php elseif ($previewable_video): ?>
  <video controls style="max-width:100%;max-height:70vh"><source src="preview.php?id=<?= $file['id'] ?>&raw=1" type="<?= e($file['mime_type']) ?>"></video>
<?php elseif ($previewable_text): ?>
  <?php
    try {
      $cipher = file_get_contents($disk_path);
      $plain  = decrypt_file($cipher, $file['encryption_iv'], $file['encryption_tag']);
      AuditService::logDownload((int)$file['id'], (int)$uid);
    } catch (Throwable $e) { $plain = null; }
  ?>
  <?php if($plain !== null): ?>
  <pre style="text-align:left;white-space:pre-wrap;word-break:break-word;background:var(--surface2);padding:16px;border-radius:10px;max-height:70vh;overflow:auto;font-family:var(--mono);font-size:.85rem"><?= e(mb_strimwidth($plain, 0, 50000, "\n…(truncated)…")) ?></pre>
  <?php else: ?>
  <div style="color:var(--muted);padding:40px">Could not decrypt this file for preview.</div>
  <?php endif; ?>
<?php else: ?>
  <div style="padding:48px;color:var(--muted)">
    <div style="font-size:3rem;margin-bottom:12px">📎</div>
    <div style="font-weight:600;margin-bottom:6px">No preview available</div>
    <div style="font-size:.88rem">This file type can't be previewed in-browser. Download it to view.</div>
  </div>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
