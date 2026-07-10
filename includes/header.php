<?php
// Call session_start() before including this file
// $page_title must be set before including
$uid       = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$username  = $_SESSION['username'];
$avcolor   = $_SESSION['avatar_color'];
$initials  = implode('', array_map(fn($w)=>strtoupper($w[0]), explode(' ', $full_name)));
$initials  = substr($initials, 0, 2);
$unread    = unread_alerts($uid);
$current   = basename($_SERVER['PHP_SELF']);

// Storage bar
$s = db()->prepare('SELECT storage_used, storage_quota FROM users WHERE id=?');
$s->execute([$uid]);
$stor = $s->fetch();
$pct  = $stor['storage_quota'] > 0 ? min(100, round($stor['storage_used']/$stor['storage_quota']*100)) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SecureVault – <?= e($page_title ?? 'Dashboard') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#090d1a;--surface:#0f172a;--surface2:#1e293b;--surface3:#293548;
  --border:#1e3a5f;--border2:#2d4a6a;
  --accent:#3b82f6;--accent2:#6366f1;--success:#10b981;--warning:#f59e0b;--danger:#ef4444;
  --text:#f1f5f9;--muted:#94a3b8;--muted2:#64748b;
  --sidebar:240px;--topbar:64px;
  --font:'Inter',sans-serif;--mono:'JetBrains Mono',monospace;
  --radius:12px;
}
body{font-family:var(--font);background:var(--bg);color:var(--text);display:flex;min-height:100vh}

/* ── SIDEBAR ── */
.sidebar{
  width:var(--sidebar);flex-shrink:0;
  background:var(--surface);
  border-right:1px solid var(--border);
  display:flex;flex-direction:column;
  position:fixed;top:0;left:0;bottom:0;
  z-index:100;overflow-y:auto;
}
.sb-logo{
  padding:20px 20px 16px;
  display:flex;align-items:center;gap:10px;
  border-bottom:1px solid var(--border);
}
.sb-logo-icon{
  width:36px;height:36px;border-radius:10px;
  background:linear-gradient(135deg,var(--accent),var(--accent2));
  display:grid;place-items:center;font-size:18px;flex-shrink:0;
}
.sb-logo-text{font-size:1.05rem;font-weight:700;letter-spacing:-.01em}
.sb-logo-sub{font-size:.6rem;color:var(--muted);font-family:var(--mono);text-transform:uppercase;letter-spacing:.1em}

.sb-user{
  padding:16px 20px;
  display:flex;align-items:center;gap:10px;
  border-bottom:1px solid var(--border);
}
.avatar{
  width:36px;height:36px;border-radius:50%;
  display:grid;place-items:center;
  font-size:.78rem;font-weight:700;flex-shrink:0;
  background:<?= e($avcolor) ?>;
  letter-spacing:.02em;
}
.sb-user-name{font-size:.88rem;font-weight:600;line-height:1.2}
.sb-user-role{font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em}

.sb-storage{padding:14px 20px;border-bottom:1px solid var(--border)}
.sb-storage-label{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;display:flex;justify-content:space-between}
.sb-bar{height:5px;border-radius:3px;background:var(--surface2);overflow:hidden}
.sb-bar-fill{height:100%;border-radius:3px;background:linear-gradient(90deg,var(--accent),var(--accent2));transition:width .5s}
<?php
$barCol = $pct>90?'var(--danger)':($pct>70?'var(--warning)':'');
if($barCol) echo ".sb-bar-fill{background:$barCol}";
?>

.sb-nav{padding:12px 12px;flex:1}
.nav-group-label{font-size:.65rem;font-weight:600;color:var(--muted2);text-transform:uppercase;letter-spacing:.1em;padding:8px 8px 4px}
.nav-link{
  display:flex;align-items:center;gap:10px;
  padding:9px 12px;border-radius:var(--radius);
  text-decoration:none;color:var(--muted);font-size:.9rem;font-weight:500;
  transition:background .15s,color .15s;margin-bottom:2px;
}
.nav-link:hover{background:var(--surface2);color:var(--text)}
.nav-link.active{background:rgba(59,130,246,.15);color:var(--accent);font-weight:600}
.nav-link .icon{font-size:1.05rem;width:22px;text-align:center;flex-shrink:0}
.nav-badge{
  margin-left:auto;min-width:20px;height:20px;border-radius:10px;
  background:var(--danger);color:#fff;font-size:.68rem;font-weight:700;
  display:grid;place-items:center;padding:0 5px;
}

