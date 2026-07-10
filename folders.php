<?php
require_once __DIR__ . '/includes/config.php';
SessionManager::start();
require_login();

$uid = $_SESSION['user_id'];
$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $error = 'Folder name is required.';
        } else {
            Folder::create($uid, $name);
            AuditService::log('folder_created', "Folder: $name");
            $success = 'Folder created.';
        }
    } elseif ($action === 'rename') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if (Folder::find($id, $uid) && $name !== '') {
            Folder::rename($id, $uid, $name);
            AuditService::log('folder_renamed', "Folder #$id -> $name");
            $success = 'Folder renamed.';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (Folder::find($id, $uid)) {
            Folder::delete($id, $uid);
            AuditService::log('folder_deleted', "Folder #$id");
            $success = 'Folder deleted. Files inside were moved to "Unfiled".';
        }
    }
}

$folders = Folder::forUser($uid);
$page_title = 'Folders';
require_once __DIR__ . '/includes/header.php';
?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px">
  <div>
    <div class="section-title">Folders</div>
    <div class="section-sub" style="margin-bottom:0">Organize your files into folders</div>
  </div>
</div>

<?php if($error): ?><div class="card" style="border-color:var(--danger);color:#fca5a5;margin-bottom:16px;padding:14px 16px"><?= e($error) ?></div><?php endif; ?>
<?php if($success): ?><div class="card" style="border-color:var(--success);color:#6ee7b7;margin-bottom:16px;padding:14px 16px"><?= e($success) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:20px;padding:16px">
  <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="create">
    <input type="text" name="name" class="form-control" placeholder="New folder name…" style="max-width:260px" required>
    <button class="btn btn-primary">+ Create Folder</button>
  </form>
</div>

<div class="card">
  <?php if(empty($folders)): ?>
  <div style="text-align:center;padding:48px;color:var(--muted)">
    <div style="font-size:3rem;margin-bottom:12px">🗂️</div>
    <div style="font-weight:600;margin-bottom:6px">No folders yet</div>
    <div style="font-size:.88rem">Create one above to start organizing your files.</div>
  </div>
  <?php else: ?>
  <table>
    <thead><tr><th>Folder</th><th>Files</th><th style="text-align:right">Actions</th></tr></thead>
    <tbody>
    <?php foreach($folders as $f): ?>
    <tr>
      <td><span style="font-weight:500">🗂️ <?= e($f['name']) ?></span></td>
      <td style="color:var(--muted)"><?= Folder::fileCount($f['id']) ?> file(s)</td>
      <td style="text-align:right;white-space:nowrap">
        <a href="files.php?folder=<?= $f['id'] ?>" class="btn btn-ghost btn-sm">Open</a>
        <button class="btn btn-ghost btn-sm" onclick="renameFolder(<?= $f['id'] ?>,'<?= addslashes(e($f['name'])) ?>')">Rename</button>
        <form method="POST" style="display:inline" onsubmit="return confirm('Delete folder \'<?= addslashes(e($f['name'])) ?>\'? Files inside will be unfiled, not deleted.')">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $f['id'] ?>">
          <button class="btn btn-danger btn-sm">Delete</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<script>
function renameFolder(id, currentName) {
  const name = prompt('Rename folder to:', currentName);
  if (!name) return;
  const form = document.createElement('form');
  form.method = 'POST';
  form.innerHTML = `
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="rename">
    <input type="hidden" name="id" value="${id}">
    <input type="hidden" name="name" value="${name.replace(/"/g,'&quot;')}">`;
  document.body.appendChild(form);
  form.submit();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
