<?php
require_once __DIR__ . '/includes/config.php';
SessionManager::start();

if (logged_in()) redirect(($_SESSION['role'] ?? '') === 'admin' ? BASE_PATH.'admin_dashboard.php' : BASE_PATH.'dashboard.php');

$error = '';
$ip = client_ip();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $login    = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($login === '' || $password === '') {
        $error = 'Please enter your username/email and password.';
    } else {
        // ── Brute-force / rate-limit check BEFORE touching the DB for auth ──
        $blocked = RateLimiter::check($login, $ip);
        if ($blocked) {
            $error = $blocked;
            AuditService::log('login_blocked', "Rate limited: $login");
        } else {
            $s = db()->prepare('SELECT * FROM users WHERE (username=? OR email=?) AND is_active=1 LIMIT 1');
            $s->execute([$login, $login]);
            $user = $s->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                RateLimiter::record($login, $ip, true);
                RateLimiter::clearLock($login);

                // Check whether 2FA is enabled for this user
                $tf = db()->prepare('SELECT * FROM two_factor WHERE user_id=? AND enabled=1 LIMIT 1');
                $tf->execute([$user['id']]);
                $twoFactor = $tf->fetch();

                if ($twoFactor) {
                    // Stash a pending login and send to the 2FA challenge page.
                    // Nothing privileged is set in $_SESSION until the code is verified.
                    session_regenerate_id(true);
                    $_SESSION['2fa_pending_user'] = $user['id'];
                    $_SESSION['2fa_method']       = $twoFactor['method'];

                    if ($twoFactor['method'] === 'sms') {
                        $phoneRow = db()->prepare('SELECT phone FROM users WHERE id=?');
                        $phoneRow->execute([$user['id']]);
                        $phone = $phoneRow->fetchColumn();
                        if ($phone) SmsGateway::sendOtp((int)$user['id'], $phone);
                    }

                    AuditService::log('login_2fa_challenge', 'Password OK, awaiting 2FA', (int)$user['id']);
                    redirect(BASE_PATH.'two_factor_verify.php');
                }

                // No 2FA — log in fully now.
                SessionManager::regenerateOnLogin();
                $_SESSION['user_id']      = $user['id'];
                $_SESSION['full_name']    = $user['full_name'];
                $_SESSION['username']     = $user['username'];
                $_SESSION['role']         = $user['role'];
                $_SESSION['avatar_color'] = $user['avatar_color'];

                db()->prepare('UPDATE users SET last_login=NOW() WHERE id=?')->execute([$user['id']]);
                AuditService::log('login', 'Successful login', (int)$user['id']);
                add_alert($user['id'], 'info', 'New Login', 'You logged in from IP ' . $ip);
                redirect($user['role'] === 'admin' ? BASE_PATH.'admin_dashboard.php' : BASE_PATH.'dashboard.php');
            } else {
                RateLimiter::record($login, $ip, false);
                $left = RateLimiter::remainingAttempts($login);
                $error = 'Invalid username/email or password.' . ($left <= 2 ? " ({$left} attempt(s) remaining before lockout)" : '');
                AuditService::log('login_failed', "Attempted login for: $login");
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SecureVault – Sign In</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0a0e1a;
  --surface:#111827;
  --surface2:#1e2a3a;
  --border:#1e3a5f;
  --accent:#3b82f6;
  --accent2:#6366f1;
  --success:#10b981;
  --danger:#ef4444;
  --text:#f1f5f9;
  --muted:#94a3b8;
  --font:'Inter',sans-serif;
  --mono:'JetBrains Mono',monospace;
}
body{
  font-family:var(--font);
  background:var(--bg);
  color:var(--text);
  min-height:100vh;
  display:grid;
  place-items:center;
  position:relative;
  overflow:hidden;
}
body::before{
  content:'';
  position:fixed;inset:0;
  background-image:
    linear-gradient(rgba(59,130,246,.05) 1px, transparent 1px),
    linear-gradient(90deg, rgba(59,130,246,.05) 1px, transparent 1px);
  background-size:40px 40px;
  pointer-events:none;
}
.orb{position:fixed;border-radius:50%;filter:blur(80px);pointer-events:none;opacity:.35}
.orb1{width:500px;height:500px;background:radial-gradient(#3b82f6,transparent);top:-150px;left:-150px}
.orb2{width:400px;height:400px;background:radial-gradient(#6366f1,transparent);bottom:-100px;right:-100px}

.card{
  position:relative;z-index:1;
  width:min(420px,94vw);
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:20px;
  padding:40px 36px;
  box-shadow:0 25px 60px rgba(0,0,0,.5);
}
.logo{display:flex;align-items:center;gap:12px;margin-bottom:32px}
.logo-icon{
  width:44px;height:44px;border-radius:12px;
  background:linear-gradient(135deg,var(--accent),var(--accent2));
  display:grid;place-items:center;font-size:22px;
  box-shadow:0 0 20px rgba(99,102,241,.4);
}
.logo-name{font-size:1.3rem;font-weight:700;letter-spacing:-.02em}
.logo-tag{font-size:.65rem;color:var(--muted);font-family:var(--mono);text-transform:uppercase;letter-spacing:.1em;margin-top:2px}
h1{font-size:1.55rem;font-weight:700;margin-bottom:4px}
.subtitle{color:var(--muted);font-size:.9rem;margin-bottom:28px}
.alert-error{
  background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);
  color:#fca5a5;padding:10px 14px;border-radius:10px;font-size:.88rem;margin-bottom:20px;
  display:flex;gap:8px;align-items:center;
}
.field{margin-bottom:18px}
label{display:block;font-size:.82rem;font-weight:500;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.06em}
input[type=text],input[type=password],input[type=email]{
  width:100%;background:var(--surface2);border:1px solid var(--border);
  color:var(--text);padding:12px 16px;border-radius:10px;font-size:.95rem;
  font-family:var(--font);transition:border-color .2s,box-shadow .2s;outline:none;
}
input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(59,130,246,.15)}
.pw-wrap{position:relative}
.pw-wrap input{padding-right:44px}
.pw-toggle{
  position:absolute;right:14px;top:50%;transform:translateY(-50%);
  background:none;border:none;cursor:pointer;color:var(--muted);font-size:1.1rem;padding:2px;
}
.btn{
  width:100%;padding:13px;border:none;border-radius:10px;font-size:1rem;font-weight:600;
  cursor:pointer;transition:opacity .2s,transform .1s;
  background:linear-gradient(135deg,var(--accent),var(--accent2));
  color:#fff;letter-spacing:.02em;
}
.btn:hover{opacity:.9;transform:translateY(-1px)}
.btn:active{transform:translateY(0)}
.divider{display:flex;align-items:center;gap:12px;margin:20px 0;color:var(--muted);font-size:.8rem}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--border)}
.link-row{text-align:center;font-size:.88rem;color:var(--muted)}
.link-row a{color:var(--accent);text-decoration:none;font-weight:500}
.security-note{
  margin-top:24px;padding:12px 14px;
  border-radius:10px;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2);
  display:flex;gap:10px;align-items:flex-start;
}
.security-note span{font-size:.78rem;color:#6ee7b7;line-height:1.5}
.lock-icon{font-size:1rem;margin-top:1px}
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
      <div class="logo-tag">File Management System</div>
    </div>
  </div>

  <h1>Welcome back</h1>
  <p class="subtitle">Sign in to access your encrypted files</p>

  <?php if ($error): ?>
  <div class="alert-error">⚠️ <?= e($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

    <div class="field">
      <label>Username or Email</label>
      <input type="text" name="login" autocomplete="username" required
             value="<?= e($_POST['login'] ?? '') ?>">
    </div>

    <div class="field">
      <label>Password</label>
      <div class="pw-wrap">
        <input type="password" name="password" id="pw" autocomplete="current-password" required>
        <button type="button" class="pw-toggle" onclick="togglePw()" title="Show/hide password">👁</button>
      </div>
    </div>

    <button type="submit" class="btn">Sign In →</button>
  </form>

  <div class="divider">or</div>

  <div class="link-row">
    <a href="change_password.php">Forgot password?</a>
    &nbsp;·&nbsp;
    <a href="register.php">Create account</a>
  </div>

  <div class="security-note">
    <span class="lock-icon">🔒</span>
    <span>All files are encrypted with AES-256-GCM. Repeated failed sign-ins temporarily lock the account.</span>
  </div>
</div>

<script>
function togglePw(){
  const f=document.getElementById('pw');
  f.type=f.type==='password'?'text':'password';
}
</script>
</body>
</html>
