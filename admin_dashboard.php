<?php
require_once __DIR__ . '/includes/config.php';
SessionManager::start();
require_login();
if ($_SESSION['role'] !== 'admin') redirect(BASE_PATH.'dashboard.php');

$db  = db();
$uid = $_SESSION['user_id'];

// ── Core system stats ────────────────────────────────────────
$stats = [
    'users'          => (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'admins'         => (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn(),
    'active_users'   => (int)$db->query('SELECT COUNT(*) FROM users WHERE is_active=1')->fetchColumn(),
    'disabled_users' => (int)$db->query('SELECT COUNT(*) FROM users WHERE is_active=0')->fetchColumn(),
    'active_today'   => (int)$db->query("SELECT COUNT(*) FROM users WHERE last_login >= CURDATE()")->fetchColumn(),
    'new_this_week'  => (int)$db->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn(),
    'locked'         => (int)$db->query("SELECT COUNT(*) FROM users WHERE locked_until IS NOT NULL AND locked_until > NOW()")->fetchColumn(),
    'files'          => (int)$db->query('SELECT COUNT(*) FROM files WHERE is_deleted=0')->fetchColumn(),
    'trashed_files'  => (int)$db->query('SELECT COUNT(*) FROM files WHERE is_deleted=1')->fetchColumn(),
    'storage'        => (int)$db->query('SELECT SUM(file_size) FROM files WHERE is_deleted=0')->fetchColumn(),
    'failed_today'   => (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE success=0 AND attempted_at >= CURDATE()")->fetchColumn(),
    'failed_week'    => (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE success=0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn(),
    'active_shares'  => (int)$db->query('SELECT COUNT(*) FROM share_links WHERE revoked=0 AND (expires_at IS NULL OR expires_at > NOW())')->fetchColumn(),
    'two_fa_enabled' => (int)$db->query('SELECT COUNT(*) FROM two_factor WHERE enabled=1')->fetchColumn(),
];
$stats['two_fa_pct'] = $stats['users'] > 0 ? round($stats['two_fa_enabled'] / $stats['users'] * 100) : 0;

// Storage by file category (system-wide)
$cats = $db->query("SELECT file_category, COUNT(*) AS cnt, SUM(file_size) AS sz FROM files WHERE is_deleted=0 GROUP BY file_category ORDER BY sz DESC")->fetchAll();

// Top 5 users by storage used
$topUsers = $db->query("SELECT full_name, username, storage_used, storage_quota, avatar_color FROM users ORDER BY storage_used DESC LIMIT 5")->fetchAll();

// Recently registered users
$recentUsers = $db->query("SELECT full_name, username, email, role, created_at FROM users ORDER BY created_at DESC LIMIT 6")->fetchAll();

// Recent security-relevant activity (logins, failures, admin actions)
$secEvents = $db->query("
    SELECT l.*, u.full_name, u.username FROM activity_log l
    LEFT JOIN users u ON u.id = l.user_id
    WHERE l.action IN ('login','login_failed','login_blocked','admin_toggle_user','admin_change_role',
                        'admin_change_quota','admin_unlock_account','admin_issue_2fa_code','password_reset')
    ORDER BY l.created_at DESC LIMIT 10
")->fetchAll();

$page_title = 'Admin Dashboard';
require_once __DIR__ . '/includes/header.php';

$cat_icons = ['document'=>'📄','image'=>'🖼️','video'=>'🎬','audio'=>'🎵','archive'=>'📦','other'=>'📎'];
$action_badges = [
    'login'=>'badge-success','login_failed'=>'badge-danger','login_blocked'=>'badge-danger',
    'admin_toggle_user'=>'badge-warning','admin_change_role'=>'badge-warning','admin_change_quota'=>'badge-warning',
    'admin_unlock_account'=>'badge-info','admin_issue_2fa_code'=>'badge-info','password_reset'=>'badge-warning',
];
?>

<div style="margin-bottom:24px">
  <div class="section-title">🛡 System Administrator Dashboard</div>
  <div class="section-sub">Full system overview — accounts, storage, and security across SecureVault</div>
</div>

<!-- TOP STAT CARDS -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin-bottom:24px">
  <div class="card" style="border-left:3px solid var(--accent)">
    <div style="font-size:.78rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px">Total Users</div>
    <div style="font-size:2rem;font-weight:700"><?= $stats['users'] ?></div>
    <div style="font-size:.8rem;color:var(--muted);margin-top:4px"><?= $stats['admins'] ?> admin<?= $stats['admins']!==1?'s':'' ?> · <?= $stats['new_this_week'] ?> new this week</div>
  </div>
  <div class="card" style="border-left:3px solid var(--accent2)">
    <div style="font-size:.78rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px">Active Today</div>
    <div style="font-size:2rem;font-weight:700"><?= $stats['active_today'] ?></div>
    <div style="font-size:.8rem;color:var(--muted);margin-top:4px"><?= $stats['disabled_users'] ?> disabled accounts</div>
  </div>
  <div class="card" style="border-left:3px solid var(--success)">
    <div style="font-size:.78rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px">Total Files</div>
    <div style="font-size:2rem;font-weight:700"><?= number_format($stats['files']) ?></div>
    <div style="font-size:.8rem;color:var(--muted);margin-top:4px"><?= $stats['trashed_files'] ?> in trash</div>
  </div>
  <div class="card" style="border-left:3px solid var(--warning)">
    <div style="font-size:.78rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px">Total Storage</div>
    <div style="font-size:2rem;font-weight:700"><?= format_bytes($stats['storage']) ?></div>
    <div style="font-size:.8rem;color:var(--muted);margin-top:4px">across all users</div>
  </div>
  <div class="card" style="border-left:3px solid <?= $stats['failed_today']>10?'var(--danger)':'var(--border2)' ?>">
    <div style="font-size:.78rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px">Failed Logins (24h)</div>
    <div style="font-size:2rem;font-weight:700;color:<?= $stats['failed_today']>10?'var(--danger)':'inherit' ?>"><?= $stats['failed_today'] ?></div>
    <div style="font-size:.8rem;color:var(--muted);margin-top:4px"><?= $stats['failed_week'] ?> in last 7 days</div>
  </div>
  <div class="card" style="border-left:3px solid <?= $stats['locked']>0?'var(--warning)':'var(--border2)' ?>">
    <div style="font-size:.78rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px">Locked Accounts</div>
    <div style="font-size:2rem;font-weight:700;color:<?= $stats['locked']>0?'var(--warning)':'inherit' ?>"><?= $stats['locked'] ?></div>
    <div style="font-size:.8rem;color:var(--muted);margin-top:4px"><a href="admin_users.php" style="color:var(--accent);text-decoration:none">manage →</a></div>
  </div>
  <div class="card" style="border-left:3px solid var(--accent)">
    <div style="font-size:.78rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px">2FA Adoption</div>
    <div style="font-size:2rem;font-weight:700"><?= $stats['two_fa_pct'] ?>%</div>
    <div style="font-size:.8rem;color:var(--muted);margin-top:4px"><?= $stats['two_fa_enabled'] ?> of <?= $stats['users'] ?> users</div>
  </div>
  <div class="card" style="border-left:3px solid var(--accent2)">
    <div style="font-size:.78rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px">Active Share Links</div>
    <div style="font-size:2rem;font-weight:700"><?= $stats['active_shares'] ?></div>
    <div style="font-size:.8rem;color:var(--muted);margin-top:4px">live &amp; unexpired</div>
  </div>
</div>

<!-- QUICK ACTIONS -->
<div class="card" style="margin-bottom:24px">
  <div class="card-header"><span class="card-title">Quick Actions</span></div>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <a href="admin_users.php" class="btn btn-primary btn-sm">👥 Manage Users</a>
    <a href="admin_logs.php" class="btn btn-ghost btn-sm">📋 Full Activity Logs</a>
    <a href="admin_users.php#locked" class="btn btn-ghost btn-sm">🔓 Unlock Accounts</a>
  </div>
</div>

<div style="display:grid;grid-template-columns:1.3fr 1fr;gap:20px;align-items:start;margin-bottom:20px">

  <!-- SECURITY EVENTS -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Recent Security &amp; Account Activity</span>
      <a href="admin_logs.php" class="btn btn-ghost btn-sm">View All</a>
    </div>
    <?php if(empty($secEvents)): ?>
    <div style="text-align:center;padding:24px;color:var(--muted);font-size:.88rem">No recent events.</div>
    <?php else: ?>
    <table>
      <thead><tr><th>Time</th><th>User</th><th>Event</th><th>Detail</th></tr></thead>
      <tbody>
      <?php foreach($secEvents as $ev): ?>
      <tr>
        <td style="white-space:nowrap;font-size:.78rem;color:var(--muted)"><?= date('M j, g:i a', strtotime($ev['created_at'])) ?></td>
        <td style="font-size:.85rem"><?= $ev['username'] ? '@'.e($ev['username']) : '<span style="color:var(--muted)">—</span>' ?></td>
        <td><span class="badge <?= $action_badges[$ev['action']] ?? 'badge-info' ?>"><?= e(str_replace('_',' ',$ev['action'])) ?></span></td>
        <td style="font-size:.8rem;color:var(--muted);max-width:220px;overflow:hidden;text-overflow:ellipsis"><?= e($ev['detail'] ?? '—') ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- STORAGE BY CATEGORY -->
  <div class="card">
    <div class="card-header"><span class="card-title">Storage by File Type</span></div>
    <?php if(empty($cats)): ?>
    <div style="text-align:center;padding:24px;color:var(--muted);font-size:.88rem">No files yet.</div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:12px">
      <?php $maxSz = max(array_column($cats,'sz')) ?: 1; foreach($cats as $c): $w = round(($c['sz']/$maxSz)*100); ?>
      <div>
        <div style="display:flex;justify-content:space-between;font-size:.83rem;margin-bottom:4px">
          <span><?= $cat_icons[$c['file_category']] ?? '📎' ?> <?= ucfirst(e($c['file_category'])) ?> <span style="color:var(--muted)">(<?= $c['cnt'] ?>)</span></span>
          <span style="color:var(--muted)"><?= format_bytes((int)$c['sz']) ?></span>
        </div>
        <div style="height:6px;border-radius:3px;background:var(--surface2);overflow:hidden">
          <div style="height:100%;width:<?= $w ?>%;background:linear-gradient(90deg,var(--accent),var(--accent2));border-radius:3px"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">

  <!-- TOP STORAGE USERS -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Top Storage Users</span>
      <a href="admin_users.php" class="btn btn-ghost btn-sm">All Users</a>
    </div>
    <div style="display:flex;flex-direction:column;gap:12px">
      <?php foreach($topUsers as $u):
        $pct = $u['storage_quota']>0 ? min(100,round($u['storage_used']/$u['storage_quota']*100)) : 0;
        $ini = substr(implode('', array_map(fn($w)=>strtoupper($w[0]), explode(' ', $u['full_name']))),0,2);
      ?>
      <div style="display:flex;align-items:center;gap:10px">
        <div style="width:32px;height:32px;border-radius:50%;background:<?= e($u['avatar_color']) ?>;display:grid;place-items:center;font-size:.75rem;font-weight:700;flex-shrink:0"><?= e($ini) ?></div>
        <div style="flex:1;min-width:0">
          <div style="font-size:.85rem;font-weight:600"><?= e($u['full_name']) ?></div>
          <div style="height:4px;border-radius:2px;background:var(--surface2);overflow:hidden;margin-top:3px">
            <div style="height:100%;width:<?= $pct ?>%;background:<?= $pct>90?'var(--danger)':'var(--accent)' ?>;border-radius:2px"></div>
          </div>
        </div>
        <div style="font-size:.78rem;color:var(--muted);white-space:nowrap"><?= format_bytes((int)$u['storage_used']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- RECENTLY REGISTERED -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Recently Registered</span>
      <a href="admin_users.php" class="btn btn-ghost btn-sm">All Users</a>
    </div>
    <?php if(empty($recentUsers)): ?>
    <div style="text-align:center;padding:24px;color:var(--muted);font-size:.88rem">No users yet.</div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:10px">
      <?php foreach($recentUsers as $u): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;font-size:.85rem">
        <div>
          <div style="font-weight:600"><?= e($u['full_name']) ?> <?php if($u['role']==='admin'): ?><span class="badge badge-info" style="margin-left:4px">admin</span><?php endif; ?></div>
          <div style="font-size:.73rem;color:var(--muted)">@<?= e($u['username']) ?></div>
        </div>
        <div style="font-size:.75rem;color:var(--muted)"><?= date('M j', strtotime($u['created_at'])) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
