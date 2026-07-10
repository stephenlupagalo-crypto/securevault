<?php
require_once __DIR__ . '/includes/config.php';
SessionManager::start();

$token = $_GET['t'] ?? '';
if ($token === '') { http_response_code(404); die('Invalid link.'); }

$share = ShareLink::findByToken($token);
if (!$share || !ShareLink::isValid($share)) {
    http_response_code(410);
    die('This share link is invalid, revoked, or has expired.');
}

$error = '';
$needsPassword = !empty($share['password_hash']);
$unlocked = !$needsPassword;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $submitted = $_POST['password'] ?? '';
    if (ShareLink::checkPassword($share, $submitted)) {
        $unlocked = true;
        $_SESSION['share_unlocked_' . $share['id']] = true;
    } else {
        $error = 'Incorrect password.';
    }
}
if (!empty($_SESSION['share_unlocked_' . $share['id']])) $unlocked = true;

if ($unlocked && isset($_GET['download'])) {
    $disk_path = UPLOAD_DIR . $share['stored_name'];
    if (!file_exists($disk_path)) { http_response_code(404); die('File missing from storage.'); }
    try {
        $cipher    = file_get_contents($disk_path);
        $plaintext = decrypt_file($cipher, $share['encryption_iv'], $share['encryption_tag']);
    } catch (Throwable $e) {
        http_response_code(500); die('Decryption failed.');
    }
    ShareLink::registerDownload((int)$share['id']);
    AuditService::logDownload((int)$share['file_id'], null, (int)$share['id']);

    header('Content-Type: ' . $share['mime_type']);
    header('Content-Disposition: attachment; filename="' . addslashes($share['original_name']) . '"');
    header('Content-Length: ' . strlen($plaintext));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $plaintext;
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SecureVault – Shared File</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:#0a0e1a;color:#f1f5f9;min-height:100vh;display:grid;place-items:center;padding:24px}
.card{width:min(440px,94vw);background:#111827;border:1px solid #1e3a5f;border-radius:20px;padding:36px;box-shadow:0 25px 60px rgba(0,0,0,.5);text-align:center}
.icon{font-size:3rem;margin-bottom:14px}
h1{font-size:1.3rem;margin-bottom:6px}
.meta{color:#94a3b8;font-size:.88rem;margin-bottom:24px}
input[type=password]{width:100%;background:#1e2a3a;border:1px solid #1e3a5f;color:#f1f5f9;padding:12px 16px;border-radius:10px;font-size:.95rem;margin-bottom:14px}
.btn{width:100%;padding:13px;border:none;border-radius:10px;font-size:1rem;font-weight:600;cursor:pointer;background:linear-gradient(135deg,#3b82f6,#6366f1);color:#fff;text-decoration:none;display:block}
.error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#fca5a5;padding:10px 14px;border-radius:10px;font-size:.85rem;margin-bottom:16px}
.badge{display:inline-block;background:rgba(16,185,129,.12);color:#6ee7b7;padding:4px 10px;border-radius:6px;font-size:.75rem;margin-top:16px}
</style>
</head>
<body>
<div class="card">
  <div class="icon">🔐</div>
  <h1><?= e($share['original_name']) ?></h1>
  <div class="meta"><?= format_bytes((int)$share['file_size']) ?> · shared via SecureVault</div>

  <?php if($error): ?><div class="error">⚠️ <?= e($error) ?></div><?php endif; ?>

  <?php if(!$unlocked): ?>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="password" name="password" placeholder="Enter password" autofocus required>
    <button type="submit" class="btn">Unlock</button>
  </form>
  <?php else: ?>
  <a href="s.php?t=<?= e($token) ?>&download=1" class="btn">⬇ Download File</a>
  <?php endif; ?>

  <div class="badge">🔒 Encrypted with AES-256-GCM</div>
</div>
</body>
</html>
