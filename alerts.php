<?php
require_once __DIR__ . '/includes/config.php';
SessionManager::start();
require_login();

$uid = $_SESSION['user_id'];

// Mark all as read
if (isset($_POST['mark_all_read'])) {
    csrf_check();
    db()->prepare('UPDATE alerts SET is_read=1 WHERE user_id=?')->execute([$uid]);
    redirect(BASE_PATH.'alerts.php');
}

// Mark single as read
if (isset($_GET['read'])) {
    $aid = (int)$_GET['read'];
    db()->prepare('UPDATE alerts SET is_read=1 WHERE id=? AND user_id=?')->execute([$aid, $uid]);
    redirect(BASE_PATH.'alerts.php');
}

// Delete alert
if (isset($_GET['del']) && isset($_GET['token'])) {
    if (hash_equals($_SESSION['csrf_token'] ?? '', $_GET['token'])) {
        $aid = (int)$_GET['del'];
        db()->prepare('DELETE FROM alerts WHERE id=? AND user_id=?')->execute([$aid, $uid]);
    }
    redirect(BASE_PATH.'alerts.php');
}

// Delete all
if (isset($_POST['delete_all'])) {
    csrf_check();
    db()->prepare('DELETE FROM alerts WHERE user_id=?')->execute([$uid]);
    redirect(BASE_PATH.'alerts.php');
}

$filter = $_GET['type'] ?? '';
$where  = 'user_id=:uid';
$params = [':uid'=>$uid];
if ($filter) { $where.=' AND type=:type'; $params[':type']=$filter; }

$sq = db()->prepare("SELECT * FROM alerts WHERE $where ORDER BY created_at DESC");
$sq->execute($params);
$alerts = $sq->fetchAll();

$unread_count = unread_alerts($uid);
$page_title = 'Alerts';
require_once __DIR__ . '/includes/header.php';

$aicons=['info'=>'ℹ️','warning'=>'⚠️','danger'=>'🚨','success'=>'✅'];
$abadge=['info'=>'badge-info','warning'=>'badge-warning','danger'=>'badge-danger','success'=>'badge-success'];
?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px">
  <div>
    <div class="section-title">Alerts &amp; Notifications</div>
    <div class="section-sub" style="margin-bottom:0"><?= $unread_count ?> unread notification<?= $unread_count!==1?'s':'' ?></div>
  </div>
  <div style="display:flex;gap:8px">
    <form method="POST" action="" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <button type="submit" name="mark_all_read" class="btn btn-ghost btn-sm">✓ Mark All Read</button>
    </form>
    <form method="POST" action="" style="display:inline" onsubmit="return confirm('Delete all alerts?')">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <button type="submit" name="delete_all" class="btn btn-danger btn-sm">🗑 Clear All</button>
    </form>
  </div>
</div>

<!-- TYPE FILTER -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px">
  <?php foreach([''=>'All','info'=>'Info','warning'=>'Warning','danger'=>'Danger','success'=>'Success'] as $k=>$v): ?>
  <a href="?type=<?= $k ?>" class="btn <?= $filter===$k?'btn-primary':'btn-ghost' ?> btn-sm">
    <?= $k?($aicons[$k].' '):'🔔 ' ?><?= $v ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if(empty($alerts)): ?>
<div class="card" style="text-align:center;padding:48px;color:var(--muted)">
  <div style="font-size:3rem;margin-bottom:12px">🔔</div>
  <div style="font-size:1rem;font-weight:600">No alerts<?= $filter?" of type \"$filter\"":'' ?></div>
</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:12px">
<?php foreach($alerts as $al): ?>
<div class="card <?= !$al['is_read']?'':'opacity-read' ?>" style="<?= !$al['is_read']?'border-left:3px solid var(--accent)':'' ?>;transition:opacity .2s">
  <div style="display:flex;align-items:flex-start;gap:14px">
    <div style="font-size:1.6rem;flex-shrink:0;margin-top:2px"><?= $aicons[$al['type']] ?? 'ℹ️' ?></div>
    <div style="flex:1;min-width:0">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px">
        <span style="font-weight:700;font-size:.95rem"><?= e($al['title']) ?></span>
        <span class="badge <?= $abadge[$al['type']] ?? 'badge-info' ?>"><?= ucfirst(e($al['type'])) ?></span>
        <?php if(!$al['is_read']): ?><span class="badge badge-danger">New</span><?php endif; ?>
      </div>
      <div style="color:var(--muted);font-size:.88rem;line-height:1.5;margin-bottom:8px"><?= e($al['message']) ?></div>
      <div style="font-size:.75rem;color:var(--muted2)"><?= date('F j, Y \a\t g:i a', strtotime($al['created_at'])) ?></div>
    </div>
    <div style="display:flex;gap:6px;flex-shrink:0">
      <?php if(!$al['is_read']): ?>
      <a href="?read=<?= $al['id'] ?>" class="btn btn-ghost btn-sm" title="Mark read">✓</a>
      <?php endif; ?>
      <a href="?del=<?= $al['id'] ?>&token=<?= csrf_token() ?>"
         class="btn btn-danger btn-sm"
         onclick="return confirm('Delete this alert?')" title="Delete">🗑</a>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
