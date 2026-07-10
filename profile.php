<?php
require_once __DIR__ . '/includes/config.php';
SessionManager::start();
require_login();

$uid   = $_SESSION['user_id'];
$error = $success = '';
$colors = ['#3b82f6','#6366f1','#10b981','#f59e0b','#ef4444','#ec4899','#14b8a6','#8b5cf6'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email']     ?? '');
    $color     = in_array($_POST['color']??'', $colors) ? $_POST['color'] : $_SESSION['avatar_color'];

    if (!$full_name || !$email) {
        $error = 'Name and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } else {
        $chk = db()->prepare('SELECT id FROM users WHERE email=? AND id!=?');
        $chk->execute([$email, $uid]);
        if ($chk->fetch()) {
            $error = 'That email is already used by another account.';
        } else {
            db()->prepare('UPDATE users SET full_name=?, email=?, avatar_color=? WHERE id=?')
                 ->execute([$full_name, $email, $color, $uid]);
            $_SESSION['full_name']    = $full_name;
            $_SESSION['avatar_color'] = $color;
            log_activity('profile_update');
            $success = 'Profile updated successfully.';
        }
    }
}

$uq = db()->prepare('SELECT * FROM users WHERE id=?');
$uq->execute([$uid]);
$user = $uq->fetch();

// Recent activity
$aq = db()->prepare('SELECT * FROM activity_log WHERE user_id=? ORDER BY created_at DESC LIMIT 10');
$aq->execute([$uid]);
$activity = $aq->fetchAll();

$page_title = 'My Profile';
require_once __DIR__ . '/includes/header.php';

$initials = implode('', array_map(fn($w)=>strtoupper($w[0]), explode(' ', $user['full_name'])));
$initials = substr($initials, 0, 2);
?>

<div class="section-title">My Profile</div>
<div class="section-sub">Manage your personal information and account settings.</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start">

<!-- PROFILE FORM -->
<div class="card">
  <div style="display:flex;align-items:center;gap:16px;margin-bottom:24px;padding-bottom:20px;border-bottom:1px solid var(--border)">
    <div style="width:64px;height:64px;border-radius:50%;background:<?= e($user['avatar_color']) ?>;display:grid;place-items:center;font-size:1.4rem;font-weight:700;flex-shrink:0">
      <?= e($initials) ?>
    </div>
    <div>
      <div style="font-size:1.2rem;font-weight:700"><?= e($user['full_name']) ?></div>
      <div style="color:var(--muted);font-size:.85rem">@<?= e($user['username']) ?></div>
      <div style="margin-top:4px"><span class="badge <?= $user['role']==='admin'?'badge-warning':'badge-info' ?>"><?= ucfirst(e($user['role'])) ?></span></div>
    </div>
  </div>

  <?php if($error): ?><div class="flash flash-error">⚠️ <?= e($error) ?></div><?php endif; ?>
  <?php if($success): ?><div class="flash flash-success">✅ <?= e($success) ?></div><?php endif; ?>

  <form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="color" id="colorInput" value="<?= e($user['avatar_color']) ?>">

    <div class="form-group">
      <label class="form-label">Full Name</label>
      <input type="text" name="full_name" class="form-control" value="<?= e($user['full_name']) ?>" required>
    </div>
    <div class="form-group">
      <label class="form-label">Email Address</label>
      <input type="email" name="email" class="form-control" value="<?= e($user['email']) ?>" required>
    </div>
    <div class="form-group">
      <label class="form-label">Username (read-only)</label>
      <input type="text" class="form-control" value="<?= e($user['username']) ?>" readonly style="opacity:.6;cursor:not-allowed">
    </div>
    <div class="form-group">
      <label class="form-label">Avatar Color</label>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px">
        <?php foreach($colors as $c): ?>
        <div style="width:32px;height:32px;border-radius:50%;background:<?= $c ?>;cursor:pointer;border:2px solid <?= $user['avatar_color']===$c?'#fff':'transparent' ?>;transition:transform .15s,border-color .15s"
             onclick="pickColor('<?= $c ?>',this)"
             class="cp"></div>
        <?php endforeach; ?>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">💾 Save Changes</button>
  </form>
</div>

<!-- ACCOUNT INFO + ACTIVITY -->
<div>
  <div class="card" style="margin-bottom:20px">
    <div class="card-title" style="margin-bottom:16px">Account Stats</div>
    <div style="display:flex;flex-direction:column;gap:12px">
      <div style="display:flex;justify-content:space-between;font-size:.88rem">
        <span style="color:var(--muted)">Member since</span>
        <span style="font-weight:600"><?= date('F j, Y', strtotime($user['created_at'])) ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:.88rem">
        <span style="color:var(--muted)">Last login</span>
        <span style="font-weight:600"><?= $user['last_login'] ? date('M j, Y g:i a', strtotime($user['last_login'])) : 'N/A' ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:.88rem">
        <span style="color:var(--muted)">Storage used</span>
        <span style="font-weight:600"><?= format_bytes((int)$user['storage_used']) ?> / <?= format_bytes((int)$user['storage_quota']) ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:.88rem">
        <span style="color:var(--muted)">Role</span>
        <span class="badge <?= $user['role']==='admin'?'badge-warning':'badge-info' ?>"><?= ucfirst(e($user['role'])) ?></span>
      </div>
    </div>
    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border)">
      <a href="change_password.php" class="btn btn-ghost" style="width:100%;justify-content:center">🔑 Change Password</a>
    </div>
  </div>

  <div class="card">
    <div class="card-title" style="margin-bottom:14px">Recent Activity</div>
    <?php if(empty($activity)): ?>
    <div style="color:var(--muted);font-size:.88rem">No activity yet.</div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:8px">
      <?php foreach($activity as $a): ?>
      <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:.83rem">
        <span style="color:var(--text);font-weight:500"><?= e(ucwords(str_replace('_',' ',$a['action']))) ?></span>
        <span style="color:var(--muted2)"><?= date('M j, g:i a', strtotime($a['created_at'])) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

</div>

<script>
function pickColor(c,el){
  document.getElementById('colorInput').value=c;
  document.querySelectorAll('.cp').forEach(e=>e.style.borderColor='transparent');
  el.style.borderColor='#fff';
  el.style.transform='scale(1.15)';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
