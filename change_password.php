<?php
require_once __DIR__ . '/includes/config.php';
SessionManager::start();

$uid = $_SESSION['user_id'] ?? null;
$is_logged_in = !empty($uid);
$action = $_POST['action'] ?? '';

// ── Self-service reset via token (not logged in) ──────────────────────────
$token_mode = false;
$token_user = null;
if (!$is_logged_in && isset($_GET['token'])) {
    $tok = $_GET['token'];
    $sq  = db()->prepare('SELECT pr.*, u.full_name, u.email FROM password_resets pr JOIN users u ON u.id=pr.user_id WHERE pr.used=0 AND pr.expires_at>NOW() ORDER BY pr.id DESC LIMIT 1');
    $sq->execute();
    foreach ($sq->fetchAll() as $row) {
        if (hash_equals($row['token_hash'], hash('sha256', $tok))) {
            $token_mode = true;
            $token_user = $row;
            break;
        }
    }
    if (!$token_mode) {
        $error_top = 'This password reset link is invalid or has expired.';
    }
}

$error = $success = $error_top = $error_top ?? '';
$page_title = 'Change Password';

// ── Handle POST ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ($action === 'request_reset') {
        $email = trim($_POST['email'] ?? '');
        $uq    = db()->prepare('SELECT id, full_name, email FROM users WHERE email=? AND is_active=1');
        $uq->execute([$email]);
        $u = $uq->fetch();

        if ($u) {
            $raw_token = bin2hex(random_bytes(32));
            $hash      = hash('sha256', $raw_token);
            $expires   = date('Y-m-d H:i:s', strtotime('+1 hour'));
            db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?,?)')->execute([$u['id'], $hash, $expires]);

            $scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $reset_link = $scheme.'://'.$_SERVER['HTTP_HOST'].BASE_PATH.'change_password.php?token='.$raw_token;

            $subject = 'SecureVault Password Reset';
            $body    = "Hi {$u['full_name']},<br><br>"
                     . "We received a request to reset your SecureVault password. This link expires in 1 hour:<br><br>"
                     . "<a href=\"{$reset_link}\">{$reset_link}</a><br><br>"
                     . "If you didn't request this, you can safely ignore this email.";

            try {
                send_email($u['email'], $subject, $body, $u['full_name']);
                log_activity('password_reset_requested', 'For user_id='.$u['id']);
            } catch (Throwable $e) {
                error_log('Password reset email failed: '.$e->getMessage());
            }
        }

        // Same message either way — never reveal whether the email exists
        $success = "If that email exists in our system, a reset link has been sent. Please check your inbox.";

    } elseif ($action === 'reset_with_token' && $token_mode) {
        $new = $_POST['new_password']     ?? '';
        $con = $_POST['confirm_password'] ?? '';
        if (strlen($new)<8 || !preg_match('/[A-Z]/',$new) || !preg_match('/[0-9]/',$new)) {
            $error = 'Password must be ≥8 chars, with 1 uppercase and 1 number.';
        } elseif ($new !== $con) {
            $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost'=>12]);
            db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $token_user['user_id']]);
            db()->prepare('UPDATE password_resets SET used=1 WHERE id=?')->execute([$token_user['id']]);
            add_alert($token_user['user_id'],'warning','Password Changed','Your password was reset via the reset link.');
            log_activity('password_reset','Via token for user_id='.$token_user['user_id']);
            $success = 'Password updated! <a href="login.php">Sign in →</a>';
            $token_mode = false;
        }

    } elseif ($action === 'change' && $is_logged_in) {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $con     = $_POST['confirm_password'] ?? '';

        $uq = db()->prepare('SELECT password_hash FROM users WHERE id=?');
        $uq->execute([$uid]);
        $u = $uq->fetch();

        if (!password_verify($current, $u['password_hash'])) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($new)<8 || !preg_match('/[A-Z]/',$new) || !preg_match('/[0-9]/',$new)) {
            $error = 'New password must be ≥8 chars, with 1 uppercase and 1 number.';
        } elseif ($new !== $con) {
            $error = 'New passwords do not match.';
        } elseif (password_verify($new, $u['password_hash'])) {
            $error = 'New password must differ from current password.';
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost'=>12]);
            db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $uid]);
            add_alert($uid,'warning','Password Changed','Your account password was changed.');
            log_activity('password_change');
            $_SESSION['flash']=['type'=>'success','msg'=>'Password changed successfully.'];
            redirect(BASE_PATH . 'change_password.php');
        }
    }
}

