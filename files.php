<?php
require_once __DIR__ . '/includes/config.php';
SessionManager::start();
require_login();

$uid = $_SESSION['user_id'];

// Delete action
if (isset($_GET['delete']) && isset($_GET['token'])) {
    if (hash_equals($_SESSION['csrf_token'] ?? '', $_GET['token'])) {
        $fid = (int)$_GET['delete'];
        $fq  = db()->prepare('SELECT * FROM files WHERE id=? AND user_id=? AND is_deleted=0');
        $fq->execute([$fid, $uid]);
        $f = $fq->fetch();
        if ($f) {
            db()->prepare('UPDATE files SET is_deleted=1, deleted_at=NOW() WHERE id=?')->execute([$fid]);
            db()->prepare('UPDATE users SET storage_used=MAX(0,storage_used-?) WHERE id=?')->execute([$f['file_size'], $uid]);
            log_activity('delete_file', 'File: '.$f['original_name']);
            add_alert($uid,'warning','File Deleted','"'.$f['original_name'].'" was deleted.');
            $_SESSION['flash']=['type'=>'success','msg'=>'"'.htmlspecialchars($f['original_name']).'" deleted.'];
        }
    }
    redirect(BASE_PATH.'files.php');
}

// Move-to-folder action
if (isset($_POST['action']) && $_POST['action'] === 'move_folder') {
    csrf_check();
    $fid = (int)($_POST['file_id'] ?? 0);
    $folderId = $_POST['folder_id'] !== '' ? (int)$_POST['folder_id'] : null;
    if ($folderId === null || Folder::find($folderId, $uid)) {
        db()->prepare('UPDATE files SET folder_id=? WHERE id=? AND user_id=?')->execute([$folderId, $fid, $uid]);
        AuditService::log('file_moved', "File #$fid -> folder " . ($folderId ?? 'none'));
    }
    redirect(BASE_PATH.'files.php' . (isset($_GET['folder']) ? '?folder='.(int)$_GET['folder'] : ''));
}

// Filters
$cat    = $_GET['cat']    ?? '';
$search = trim($_GET['q'] ?? '');
$sort   = in_array($_GET['sort']??'',['created_at','file_size','original_name'])?$_GET['sort']:'created_at';
$dir    = ($_GET['dir']??'DESC')==='ASC'?'ASC':'DESC';
$folderFilter = isset($_GET['folder']) ? (int)$_GET['folder'] : null;

$where  = 'user_id=:uid AND is_deleted=0';
$params = [':uid'=>$uid];
if ($cat) { $where.=' AND file_category=:cat'; $params[':cat']=$cat; }
if ($search) { $where.=' AND original_name LIKE :q'; $params[':q']="%$search%"; }
if ($folderFilter !== null) { $where.=' AND folder_id=:fid'; $params[':fid']=$folderFilter; }

$stmt = db()->prepare("SELECT * FROM files WHERE $where ORDER BY $sort $dir");
$stmt->execute($params);
$files = $stmt->fetchAll();
$userFolders = Folder::forUser($uid);
$folderNames = array_column($userFolders, 'name', 'id');

$cat_icons=['document'=>'📄','image'=>'🖼️','video'=>'🎬','audio'=>'🎵','archive'=>'📦','other'=>'📎'];
$page_title='My Files';
require_once __DIR__ . '/includes/header.php';
?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px">
  <div>
    <div class="section-title">My Files</div>
    <div class="section-sub" style="margin-bottom:0"><?= count($files) ?> file<?= count($files)!==1?'s':'' ?> <?= $search ? 'matching "'.e($search).'"' : '' ?></div>
  </div>
  <a href="upload.php" class="btn btn-primary">⬆ Upload New</a>
</div>

