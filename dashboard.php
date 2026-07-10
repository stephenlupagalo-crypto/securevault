<?php
require_once __DIR__ . '/includes/config.php';
SessionManager::start();
require_login();

$uid  = $_SESSION['user_id'];
$name = $_SESSION['full_name'];

// Stats
$db = db();
$stF = $db->prepare('SELECT COUNT(*) AS cnt, SUM(file_size) AS sz FROM files WHERE user_id=? AND is_deleted=0');
$stF->execute([$uid]);
$fstat = $stF->fetch();

$stC = $db->prepare('SELECT file_category, COUNT(*) AS cnt FROM files WHERE user_id=? AND is_deleted=0 GROUP BY file_category');
$stC->execute([$uid]);
$cats = $stC->fetchAll();

$stR = $db->prepare('SELECT * FROM files WHERE user_id=? AND is_deleted=0 ORDER BY created_at DESC LIMIT 8');
$stR->execute([$uid]);
$recent = $stR->fetchAll();

$stA = $db->prepare('SELECT * FROM alerts WHERE user_id=? ORDER BY created_at DESC LIMIT 5');
$stA->execute([$uid]);
$alerts = $stA->fetchAll();

$unread_count = unread_alerts($uid);

$stor_q = $db->prepare('SELECT storage_used, storage_quota FROM users WHERE id=?');
$stor_q->execute([$uid]);
$stor = $stor_q->fetch();
$pct  = $stor['storage_quota']>0 ? min(100,round($stor['storage_used']/$stor['storage_quota']*100)) : 0;

// ── Admin-only banner (full stats now live on admin_dashboard.php) ──
$is_admin = ($_SESSION['role'] ?? '') === 'admin';

$page_title = 'Dashboard';
require_once __DIR__ . '/includes/header.php';

$cat_icons = ['document'=>'📄','image'=>'🖼️','video'=>'🎬','audio'=>'🎵','archive'=>'📦','other'=>'📎'];
$hour = (int)date('H');
$greeting = $hour<12?'Good morning':($hour<17?'Good afternoon':'Good evening');
?>

<?php if ($is_admin): ?>
<div class="card" style="margin-bottom:24px;border-left:3px solid var(--accent2);display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
  <div>
    <div style="font-weight:700;font-size:.98rem;margin-bottom:2px">🛡 You're signed in as System Administrator</div>
    <div style="font-size:.85rem;color:var(--muted)">Full system-wide stats, security events, and storage breakdowns live on your Admin Dashboard.</div>
  </div>
  <a href="admin_dashboard.php" class="btn btn-primary btn-sm">Open Admin Dashboard →</a>
</div>
<?php endif; ?>

<div style="margin-bottom:28px">
  <div style="font-size:1.6rem;font-weight:700;margin-bottom:4px">
    <?= $greeting ?>, <?= e(explode(' ',$name)[0]) ?> 👋
  </div>
  <div style="color:var(--muted);font-size:.92rem">
    Here's your SecureVault overview for <?= date('l, F j, Y') ?>
  </div>
</div>

<!-- STAT CARDS -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px">
  <div class="card" style="border-left:3px solid var(--accent)">
    <div style="font-size:.78rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px">Total Files</div>
    <div style="font-size:2rem;font-weight:700"><?= number_format((int)$fstat['cnt']) ?></div>
    <div style="font-size:.8rem;color:var(--muted);margin-top:4px">encrypted &amp; stored</div>
  </div>
  <div class="card" style="border-left:3px solid var(--accent2)">
    <div style="font-size:.78rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px">Storage Used</div>
    <div style="font-size:2rem;font-weight:700"><?= format_bytes((int)($fstat['sz']??0)) ?></div>
    <div style="font-size:.8rem;color:var(--muted);margin-top:4px"><?= $pct ?>% of <?= format_bytes((int)$stor['storage_quota']) ?></div>
  </div>
  <div class="card" style="border-left:3px solid var(--success)">
    <div style="font-size:.78rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px">Unread Alerts</div>
    <div style="font-size:2rem;font-weight:700"><?= $unread_count ?></div>
    <div style="font-size:.8rem;color:var(--muted);margin-top:4px"><a href="alerts.php" style="color:var(--accent);text-decoration:none">view all →</a></div>
  </div>
  <div class="card" style="border-left:3px solid var(--warning)">
    <div style="font-size:.78rem;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px">Encryption</div>
    <div style="font-size:1.1rem;font-weight:700;color:#6ee7b7">✅ AES-256-GCM</div>
    <div style="font-size:.8rem;color:var(--muted);margin-top:4px">all files protected</div>
  </div>
