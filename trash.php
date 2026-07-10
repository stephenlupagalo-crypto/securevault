<?php
require_once __DIR__ . '/includes/config.php';
SessionManager::start();
require_login();

$uid = $_SESSION['user_id'];

// Restore
if (isset($_GET['restore']) && isset($_GET['token'])) {
    if (hash_equals($_SESSION['csrf_token'] ?? '', $_GET['token'])) {
        $fid = (int)$_GET['restore'];
        $fq = db()->prepare('SELECT * FROM files WHERE id=? AND user_id=? AND is_deleted=1');
        $fq->execute([$fid, $uid]);
        $f = $fq->fetch();
        if ($f) {
            db()->prepare('UPDATE files SET is_deleted=0, deleted_at=NULL WHERE id=?')->execute([$fid]);
            db()->prepare('UPDATE users SET storage_used=storage_used+? WHERE id=?')->execute([$f['file_size'], $uid]);
            AuditService::log('file_restored', 'File: '.$f['original_name']);
            $_SESSION['flash']=['type'=>'success','msg'=>'"'.htmlspecialchars($f['original_name']).'" restored.'];
        }
    }
    redirect(BASE_PATH.'trash.php');
}

// Permanent delete
if (isset($_GET['purge']) && isset($_GET['token'])) {
    if (hash_equals($_SESSION['csrf_token'] ?? '', $_GET['token'])) {
        $fid = (int)$_GET['purge'];
        $fq = db()->prepare('SELECT * FROM files WHERE id=? AND user_id=? AND is_deleted=1');
        $fq->execute([$fid, $uid]);
        $f = $fq->fetch();
        if ($f) {
            $path = UPLOAD_DIR . $f['stored_name'];
            if (is_file($path)) @unlink($path);
            db()->prepare('DELETE FROM files WHERE id=?')->execute([$fid]);
            AuditService::log('file_purged', 'Permanently deleted: '.$f['original_name']);
            $_SESSION['flash']=['type'=>'warning','msg'=>'"'.htmlspecialchars($f['original_name']).'" permanently deleted.'];
        }
    }
    redirect(BASE_PATH.'trash.php');
}

// Empty trash
if (isset($_POST['action']) && $_POST['action']==='empty_trash') {
    csrf_check();
    $fq = db()->prepare('SELECT * FROM files WHERE user_id=? AND is_deleted=1');
    $fq->execute([$uid]);
    foreach ($fq->fetchAll() as $f) {
        $path = UPLOAD_DIR . $f['stored_name'];
        if (is_file($path)) @unlink($path);
    }
    db()->prepare('DELETE FROM files WHERE user_id=? AND is_deleted=1')->execute([$uid]);
    AuditService::log('trash_emptied', '');
    $_SESSION['flash']=['type'=>'success','msg'=>'Trash emptied.'];
    redirect(BASE_PATH.'trash.php');
}

$stmt = db()->prepare('SELECT * FROM files WHERE user_id=? AND is_deleted=1 ORDER BY deleted_at DESC');
$stmt->execute([$uid]);
$files = $stmt->fetchAll();
$cat_icons=['document'=>'📄','image'=>'🖼️','video'=>'🎬','audio'=>'🎵','archive'=>'📦','other'=>'📎'];

$page_title = 'Trash';
require_once __DIR__ . '/includes/header.php';
?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px">
  <div>
    <div class="section-title">Trash</div>
    <div class="section-sub" style="margin-bottom:0"><?= count($files) ?> deleted file<?= count($files)!==1?'s':'' ?></div>
  </div>
  <?php if(!empty($files)): ?>
  <form method="POST" onsubmit="return confirm('Permanently delete ALL files in trash? This cannot be undone.')">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="empty_trash">
    <button class="btn btn-danger">🗑 Empty Trash</button>
  </form>
  <?php endif; ?>
</div>

<div class="card">
  <?php if(empty($files)): ?>
  <div style="text-align:center;padding:48px;color:var(--muted)">
    <div style="font-size:3rem;margin-bottom:12px">🗑️</div>
    <div style="font-weight:600;margin-bottom:6px">Trash is empty</div>
    <div style="font-size:.88rem">Deleted files show up here before they're gone for good.</div>
  </div>
  <?php else: ?>
  <table>
    <thead><tr><th>File</th><th>Size</th><th>Deleted</th><th style="text-align:right">Actions</th></tr></thead>
    <tbody>
    <?php foreach($files as $f): ?>
    <tr class="file-row">
      <td>
        <div style="display:flex;align-items:center;gap:10px">
          <span style="font-size:1.4rem"><?= $cat_icons[$f['file_category']] ?? '📎' ?></span>
          <span style="font-weight:500"><?= e($f['original_name']) ?></span>
        </div>
      </td>
      <td style="color:var(--muted)"><?= format_bytes((int)$f['file_size']) ?></td>
      <td style="color:var(--muted);font-size:.82rem"><?= date('M j, Y g:i a', strtotime($f['deleted_at'])) ?></td>
      <td style="text-align:right;white-space:nowrap">
        <a href="trash.php?restore=<?= $f['id'] ?>&token=<?= csrf_token() ?>" class="btn btn-primary btn-sm">↩ Restore</a>
        <a href="trash.php?purge=<?= $f['id'] ?>&token=<?= csrf_token() ?>" class="btn btn-danger btn-sm"
           onclick="return confirm('Permanently delete \"<?= addslashes(e($f['original_name'])) ?>\"? This cannot be undone.')">✕ Delete Forever</a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
