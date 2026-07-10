<?php
require_once __DIR__ . '/includes/config.php';
SessionManager::start();

if (logged_in()) redirect(BASE_PATH.'dashboard.php');

$error = $success = '';
$colors = ['#3b82f6','#6366f1','#10b981','#f59e0b','#ef4444','#ec4899','#14b8a6','#8b5cf6'];
$ip = client_ip();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email']     ?? '');
    $username  = trim($_POST['username']  ?? '');
    $password  = $_POST['password']  ?? '';
    $confirm   = $_POST['confirm']   ?? '';
    $color     = in_array($_POST['color'] ?? '', $colors) ? $_POST['color'] : $colors[0];

    $rateBlocked = RateLimiter::checkRegistration($ip);

    if ($rateBlocked) {
        $error = $rateBlocked;
    } elseif (!$full_name || !$email || !$username || !$password) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.]{3,60}$/', $username)) {
        $error = 'Username must be 3-60 characters (letters, numbers, underscore, dot only).';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif (!preg_match('/[A-Z]/',$password) || !preg_match('/[0-9]/',$password)) {
        $error = 'Password must include at least one uppercase letter and one number.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        RateLimiter::recordRegistration($ip);
        $s = db()->prepare('SELECT id FROM users WHERE username=? OR email=? LIMIT 1');
        $s->execute([$username, $email]);
        if ($s->fetch()) {
            $error = 'Username or email is already taken.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost'=>12]);
            db()->prepare('INSERT INTO users (full_name,email,username,password_hash,avatar_color) VALUES (?,?,?,?,?)')
                 ->execute([$full_name, $email, $username, $hash, $color]);
            $uid = (int)db()->lastInsertId();
            add_alert($uid, 'success', 'Account Created', 'Welcome to SecureVault, '.$full_name.'! Your account is ready.');
            AuditService::log('register', "New user: $username", $uid);
            $success = 'Account created! <a href="login.php">Sign in now →</a>';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SecureVault – Create Account</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#0a0e1a;--surface:#111827;--surface2:#1e2a3a;--border:#1e3a5f;--accent:#3b82f6;--accent2:#6366f1;--danger:#ef4444;--success:#10b981;--text:#f1f5f9;--muted:#94a3b8;--font:'Inter',sans-serif}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;display:grid;place-items:center;padding:24px;position:relative;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(59,130,246,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(59,130,246,.05) 1px,transparent 1px);background-size:40px 40px;pointer-events:none}