<!-- FILTER BAR -->
<div class="card" style="margin-bottom:20px;padding:14px 16px">
  <form method="GET" action="" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <input type="text" name="q" class="form-control" placeholder="Search files…" value="<?= e($search) ?>" style="max-width:220px">
    <select name="cat" class="form-control" style="max-width:160px" onchange="this.form.submit()">
      <option value="">All Types</option>
      <?php foreach(['document','image','video','audio','archive','other'] as $c): ?>
      <option value="<?= $c ?>" <?= $cat===$c?'selected':'' ?>><?= ucfirst($c) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="folder" class="form-control" style="max-width:170px" onchange="this.form.submit()">
      <option value="">All Folders</option>
      <?php foreach($userFolders as $fo): ?>
      <option value="<?= $fo['id'] ?>" <?= $folderFilter===(int)$fo['id']?'selected':'' ?>>🗂️ <?= e($fo['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="sort" class="form-control" style="max-width:160px" onchange="this.form.submit()">
      <option value="created_at" <?= $sort==='created_at'?'selected':'' ?>>Sort: Date</option>
      <option value="original_name" <?= $sort==='original_name'?'selected':'' ?>>Sort: Name</option>
      <option value="file_size" <?= $sort==='file_size'?'selected':'' ?>>Sort: Size</option>
    </select>
    <button type="submit" class="btn btn-ghost">🔍 Filter</button>
    <?php if($search||$cat): ?><a href="files.php" class="btn btn-ghost">✕ Clear</a><?php endif; ?>
  </form>
</div>

<!-- FILES TABLE -->
<div class="card">
  <?php if(empty($files)): ?>
  <div style="text-align:center;padding:48px;color:var(--muted)">
    <div style="font-size:3rem;margin-bottom:12px">📂</div>
    <div style="font-size:1rem;font-weight:600;margin-bottom:6px">No files found</div>
    <div style="font-size:.88rem;margin-bottom:16px">
      <?= $search||$cat ? 'Try a different filter.' : 'Upload your first file to get started.' ?>
    </div>
    <a href="upload.php" class="btn btn-primary">⬆ Upload File</a>
  </div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>File</th>
        <th>Type</th>
        <th>Size</th>
        <th>Uploaded</th>
        <th>Encrypted</th>
        <th style="text-align:right">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($files as $f): ?>
    <tr class="file-row">
      <td>
        <div style="display:flex;align-items:center;gap:10px">
          <div style="font-size:1.4rem;flex-shrink:0"><?= $cat_icons[$f['file_category']] ?? '📎' ?></div>
          <div>
            <div style="font-weight:500;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($f['original_name']) ?>">
              <?= e($f['original_name']) ?>
            </div>
            <?php if($f['description']): ?>
            <div style="font-size:.75rem;color:var(--muted)"><?= e(mb_strimwidth($f['description'],0,60,'…')) ?></div>
            <?php endif; ?>
          </div>
        </div>
      </td>
      <td><span class="badge badge-info"><?= e(ucfirst($f['file_category'])) ?></span></td>
      <td style="color:var(--muted);white-space:nowrap"><?= format_bytes((int)$f['file_size']) ?></td>
      <td style="color:var(--muted);font-size:.82rem;white-space:nowrap"><?= date('M j, Y', strtotime($f['created_at'])) ?><br><span style="color:var(--muted2)"><?= date('g:i a', strtotime($f['created_at'])) ?></span></td>
      <td><span class="badge badge-success">🔒 AES-256</span></td>
      <td style="text-align:right;white-space:nowrap">
        <a href="preview.php?id=<?= $f['id'] ?>" class="btn btn-ghost btn-sm" title="Preview">👁</a>
        <a href="download.php?id=<?= $f['id'] ?>" class="btn btn-ghost btn-sm" title="Download">⬇</a>
        <a href="share.php?id=<?= $f['id'] ?>" class="btn btn-ghost btn-sm" title="Share">🔗</a>
        <button class="btn btn-ghost btn-sm" title="Move to folder" onclick="moveFolder(<?= $f['id'] ?>)">🗂️</button>
        <a href="files.php?delete=<?= $f['id'] ?>&token=<?= csrf_token() ?>"
           class="btn btn-danger btn-sm"
           onclick="return confirm('Move \"<?= addslashes(e($f['original_name'])) ?>\" to Trash?')"
           title="Delete">🗑</a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<script>
const SV_FOLDERS = <?= json_encode($folderNames, JSON_HEX_TAG) ?>;
function moveFolder(fileId) {
  let opts = 'Unfiled (0)\n';
  const ids = Object.keys(SV_FOLDERS);
  ids.forEach((id,i)=> opts += `${i+1}. ${SV_FOLDERS[id]} (${id})\n`);
  const choice = prompt('Move to folder — enter folder ID, or 0 for Unfiled:\n' + opts, '0');
  if (choice === null) return;
  const folderId = choice.trim() === '0' ? '' : choice.trim();
  const form = document.createElement('form');
  form.method = 'POST';
  form.innerHTML = `
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="move_folder">
    <input type="hidden" name="file_id" value="${fileId}">
    <input type="hidden" name="folder_id" value="${folderId}">`;
  document.body.appendChild(form);
  form.submit();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
