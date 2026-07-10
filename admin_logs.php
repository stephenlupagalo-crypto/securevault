<?php
require_once __DIR__ . '/includes/config.php';
SessionManager::start();
require_login();
if ($_SESSION['role'] !== 'admin') redirect(BASE_PATH.'dashboard.php');

$logs = db()->query('SELECT l.*, u.full_name, u.username FROM activity_log l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.created_at DESC LIMIT 200')->fetchAll();

$page_title = 'Activity Logs';
require_once __DIR__ . '/includes/header.php';

$action_badges = [
    'login'         => 'badge-success',
    'login_failed'  => 'badge-danger',
    'logout'        => 'badge-info',
    'upload'        => 'badge-info',
    'download'      => 'badge-info',
    'delete_file'   => 'badge-warning',
    'password_change'=>'badge-warning',
    'password_reset'=>'badge-warning',
    'register'      => 'badge-success',
    'profile_update'=>'badge-info',
];
?>

<div class="section-title">Activity Logs</div>
<div class="section-sub">Last 200 system events</div>

<div class="card">
<table>
  <thead><tr>
    <th>Time</th><th>User</th><th>Action</th><th>Detail</th><th>IP</th>
  </tr></thead>
  <tbody>
  <?php foreach($logs as $l): ?>
  <tr>
    <td style="white-space:nowrap;font-size:.8rem;color:var(--muted)"><?= date('M j, Y g:i a', strtotime($l['created_at'])) ?></td>
    <td style="font-size:.85rem">
      <?php if($l['full_name']): ?>
      <div style="font-weight:600"><?= e($l['full_name']) ?></div>
      <div style="font-size:.73rem;color:var(--muted)">@<?= e($l['username']) ?></div>
      <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
    </td>
    <td><span class="badge <?= $action_badges[$l['action']] ?? 'badge-info' ?>"><?= e(str_replace('_',' ',$l['action'])) ?></span></td>
    <td style="font-size:.82rem;color:var(--muted);max-width:280px;overflow:hidden;text-overflow:ellipsis"><?= e($l['detail'] ?? '—') ?></td>
    <td style="font-size:.8rem;color:var(--muted2);font-family:var(--mono)"><?= e($l['ip_address'] ?? '—') ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