.orb{position:fixed;border-radius:50%;filter:blur(80px);pointer-events:none;opacity:.3}
.orb1{width:500px;height:500px;background:radial-gradient(#3b82f6,transparent);top:-200px;right:-100px}
.orb2{width:350px;height:350px;background:radial-gradient(#6366f1,transparent);bottom:-100px;left:-100px}
.card{position:relative;z-index:1;width:min(460px,100%);background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:40px 36px;box-shadow:0 25px 60px rgba(0,0,0,.5)}
.logo{display:flex;align-items:center;gap:12px;margin-bottom:28px}
.logo-icon{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,var(--accent),var(--accent2));display:grid;place-items:center;font-size:22px}
.logo-name{font-size:1.3rem;font-weight:700}
.logo-tag{font-size:.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:.1em}
h1{font-size:1.5rem;font-weight:700;margin-bottom:4px}
.subtitle{color:var(--muted);font-size:.88rem;margin-bottom:24px}
.alert{padding:11px 14px;border-radius:10px;font-size:.88rem;margin-bottom:18px;display:flex;gap:8px;align-items:center}
.alert-error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
.alert-success{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);color:#6ee7b7}
.alert-success a{color:#34d399;font-weight:600}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.field{margin-bottom:16px}
label{display:block;font-size:.8rem;font-weight:500;color:var(--muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.06em}
input[type=text],input[type=email],input[type=password]{width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:11px 14px;border-radius:10px;font-size:.93rem;font-family:var(--font);outline:none;transition:border-color .2s,box-shadow .2s}
input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(59,130,246,.15)}
.pw-wrap{position:relative}
.pw-wrap input{padding-right:42px}
.pw-toggle{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted);font-size:1rem}
.strength-bar{height:4px;border-radius:2px;background:var(--surface2);margin-top:6px;overflow:hidden}
.strength-fill{height:100%;border-radius:2px;transition:width .3s,background .3s;width:0}
.strength-label{font-size:.75rem;color:var(--muted);margin-top:4px}
.color-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
.color-swatch{width:28px;height:28px;border-radius:50%;cursor:pointer;border:2px solid transparent;transition:transform .2s,border-color .2s;position:relative}
.color-swatch input{position:absolute;inset:0;opacity:0;cursor:pointer}
.color-swatch.active{border-color:#fff;transform:scale(1.15)}
.btn{width:100%;padding:13px;border:none;border-radius:10px;font-size:1rem;font-weight:600;cursor:pointer;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;transition:opacity .2s,transform .1s;margin-top:4px}
.btn:hover{opacity:.9;transform:translateY(-1px)}
.link-row{text-align:center;font-size:.87rem;color:var(--muted);margin-top:18px}
.link-row a{color:var(--accent);text-decoration:none;font-weight:500}
</style>
</head>
<body>
<div class="orb orb1"></div>
<div class="orb orb2"></div>
<div class="card">
  <div class="logo">
    <div class="logo-icon">🔐</div>
    <div>
      <div class="logo-name">SecureVault</div>
      <div class="logo-tag">Create your account</div>
    </div>
  </div>

  <h1>Join SecureVault</h1>
  <p class="subtitle">Set up your encrypted file storage in seconds</p>

  <?php if ($error): ?><div class="alert alert-error">⚠️ <?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success">✅ <?= $success ?></div><?php endif; ?>

  <?php if (!$success): ?>
  <form method="POST" action="" id="regForm">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="color" id="colorInput" value="<?= e($colors[0]) ?>">

    <div class="row2">
      <div class="field">
        <label>Full Name</label>
        <input type="text" name="full_name" required value="<?= e($_POST['full_name'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Username</label>
        <input type="text" name="username" required value="<?= e($_POST['username'] ?? '') ?>">
      </div>
    </div>

    <div class="field">
      <label>Email Address</label>
      <input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>">
    </div>

    <div class="field">
      <label>Password</label>
      <div class="pw-wrap">
        <input type="password" name="password" id="pw" required oninput="checkStrength()">
        <button type="button" class="pw-toggle" onclick="togglePw('pw')">👁</button>
      </div>
      <div class="strength-bar"><div class="strength-fill" id="sBar"></div></div>
      <div class="strength-label" id="sLabel">Enter a password (min 8 chars, 1 uppercase, 1 number)</div>
    </div>

    <div class="field">
      <label>Confirm Password</label>
      <div class="pw-wrap">
        <input type="password" name="confirm" id="pw2" required>
        <button type="button" class="pw-toggle" onclick="togglePw('pw2')">👁</button>
      </div>
    </div>

    <div class="field">
      <label>Avatar Color</label>
      <div class="color-row" id="colorRow">
        <?php foreach ($colors as $i => $c): ?>
        <div class="color-swatch <?= $i===0?'active':'' ?>" style="background:<?= $c ?>" onclick="pickColor('<?= $c ?>',this)"></div>
        <?php endforeach; ?>
      </div>
    </div>

    <button type="submit" class="btn">Create Account →</button>
  </form>
  <?php endif; ?>

  <div class="link-row">Already have an account? <a href="login.php">Sign in</a></div>
</div>

<script>
function togglePw(id){const f=document.getElementById(id);f.type=f.type==='password'?'text':'password'}
function pickColor(c,el){
  document.getElementById('colorInput').value=c;
  document.querySelectorAll('.color-swatch').forEach(s=>s.classList.remove('active'));
  el.classList.add('active');
}
function checkStrength(){
  const v=document.getElementById('pw').value;
  const bar=document.getElementById('sBar');
  const lbl=document.getElementById('sLabel');
  let score=0;
  if(v.length>=8)score++;if(/[A-Z]/.test(v))score++;if(/[0-9]/.test(v))score++;if(/[^A-Za-z0-9]/.test(v))score++;
  const cols=['#ef4444','#f97316','#eab308','#10b981'];
  const labels=['Too short','Weak','Good','Strong'];
  bar.style.width=(score*25)+'%';
  bar.style.background=cols[score-1]||'#334155';
  lbl.textContent=score?labels[score-1]:'Enter a password (min 8 chars, 1 uppercase, 1 number)';
}
</script>
</body>
</html>
