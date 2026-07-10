<?php
require_once __DIR__ . '/includes/config.php';
SessionManager::start();
require_login();

$uid = $_SESSION['user_id'];
$fid = (int)($_GET['id'] ?? $_POST['file_id'] ?? 0);

$fq = db()->prepare('SELECT * FROM files WHERE id=? AND user_id=? AND is_deleted=0');
$fq->execute([$fid, $uid]);
$file = $fq->fetch();
if (!$file) { http_response_code(404); die('File not found or access denied.'); }

$error = $success = '';
$newLink = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $password = trim($_POST['password'] ?? '');
        $expiresIn = $_POST['expires_in'] ?? '';
        $maxDl = (int)($_POST['max_downloads'] ?? 0);

        $expiresAt = match ($expiresIn) {
            '1h'  => date('Y-m-d H:i:s', strtotime('+1 hour')),
            '24h' => date('Y-m-d H:i:s', strtotime('+24 hours')),
            '7d'  => date('Y-m-d H:i:s', strtotime('+7 days')),
            '30d' => date('Y-m-d H:i:s', strtotime('+30 days')),
            default => null,
        };

        $result = ShareLink::create($fid, $uid, $password ?: null, $expiresAt, $maxDl > 0 ? $maxDl : null);
        $newLink = rtrim($_SERVER['REQUEST_SCHEME'] ?? 'http', '/') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') .
                   BASE_PATH . 's.php?t=' . $result['token'];
        AuditService::log('share_created', 'File: ' . $file['original_name']);
        $success = 'Share link created.';
    } elseif ($action === 'revoke') {
        $sid = (int)($_POST['share_id'] ?? 0);
        ShareLink::revoke($sid, $uid);
        AuditService::log('share_revoked', "Share #$sid for file: " . $file['original_name']);
        $success = 'Share link revoked.';
    }
}

$shares = ShareLink::forFile($fid, $uid);
$page_title = 'Share: ' . $file['original_name'];
require_once __DIR__ . '/includes/header.php';
?>

<div style="margin-bottom:20px">
  <div class="section-title">Share "<?= e($file['original_name']) ?>"</div>
  <div class="section-sub" style="margin-bottom:0">Create a link others can use to download this file — no account required.</div>
</div>

<?php if($success): ?><div class="card" style="border-color:var(--success);color:#6ee7b7;margin-bottom:16px;padding:14px 16px"><?= e($success) ?></div><?php endif; ?>

<?php if($newLink): ?>
<div class="card" style="margin-bottom:20px;padding:18px;border-color:var(--accent)">
  <div style="font-weight:600;margin-bottom:8px">🔗 New Share Link</div>
  <div style="display:flex;gap:8px;align-items:center">
    <input type="text" readonly value="<?= e($newLink) ?>" id="shareUrl" class="form-control" style="font-family:var(--mono);font-size:.82rem">
    <button class="btn btn-primary btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('shareUrl').value)">Copy</button>
  </div>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:20px;padding:20px">
  <div style="font-weight:600;margin-bottom:14px">Create New Link</div>
  <form method="POST" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;align-items:end">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="file_id" value="<?= $fid ?>">
    <div>
      <label style="display:block;font-size:.8rem;color:var(--muted);margin-bottom:6px">Password (optional)</label>
      <input type="text" name="password" class="form-control" placeholder="Leave blank for none">
    </div>
    <div>
      <label style="display:block;font-size:.8rem;color:var(--muted);margin-bottom:6px">Expires</label>
      <select name="expires_in" class="form-control">
        <option value="">Never</option>
        <option value="1h">1 hour</option>
        <option value="24h" selected>24 hours</option>
        <option value="7d">7 days</option>
        <option value="30d">30 days</option>
      </select>
    </div>
    <div>
      <label style="display:block;font-size:.8rem;color:var(--muted);margin-bottom:6px">Max downloads</label>
      <input type="number" name="max_downloads" class="form-control" placeholder="Unlimited" min="1">
    </div>
    <button class="btn btn-primary">+ Create Link</button>
  </form>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">Existing Links</span></div>
  <?php if(empty($shares)): ?>
  <div style="text-align:center;padding:32px;color:var(--muted)">No share links yet for this file.</div>
  <?php else: ?>
  <table>
    <thead><tr><th>Created</th><th>Expires</th><th>Downloads</th><th>Password</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
    <tbody>
    <?php foreach($shares as $s): ?>
    <tr>
      <td style="color:var(--muted);font-size:.82rem"><?= date('M j, Y g:i a', strtotime($s['created_at'])) ?></td>
      <td style="color:var(--muted);font-size:.82rem"><?= $s['expires_at'] ? date('M j, Y g:i a', strtotime($s['expires_at'])) : 'Never' ?></td>
      <td><?= $s['download_count'] ?><?= $s['max_downloads'] ? ' / '.$s['max_downloads'] : '' ?></td>
      <td><?= $s['password_hash'] ? '🔒 Yes' : '— No' ?></td>
      <td>
        <?php if($s['revoked']): ?><span class="badge badge-danger">Revoked</span>
        <?php elseif($s['expires_at'] && strtotime($s['expires_at'])<time()): ?><span class="badge badge-warning">Expired</span>
        <?php else: ?><span class="badge badge-success">Active</span><?php endif; ?>
      </td>
      <td style="text-align:right">
        <?php if(!$s['revoked']): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Revoke this share link?')">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="revoke">
          <input type="hidden" name="file_id" value="<?= $fid ?>">
          <input type="hidden" name="share_id" value="<?= $s['id'] ?>">
          <button class="btn btn-danger btn-sm">Revoke</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