.sb-bottom{padding:12px 12px;border-top:1px solid var(--border)}

/* ── MAIN ── */
.main{margin-left:var(--sidebar);flex:1;display:flex;flex-direction:column;min-height:100vh}
.topbar{
  height:var(--topbar);
  background:var(--surface);border-bottom:1px solid var(--border);
  display:flex;align-items:center;padding:0 24px;gap:16px;
  position:sticky;top:0;z-index:50;
}
.topbar-title{font-size:1.15rem;font-weight:700;flex:1}
.topbar-search{
  flex:1;max-width:360px;
  display:flex;align-items:center;gap:8px;
  background:var(--surface2);border:1px solid var(--border);
  border-radius:10px;padding:8px 14px;
}
.topbar-search input{background:none;border:none;outline:none;color:var(--text);font-size:.9rem;font-family:var(--font);width:100%}
.topbar-search input::placeholder{color:var(--muted2)}
.tb-btn{
  width:40px;height:40px;border-radius:10px;background:var(--surface2);
  border:1px solid var(--border);display:grid;place-items:center;cursor:pointer;
  position:relative;font-size:1.1rem;text-decoration:none;color:var(--text);
  transition:background .15s;flex-shrink:0;
}
.tb-btn:hover{background:var(--surface3)}
.tb-badge{
  position:absolute;top:6px;right:6px;
  width:8px;height:8px;border-radius:50%;background:var(--danger);
  border:2px solid var(--surface);
}
.page-body{flex:1;padding:24px}

/* ── FLASH MESSAGES ── */
.flash{
  padding:12px 16px;border-radius:var(--radius);margin-bottom:20px;
  display:flex;align-items:center;gap:10px;font-size:.9rem;
}
.flash-success{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);color:#6ee7b7}
.flash-error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
.flash-info{background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.3);color:#93c5fd}

/* ── CARDS / PANELS ── */
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px}
.card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;gap:12px}
.card-title{font-size:1rem;font-weight:700}
.section-title{font-size:1.3rem;font-weight:700;margin-bottom:6px}
.section-sub{color:var(--muted);font-size:.88rem;margin-bottom:20px}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:10px;font-size:.88rem;font-weight:600;cursor:pointer;border:none;transition:opacity .2s,transform .1s;text-decoration:none}
.btn:hover{opacity:.85;transform:translateY(-1px)}
.btn-primary{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff}
.btn-ghost{background:var(--surface2);border:1px solid var(--border);color:var(--text)}
.btn-danger{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
.btn-success{background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);color:#6ee7b7}
.btn-warning{background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.3);color:#fcd34d}
.btn-sm{padding:6px 12px;font-size:.8rem}

/* ── TABLES ── */
table{width:100%;border-collapse:collapse}
th{text-align:left;font-size:.75rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;padding:10px 12px;border-bottom:1px solid var(--border)}
td{padding:11px 12px;border-bottom:1px solid var(--border);font-size:.88rem;vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:var(--surface2)}

/* ── BADGES ── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:.72rem;font-weight:600}
.badge-info{background:rgba(59,130,246,.15);color:#93c5fd}
.badge-success{background:rgba(16,185,129,.15);color:#6ee7b7}
.badge-warning{background:rgba(245,158,11,.15);color:#fcd34d}
.badge-danger{background:rgba(239,68,68,.15);color:#fca5a5}

/* ── FORMS ── */
.form-group{margin-bottom:18px}
.form-label{display:block;font-size:.78rem;font-weight:500;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.06em}
.form-control{width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:10px 14px;border-radius:10px;font-size:.93rem;font-family:var(--font);outline:none;transition:border-color .2s,box-shadow .2s}
.form-control:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(59,130,246,.15)}
select.form-control option{background:var(--surface)}
textarea.form-control{resize:vertical;min-height:80px}

