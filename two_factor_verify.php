<?php
require_once __DIR__ . '/includes/config.php';
SessionManager::start();

if (logged_in()) redirect(($_SESSION['role'] ?? '') === 'admin' ? BASE_PATH.'admin_dashboard.php' : BASE_PATH.'dashboard.php');
if (empty($_SESSION['2fa_pending_user'])) redirect(BASE_PATH.'login.php');

$userId = (int)$_SESSION['2fa_pending_user'];
$method = $_SESSION['2fa_method'] ?? 'totp';
$error  = '';
$info   = '';

$u = db()->prepare('SELECT * FROM users WHERE id=?');
$u->execute([$userId]);
$user = $u->fetch();
if (!$user) redirect(BASE_PATH.'login.php');

$tf = db()->prepare('SELECT * FROM two_factor WHERE user_id=?');
$tf->execute([$userId]);
$twoFactor = $tf->fetch();

function complete_login(array $user): never {
    SessionManager::regenerateOnLogin();
    $_SESSION['user_id']      = $user['id'];
    $_SESSION['full_name']    = $user['full_name'];
    $_SESSION['username']     = $user['username'];
    $_SESSION['role']         = $user['role'];
    $_SESSION['avatar_color'] = $user['avatar_color'];
    unset($_SESSION['2fa_pending_user'], $_SESSION['2fa_method']);

    db()->prepare('UPDATE users SET last_login=NOW() WHERE id=?')->execute([$user['id']]);
    AuditService::log('login', 'Successful login (2FA verified)', (int)$user['id']);
    add_alert($user['id'], 'info', 'New Login', 'You logged in from IP ' . client_ip());
    redirect($user['role'] === 'admin' ? BASE_PATH.'admin_dashboard.php' : BASE_PATH.'dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'verify';

    if ($action === 'resend_sms' && $method === 'sms') {
        if ($user['phone']) {
            SmsGateway::sendOtp($userId, $user['phone']);
            $info = 'A new code has been sent to your phone.';
        }
    } elseif ($action === 'cancel') {
        session_unset();
        session_destroy();
        redirect(BASE_PATH.'login.php');
    } else {
        $code = trim($_POST['code'] ?? '');
        $verified = false;

        if ($code === '') {
            $error = 'Please enter a code.';
        } elseif ($method === 'totp' && $twoFactor['totp_secret'] && TwoFactorAuth::verifyCode($twoFactor['totp_secret'], $code)) {
            $verified = true;
        } elseif ($method === 'sms' && SmsGateway::verifyOtp($userId, $code)) {
            $verified = true;
        } elseif (TwoFactorAuth::verifyAdminCode($twoFactor['admin_code_hash'] ?? null, $twoFactor['admin_code_expires'] ?? null, $code)) {
            // Admin-issued fixed override code (e.g. lost phone / SMS unreachable)
            db()->prepare('UPDATE two_factor SET admin_code_hash=NULL, admin_code_expires=NULL WHERE user_id=?')->execute([$userId]);
            AuditService::log('2fa_admin_code_used', 'Logged in using admin-issued fixed code', $userId);
            $verified = true;
        } else {
            // Try backup codes as a last resort
            $backupCodes = json_decode($twoFactor['backup_codes'] ?? '[]', true) ?: [];
            $remaining = TwoFactorAuth::consumeBackupCode($backupCodes, $code);
            if ($remaining !== null) {
                db()->prepare('UPDATE two_factor SET backup_codes=? WHERE user_id=?')
                    ->execute([json_encode($remaining), $userId]);
                AuditService::log('2fa_backup_code_used', 'Logged in using a backup recovery code', $userId);
                $verified = true;
            }
        }

        if ($verified) {
            complete_login($user);
        } else {
            $error = 'Invalid or expired code. Please try again.';
            AuditService::log('2fa_failed', 'Failed 2FA attempt', $userId);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SecureVault – Verify It's You</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:#0a0e1a;color:#f1f5f9;min-height:100vh;display:grid;place-items:center}
.card{width:min(420px,94vw);background:#111827;border:1px solid #1e3a5f;border-radius:20px;padding:40px 36px;box-shadow:0 25px 60px rgba(0,0,0,.5)}
h1{font-size:1.4rem;font-weight:700;margin-bottom:6px}
.subtitle{color:#94a3b8;font-size:.88rem;margin-bottom:24px;line-height:1.5}
.alert-error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#fca5a5;padding:10px 14px;border-radius:10px;font-size:.88rem;margin-bottom:18px}
.alert-info{background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.3);color:#93c5fd;padding:10px 14px;border-radius:10px;font-size:.88rem;margin-bottom:18px}
label{display:block;font-size:.8rem;font-weight:500;color:#94a3b8;margin-bottom:6px;text-transform:uppercase;letter-spacing:.06em}
input[type=text]{width:100%;background:#1e2a3a;border:1px solid #1e3a5f;color:#f1f5f9;padding:14px 16px;border-radius:10px;font-size:1.3rem;letter-spacing:.3em;text-align:center;font-family:'JetBrains Mono',monospace;margin-bottom:18px}
.btn{width:100%;padding:13px;border:none;border-radius:10px;font-size:1rem;font-weight:600;cursor:pointer;background:linear-gradient(135deg,#3b82f6,#6366f1);color:#fff;margin-bottom:10px}
.btn-ghost{background:transparent;border:1px solid #1e3a5f;color:#94a3b8}
</style>
</head>
<body>
<div class="card">
  <h1>🔐 Two-Factor Verification</h1>
  <p class="subtitle">
    <?php if($method==='totp'): ?>
      Enter the 6-digit code from your authenticator app.
    <?php elseif($method==='sms'): ?>
      We sent a 6-digit code by SMS to your registered phone number.
    <?php endif; ?>
    Lost access? A backup recovery code also works here.
  </p>

  <?php if($error): ?><div class="alert-error">⚠️ <?= e($error) ?></div><?php endif; ?>
  <?php if($info): ?><div class="alert-info">ℹ️ <?= e($info) ?></div><?php endif; ?>

  <form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="verify">
    <label>Verification Code</label>
    <input type="text" name="code" autocomplete="one-time-code" maxlength="9" autofocus placeholder="••••••" required>
    <button type="submit" class="btn">Verify →</button>
  </form>

  <?php if($method==='sms'): ?>
  <form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="resend_sms">
    <button type="submit" class="btn btn-ghost">Resend SMS Code</button>
  </form>
  <?php endif; ?>

  <form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="cancel">
    <button type="submit" class="btn btn-ghost">Cancel, sign out</button>
  </form>
</div>
</body>
</html>
