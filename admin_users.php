<?php
require_once __DIR__ . '/includes/config.php';
SessionManager::start();
require_login();
if ($_SESSION['role'] !== 'admin') redirect(BASE_PATH.'dashboard.php');

$uid = $_SESSION['user_id'];

// Toggle active
if (isset($_GET['toggle']) && isset($_GET['token'])) {
    if (hash_equals($_SESSION['csrf_token']??'', $_GET['token'])) {
        $tid = (int)$_GET['toggle'];
        if ($tid !== $uid) {
            db()->prepare('UPDATE users SET is_active=1-is_active WHERE id=?')->execute([$tid]);
            log_activity('admin_toggle_user', "user_id=$tid");
        }
    }
    redirect(BASE_PATH.'admin_users.php');
}

// Change role
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['change_role'])) {
    csrf_check();
    $tid  = (int)$_POST['user_id'];
    $role = in_array($_POST['role'],['admin','user'])?$_POST['role']:'user';
    if ($tid !== $uid) {
        db()->prepare('UPDATE users SET role=? WHERE id=?')->execute([$role,$tid]);
        log_activity('admin_change_role',"user_id=$tid role=$role");
    }
    redirect(BASE_PATH.'admin_users.php');
}

// Change quota
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['change_quota'])) {
    csrf_check();
    $tid   = (int)$_POST['user_id'];
    $quota = (int)$_POST['quota_gb'] * 1073741824;
    if ($quota > 0) {
        db()->prepare('UPDATE users SET storage_quota=? WHERE id=?')->execute([$quota,$tid]);
        log_activity('admin_change_quota',"user_id=$tid quota={$_POST['quota_gb']}GB");
    }
    redirect(BASE_PATH.'admin_users.php');
}

// Unlock a locked-out account
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['unlock_account'])) {
    csrf_check();
    $tid = (int)$_POST['user_id'];
    db()->prepare('UPDATE users SET locked_until=NULL, failed_attempts=0 WHERE id=?')->execute([$tid]);
    AuditService::log('admin_unlock_account', "user_id=$tid");
    redirect(BASE_PATH.'admin_users.php');
}

// Issue a fixed (admin-set) 2FA verification code — e.g. lost phone / SMS unreachable
$issuedCode = null; $issuedForUser = null;
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['issue_admin_code'])) {
    csrf_check();
    $tid  = (int)$_POST['user_id'];
    $note = trim($_POST['note'] ?? '');
    $code = TwoFactorAuth::generateAdminCode();
    $hash = TwoFactorAuth::hashAdminCode($code);
    $expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));

    $exists = db()->prepare('SELECT user_id FROM two_factor WHERE user_id=?');
    $exists->execute([$tid]);
    if ($exists->fetch()) {
        db()->prepare('UPDATE two_factor SET admin_code_hash=?, admin_code_expires=?, admin_code_note=? WHERE user_id=?')
            ->execute([$hash, $expires, $note, $tid]);
    } else {
        db()->prepare('INSERT INTO two_factor (user_id, method, enabled, admin_code_hash, admin_code_expires, admin_code_note) VALUES (?,?,0,?,?,?)')
            ->execute([$tid, 'none', $hash, $expires, $note]);
    }
    AuditService::log('admin_issue_2fa_code', "user_id=$tid note=$note");
    $issuedCode = $code;
    $issuedForUser = $tid;
}

$users = db()->query('SELECT u.*, (SELECT COUNT(*) FROM files WHERE user_id=u.id AND is_deleted=0) AS file_count FROM users u ORDER BY created_at DESC')->fetchAll();

$page_title = 'Manage Users';
require_once __DIR__ . '/includes/header.php';
?>

<?php if($issuedCode): ?>
<div class="card" style="margin-bottom:16px;padding:16px;border-color:var(--accent)">
  <div style="font-weight:600;margin-bottom:6px">🔑 Fixed Verification Code Issued</div>
  <div style="font-size:.85rem;color:var(--muted);margin-bottom:8px">
    Give this code to the user directly (phone call, in person). It works once in place of their normal 2FA code, and expires in 30 minutes.
  </div>
  <div style="font-family:var(--mono);font-size:1.4rem;letter-spacing:.2em;background:var(--surface2);padding:10px 16px;border-radius:8px;display:inline-block"><?= e($issuedCode) ?></div>
</div>
<?php endif; ?>

<div class="section-title">User Management</div>
<div class="section-sub"><?= count($users) ?> registered accounts</div>