if ($is_logged_in) {
    require_once __DIR__ . '/includes/header.php';
} else {
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SecureVault – Reset Password</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#0a0e1a;--surface:#111827;--surface2:#1e2a3a;--border:#1e3a5f;--accent:#3b82f6;--accent2:#6366f1;--danger:#ef4444;--success:#10b981;--text:#f1f5f9;--muted:#94a3b8;--font:'Inter',sans-serif}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;display:grid;place-items:center;padding:24px}
body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(59,130,246,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(59,130,246,.05) 1px,transparent 1px);background-size:40px 40px;pointer-events:none}
.card{position:relative;z-index:1;width:min(420px,100%);background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:40px 36px;box-shadow:0 25px 60px rgba(0,0,0,.5)}
.logo{display:flex;align-items:center;gap:10px;margin-bottom:28px}
.logo-icon{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,var(--accent),var(--accent2));display:grid;place-items:center;font-size:20px}
h1{font-size:1.4rem;font-weight:700;margin-bottom:4px}
.sub{color:var(--muted);font-size:.88rem;margin-bottom:24px}
.alert{padding:11px 14px;border-radius:10px;font-size:.88rem;margin-bottom:16px}
.alert-e{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#fca5a5}
.alert-s{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);color:#6ee7b7}
.field{margin-bottom:16px}
label{display:block;font-size:.8rem;color:var(--muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.06em;font-weight:500}
input{width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:11px 14px;border-radius:10px;font-size:.93rem;font-family:var(--font);outline:none;transition:border-color .2s,box-shadow .2s}
input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(59,130,246,.15)}
.btn{width:100%;padding:12px;border:none;border-radius:10px;font-size:1rem;font-weight:600;cursor:pointer;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;margin-top:4px}
.lnk{text-align:center;margin-top:16px;font-size:.87rem;color:var(--muted)}
.lnk a{color:var(--accent);text-decoration:none}
</style>
</head><body>
<div class="card">
  <div class="logo">
    <div class="logo-icon">🔐</div>
    <div style="font-size:1.1rem;font-weight:700">SecureVault</div>
  </div>
<?php } ?>

<?php if ($is_logged_in): ?>
<div class="section-title">Change Password</div>
<div class="section-sub">Update your account password. Choose something strong.</div>

<div style="display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start">
<div class="card">
  <?php if($error): ?><div class="flash flash-error">⚠️ <?= e($error) ?></div><?php endif; ?>

  <form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="change">

    <div class="form-group">
      <label class="form-label">Current Password</label>
      <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
    </div>
    <div class="form-group">
      <label class="form-label">New Password</label>
      <input type="password" name="new_password" id="newpw" class="form-control" required autocomplete="new-password" oninput="strength()">
      <div style="height:4px;border-radius:2px;background:var(--surface2);margin-top:6px;overflow:hidden"><div id="sbar" style="height:100%;width:0;border-radius:2px;transition:width .3s,background .3s"></div></div>
      <div id="slbl" style="font-size:.73rem;color:var(--muted);margin-top:3px">Min 8 chars, 1 uppercase, 1 number</div>
    </div>
    <div class="form-group">
      <label class="form-label">Confirm New Password</label>
      <input type="password" name="confirm_password" class="form-control" required autocomplete="new-password">
    </div>
    <button type="submit" class="btn btn-primary">🔑 Update Password</button>
  </form>
</div>

<div>
  <div class="card" style="background:rgba(59,130,246,.06);border-color:rgba(59,130,246,.2)">
    <div style="font-size:.88rem;color:#93c5fd;line-height:1.8">
      <strong>Password Requirements</strong><br>
      ✓ Minimum 8 characters<br>
      ✓ At least 1 uppercase letter<br>
      ✓ At least 1 number<br>
      ✓ Must differ from current password<br><br>
      <strong>Tips:</strong><br>
      • Use a passphrase instead of a word<br>
      • Include symbols for extra strength<br>
      • Never share your password
    </div>
  </div>
</div>
</div>

<?php else: ?>

<?php if($error_top): ?><div class="alert alert-e">⚠️ <?= e($error_top) ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-e">⚠️ <?= e($error) ?></div><?php endif; ?>
<?php if($success): ?><div class="alert alert-s"><?= $success ?></div><?php endif; ?>

<?php if($token_mode): ?>
  <h1>Set New Password</h1>
  <p class="sub">Resetting password for <?= e($token_user['full_name']) ?></p>
  <form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="reset_with_token">
    <div class="field"><label>New Password</label><input type="password" name="new_password" required></div>
    <div class="field"><label>Confirm Password</label><input type="password" name="confirm_password" required></div>
    <button type="submit" class="btn">Set New Password</button>
  </form>
<?php elseif(!$success): ?>
  <h1>Forgot Password?</h1>
  <p class="sub">Enter your email and we'll generate a reset link.</p>
  <form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="request_reset">
    <div class="field"><label>Email Address</label><input type="email" name="email" required></div>
    <button type="submit" class="btn">Send Reset Link</button>
  </form>
<?php endif; ?>
<div class="lnk"><a href="login.php">← Back to Sign In</a></div>
</div>
</body>
</html>
<?php endif; ?>

<script>
function strength(){
  const v=document.getElementById('newpw')?.value||'';
  let s=0;if(v.length>=8)s++;if(/[A-Z]/.test(v))s++;if(/[0-9]/.test(v))s++;if(/[^A-Za-z0-9]/.test(v))s++;
  const b=document.getElementById('sbar'),l=document.getElementById('slbl');
  if(!b)return;
  b.style.width=(s*25)+'%';
  b.style.background=['#ef4444','#f97316','#eab308','#10b981'][s-1]||'#334155';
  if(l)l.textContent=s?['Weak','Fair','Good','Strong'][s-1]:'Min 8 chars, 1 uppercase, 1 number';
}
</script>

<?php if($is_logged_in) require_once __DIR__ . '/includes/footer.php'; ?>