</div>

<!-- STORAGE BAR DETAIL -->
<div class="card" style="margin-bottom:24px">
  <div class="card-header">
    <span class="card-title">Storage Usage</span>
    <span style="font-size:.85rem;color:var(--muted)"><?= format_bytes((int)$stor['storage_used']) ?> / <?= format_bytes((int)$stor['storage_quota']) ?></span>
  </div>
  <div style="height:10px;border-radius:5px;background:var(--surface2);overflow:hidden">
    <div style="height:100%;width:<?= $pct ?>%;background:linear-gradient(90deg,var(--accent),var(--accent2));border-radius:5px;transition:width .5s"></div>
  </div>
  <?php if($pct>=90): ?>
  <div style="margin-top:10px;font-size:.85rem;color:#fca5a5">⚠️ Storage almost full. Delete unused files or request a quota increase.</div>
  <?php endif; ?>
  <div style="display:flex;gap:20px;margin-top:16px;flex-wrap:wrap">
    <?php foreach($cats as $cat): ?>
    <div style="display:flex;align-items:center;gap:6px;font-size:.83rem">
      <span><?= $cat_icons[$cat['file_category']] ?? '📎' ?></span>
      <span style="color:var(--muted)"><?= ucfirst(e($cat['file_category'])) ?></span>
      <span style="font-weight:600"><?= $cat['cnt'] ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start">

<!-- RECENT FILES -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Recent Files</span>
    <a href="files.php" class="btn btn-ghost btn-sm">View All</a>
  </div>
  <?php if(empty($recent)): ?>
  <div style="text-align:center;padding:32px;color:var(--muted)">
    <div style="font-size:2.5rem;margin-bottom:8px">📂</div>
    No files yet. <a href="upload.php" style="color:var(--accent)">Upload your first file →</a>
  </div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th>File</th><th>Size</th><th>Date</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach($recent as $f): ?>
    <tr class="file-row">
      <td>
        <div style="display:flex;align-items:center;gap:8px">
          <span style="font-size:1.2rem"><?= $cat_icons[$f['file_category']] ?? '📎' ?></span>
          <div>
            <div style="font-weight:500;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($f['original_name']) ?></div>
            <div style="font-size:.73rem;color:var(--muted)"><?= e($f['mime_type']) ?></div>
          </div>
        </div>
      </td>
      <td style="color:var(--muted);white-space:nowrap"><?= format_bytes((int)$f['file_size']) ?></td>
      <td style="color:var(--muted);white-space:nowrap;font-size:.82rem"><?= date('M j, Y', strtotime($f['created_at'])) ?></td>
      <td>
        <a href="download.php?id=<?= $f['id'] ?>" class="btn btn-ghost btn-sm" title="Download">⬇</a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- RECENT ALERTS -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Alerts</span>
    <a href="alerts.php" class="btn btn-ghost btn-sm">All</a>
  </div>
  <?php if(empty($alerts)): ?>
  <div style="text-align:center;padding:24px;color:var(--muted);font-size:.88rem">No alerts yet.</div>
  <?php else: ?>
  <div style="display:flex;flex-direction:column;gap:10px">
    <?php $aicons=['info'=>'ℹ️','warning'=>'⚠️','danger'=>'🚨','success'=>'✅']; ?>
    <?php foreach($alerts as $al): ?>
    <div style="padding:10px 12px;border-radius:10px;background:var(--surface2);border-left:3px solid var(--<?= e($al['type']==='danger'?'danger':($al['type']==='warning'?'warning':($al['type']==='success'?'success':'accent'))) ?>)">
      <div style="display:flex;align-items:center;gap:6px;font-size:.85rem;font-weight:600;margin-bottom:2px">
        <?= $aicons[$al['type']] ?? 'ℹ️' ?> <?= e($al['title']) ?>
        <?php if(!$al['is_read']): ?><span style="width:6px;height:6px;border-radius:50%;background:var(--danger);display:inline-block;margin-left:auto"></span><?php endif; ?>
      </div>
      <div style="font-size:.78rem;color:var(--muted);line-height:1.4"><?= e(mb_strimwidth($al['message'],0,80,'…')) ?></div>
      <div style="font-size:.7rem;color:var(--muted2);margin-top:4px"><?= date('M j, g:i a', strtotime($al['created_at'])) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