<div class="card">
<table>
  <thead><tr>
    <th>User</th><th>Email</th><th>Role</th><th>Files</th><th>Storage</th><th>Status</th><th>Last Login</th><th style="text-align:right">Actions</th>
  </tr></thead>
  <tbody>
  <?php foreach($users as $u): ?>
  <?php
    $ini = implode('', array_map(fn($w)=>strtoupper($w[0]), explode(' ', $u['full_name'])));
    $ini = substr($ini,0,2);
    $pct = $u['storage_quota']>0?min(100,round($u['storage_used']/$u['storage_quota']*100)):0;
  ?>
  <tr>
    <td>
      <div style="display:flex;align-items:center;gap:10px">
        <div style="width:34px;height:34px;border-radius:50%;background:<?= e($u['avatar_color']) ?>;display:grid;place-items:center;font-size:.8rem;font-weight:700;flex-shrink:0"><?= e($ini) ?></div>
        <div>
          <div style="font-weight:600;font-size:.9rem"><?= e($u['full_name']) ?></div>
          <div style="font-size:.73rem;color:var(--muted)">@<?= e($u['username']) ?></div>
        </div>
      </div>
    </td>
    <td style="font-size:.85rem;color:var(--muted)"><?= e($u['email']) ?></td>
    <td>
      <form method="POST" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="change_role" value="1">
        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
        <select name="role" class="form-control" style="padding:4px 8px;font-size:.8rem;width:auto"
                onchange="this.form.submit()" <?= $u['id']===$uid?'disabled':'' ?>>
          <option value="user"  <?= $u['role']==='user'?'selected':'' ?>>User</option>
          <option value="admin" <?= $u['role']==='admin'?'selected':'' ?>>Admin</option>
        </select>
      </form>
    </td>
    <td style="font-size:.88rem"><?= number_format((int)$u['file_count']) ?></td>
    <td style="min-width:120px">
      <div style="font-size:.78rem;color:var(--muted);margin-bottom:3px"><?= format_bytes((int)$u['storage_used']) ?> / <?= format_bytes((int)$u['storage_quota']) ?></div>
      <div style="height:4px;border-radius:2px;background:var(--surface2);overflow:hidden">
        <div style="height:100%;width:<?= $pct ?>%;background:<?= $pct>90?'var(--danger)':($pct>70?'var(--warning)':'var(--accent)') ?>;border-radius:2px"></div>
      </div>
      <form method="POST" style="display:flex;gap:4px;margin-top:4px">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="change_quota" value="1">
        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
        <input type="number" name="quota_gb" min="1" max="1000" placeholder="GB"
               style="width:60px;padding:3px 6px;font-size:.75rem;background:var(--surface2);border:1px solid var(--border);border-radius:6px;color:var(--text)">
        <button type="submit" class="btn btn-ghost btn-sm" style="padding:3px 8px;font-size:.73rem">Set</button>
      </form>
    </td>
    <td>
      <span class="badge <?= $u['is_active']?'badge-success':'badge-danger' ?>">
        <?= $u['is_active']?'Active':'Disabled' ?>
      </span>
    </td>
    <td style="font-size:.8rem;color:var(--muted)"><?= $u['last_login']?date('M j, Y', strtotime($u['last_login'])):'Never' ?></td>
    <td style="text-align:right;white-space:nowrap">
      <?php if($u['id']!==$uid): ?>
      <a href="?toggle=<?= $u['id'] ?>&token=<?= csrf_token() ?>"
         class="btn <?= $u['is_active']?'btn-danger':'btn-success' ?> btn-sm"
         onclick="return confirm('<?= $u['is_active']?'Disable':'Enable' ?> this user?')">
        <?= $u['is_active']?'Disable':'Enable' ?>
      </a>
      <?php if($u['locked_until'] && strtotime($u['locked_until'])>time()): ?>
      <form method="POST" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="unlock_account" value="1">
        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
        <button class="btn btn-warning btn-sm" title="Account is locked from failed logins">🔓 Unlock</button>
      </form>
      <?php endif; ?>
      <button class="btn btn-ghost btn-sm" onclick="issueCode(<?= $u['id'] ?>,'<?= addslashes(e($u['full_name'])) ?>')" title="Issue fixed 2FA override code">🔑 2FA Code</button>
      <?php else: ?>
      <span style="font-size:.78rem;color:var(--muted)">You</span>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<script>
function issueCode(userId, name) {
  const note = prompt('Reason for issuing a fixed 2FA override code for ' + name + ' (e.g. "lost phone"):', '');
  if (note === null) return;
  const form = document.createElement('form');
  form.method = 'POST';
  form.innerHTML = `
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="issue_admin_code" value="1">
    <input type="hidden" name="user_id" value="${userId}">
    <input type="hidden" name="note" value="${note.replace(/"/g,'&quot;')}">`;
  document.body.appendChild(form);
  form.submit();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