/* ── RESPONSIVE ── */
@media(max-width:768px){
  .sidebar{transform:translateX(-100%);transition:transform .25s}
  .sidebar.open{transform:translateX(0)}
  .main{margin-left:0}
  .topbar-search{display:none}
}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sb-logo">
    <div class="sb-logo-icon">🔐</div>
    <div>
      <div class="sb-logo-text">SecureVault</div>
      <div class="sb-logo-sub">File System</div>
    </div>
  </div>

  <div class="sb-user">
    <div class="avatar"><?= e($initials) ?></div>
    <div>
      <div class="sb-user-name"><?= e($full_name) ?></div>
      <div class="sb-user-role"><?= e($_SESSION['role']) ?></div>
    </div>
  </div>

  <div class="sb-storage">
    <div class="sb-storage-label">
      <span>Storage</span>
      <span><?= $pct ?>%</span>
    </div>
    <div class="sb-bar"><div class="sb-bar-fill" style="width:<?= $pct ?>%"></div></div>
    <div style="font-size:.72rem;color:var(--muted);margin-top:4px">
      <?= format_bytes((int)$stor['storage_used']) ?> / <?= format_bytes((int)$stor['storage_quota']) ?>
    </div>
  </div>

  <nav class="sb-nav">
    <div class="nav-group-label">Main</div>
    <a href="dashboard.php" class="nav-link <?= $current==='dashboard.php'?'active':'' ?>">
      <span class="icon">🏠</span> Dashboard
    </a>
    <a href="files.php" class="nav-link <?= $current==='files.php'?'active':'' ?>">
      <span class="icon">📁</span> My Files
    </a>
    <a href="upload.php" class="nav-link <?= $current==='upload.php'?'active':'' ?>">
      <span class="icon">⬆️</span> Upload File
    </a>
    <a href="folders.php" class="nav-link <?= $current==='folders.php'?'active':'' ?>">
      <span class="icon">🗂️</span> Folders
    </a>
    <a href="trash.php" class="nav-link <?= $current==='trash.php'?'active':'' ?>">
      <span class="icon">🗑️</span> Trash
    </a>

    <div class="nav-group-label" style="margin-top:8px">Account</div>
    <a href="alerts.php" class="nav-link <?= $current==='alerts.php'?'active':'' ?>">
      <span class="icon">🔔</span> Alerts
      <?php if($unread>0): ?><span class="nav-badge"><?= $unread ?></span><?php endif; ?>
    </a>
    <a href="change_password.php" class="nav-link <?= $current==='change_password.php'?'active':'' ?>">
      <span class="icon">🔑</span> Password
    </a>
    <a href="two_factor_setup.php" class="nav-link <?= $current==='two_factor_setup.php'?'active':'' ?>">
      <span class="icon">🛡️</span> Two-Factor Auth
    </a>
    <a href="profile.php" class="nav-link <?= $current==='profile.php'?'active':'' ?>">
      <span class="icon">👤</span> Profile
    </a>
    <?php if($_SESSION['role']==='admin'): ?>
    <div class="nav-group-label" style="margin-top:8px">Admin</div>
    <a href="admin_dashboard.php" class="nav-link <?= $current==='admin_dashboard.php'?'active':'' ?>">
      <span class="icon">🛡️</span> Admin Dashboard
    </a>
    <a href="admin_users.php" class="nav-link <?= $current==='admin_users.php'?'active':'' ?>">
      <span class="icon">👥</span> Users
    </a>
    <a href="admin_logs.php" class="nav-link <?= $current==='admin_logs.php'?'active':'' ?>">
      <span class="icon">📋</span> Activity Logs
    </a>
    <?php endif; ?>
  </nav>

  <div class="sb-bottom">
    <a href="logout.php" class="nav-link" style="color:var(--danger)">
      <span class="icon">🚪</span> Sign Out
    </a>
  </div>
</aside>

<!-- MAIN -->
<div class="main">
  <header class="topbar">
    <button onclick="document.getElementById('sidebar').classList.toggle('open')"
            style="display:none;background:none;border:none;color:var(--text);font-size:1.4rem;cursor:pointer;margin-right:8px"
            id="mbMenu">☰</button>
    <div class="topbar-title"><?= e($page_title ?? 'Dashboard') ?></div>
    <div class="topbar-search">
      <span>🔍</span>
      <input type="text" placeholder="Search files…" id="globalSearch" onkeyup="liveSearch(this.value)">
    </div>
    <a href="alerts.php" class="tb-btn" title="Alerts">
      🔔
      <?php if($unread>0): ?><span class="tb-badge"></span><?php endif; ?>
    </a>
    <a href="upload.php" class="btn btn-primary btn-sm">⬆ Upload</a>
  </header>

  <div class="page-body">
<?php
// Flash messages
if (!empty($_SESSION['flash'])) {
    $f = $_SESSION['flash'];
    echo '<div class="flash flash-'.$f['type'].'">'.(($f['type']==='success')?'✅':'⚠️').' '.e($f['msg']).'</div>';
    unset($_SESSION['flash']);
}
?>
