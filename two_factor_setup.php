<?php
require_once __DIR__ . '/includes/config.php';
SessionManager::start();
require_login();

$uid = $_SESSION['user_id'];
$error = $success = '';
$newBackupCodes = null;

$tf = db()->prepare('SELECT * FROM two_factor WHERE user_id=?');
$tf->execute([$uid]);
$twoFactor = $tf->fetch();
if (!$twoFactor) {
    db()->prepare('INSERT INTO two_factor (user_id, method, enabled) VALUES (?,?,0)')->execute([$uid, 'none']);
    $tf->execute([$uid]);
    $twoFactor = $tf->fetch();
}

$u = db()->prepare('SELECT phone FROM users WHERE id=?');
$u->execute([$uid]);
$userPhone = $u->fetchColumn();

// Pending secret kept in session between "generate" and "confirm" steps
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'start_totp') {
        $_SESSION['pending_totp_secret'] = TwoFactorAuth::generateSecret();
    } elseif ($action === 'confirm_totp') {
        $secret = $_SESSION['pending_totp_secret'] ?? '';
        $code   = trim($_POST['code'] ?? '');
        if ($secret && TwoFactorAuth::verifyCode($secret, $code)) {
            $backup = TwoFactorAuth::generateBackupCodes();
            db()->prepare('UPDATE two_factor SET method="totp", totp_secret=?, enabled=1, backup_codes=? WHERE user_id=?')
                ->execute([$secret, json_encode($backup['hashed']), $uid]);
            unset($_SESSION['pending_totp_secret']);
            $newBackupCodes = $backup['plain'];
            $success = 'Authenticator app 2FA enabled. Save your backup codes somewhere safe.';
            AuditService::log('2fa_enabled', 'Method: totp');
            $tf->execute([$uid]); $twoFactor = $tf->fetch();
        } else {
            $error = 'That code did not match. Please scan/enter the secret again and try the current code from your app.';
        }
    } elseif ($action === 'set_phone_and_start_sms') {
        $phone = trim($_POST['phone'] ?? '');
        if (!preg_match('/^\+?[0-9]{9,15}$/', $phone)) {
            $error = 'Enter a valid phone number in international format, e.g. +2557XXXXXXXX.';
        } else {
            db()->prepare('UPDATE users SET phone=? WHERE id=?')->execute([$phone, $uid]);
            SmsGateway::sendOtp($uid, $phone);
            $_SESSION['pending_sms_phone'] = $phone;
            $success = 'A verification code was sent to ' . $phone . '.';
        }
    } elseif ($action === 'confirm_sms') {
        $code = trim($_POST['code'] ?? '');
        if (SmsGateway::verifyOtp($uid, $code)) {
            $backup = TwoFactorAuth::generateBackupCodes();
            db()->prepare('UPDATE two_factor SET method="sms", enabled=1, backup_codes=? WHERE user_id=?')
                ->execute([json_encode($backup['hashed']), $uid]);
            unset($_SESSION['pending_sms_phone']);
            $newBackupCodes = $backup['plain'];
            $success = 'SMS-based 2FA enabled. Save your backup codes somewhere safe.';
            AuditService::log('2fa_enabled', 'Method: sms');
            $tf->execute([$uid]); $twoFactor = $tf->fetch();
        } else {
            $error = 'Invalid or expired code.';
        }
    } elseif ($action === 'disable') {
        db()->prepare('UPDATE two_factor SET method="none", enabled=0, totp_secret=NULL, backup_codes=NULL WHERE user_id=?')->execute([$uid]);
        $success = 'Two-factor authentication disabled.';
        AuditService::log('2fa_disabled', '');
        $tf->execute([$uid]); $twoFactor = $tf->fetch();
    } elseif ($action === 'regenerate_backup') {
        $backup = TwoFactorAuth::generateBackupCodes();
        db()->prepare('UPDATE two_factor SET backup_codes=? WHERE user_id=?')->execute([json_encode($backup['hashed']), $uid]);
        $newBackupCodes = $backup['plain'];
        $success = 'New backup codes generated. Your old codes no longer work.';
        AuditService::log('2fa_backup_regenerated', '');
    }
}

$pendingSecret = $_SESSION['pending_totp_secret'] ?? null;
$page_title = 'Two-Factor Authentication';
require_once __DIR__ . '/includes/header.php';
?>
<div class="section-title">Two-Factor Authentication</div>
<div class="section-sub">Add an extra layer of protection to your account.</div>

<?php if($error): ?><div class="card" style="border-color:var(--danger);color:#fca5a5;margin-bottom:16px;padding:14px 16px"><?= e($error) ?></div><?php endif; ?>
<?php if($success): ?><div class="card" style="border-color:var(--success);color:#6ee7b7;margin-bottom:16px;padding:14px 16px"><?= e($success) ?></div><?php endif; ?>

<?php if($newBackupCodes): ?>
<div class="card" style="margin-bottom:20px;padding:20px">
  <div style="font-weight:600;margin-bottom:8px">🗝 Your Backup Recovery Codes</div>
  <div style="font-size:.85rem;color:var(--muted);margin-bottom:14px">
    Each code works once, if you lose access to your authenticator/phone. Store them somewhere safe — they won't be shown again.
  </div>
  <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;font-family:var(--mono);background:var(--surface2);padding:14px;border-radius:10px">
    <?php foreach($newBackupCodes as $c): ?><div><?= e($c) ?></div><?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card" style="padding:20px;margin-bottom:20px">
  <div style="display:flex;justify-content:space-between;align-items:center">
    <div>
      <div style="font-weight:600">Current status</div>
      <div style="font-size:.85rem;color:var(--muted)">
        <?= $twoFactor['enabled'] ? '✅ Enabled via ' . strtoupper($twoFactor['method']) : '⚪ Not enabled' ?>
      </div>
    </div>
    <?php if($twoFactor['enabled']): ?>
    <form method="POST" onsubmit="return confirm('Disable two-factor authentication?')">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="disable">
      <button class="btn btn-danger btn-sm">Disable 2FA</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if(!$twoFactor['enabled']): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

  <!-- TOTP -->
  <div class="card" style="padding:20px">
    <div style="font-weight:600;margin-bottom:6px">📱 Authenticator App (TOTP)</div>
    <div style="font-size:.85rem;color:var(--muted);margin-bottom:14px">Google Authenticator, Authy, Microsoft Authenticator, etc.</div>

    <?php if(!$pendingSecret): ?>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="start_totp">
      <button class="btn btn-primary">Set Up Authenticator App</button>
    </form>
    <?php else: ?>
    <div style="font-size:.82rem;color:var(--muted);margin-bottom:8px">1. Add a new account in your authenticator app manually, using this key:</div>
    <div style="font-family:var(--mono);background:var(--surface2);padding:10px;border-radius:8px;word-break:break-all;margin-bottom:14px"><?= e($pendingSecret) ?></div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="confirm_totp">
      <label style="display:block;font-size:.8rem;color:var(--muted);margin-bottom:6px">2. Enter the 6-digit code it shows:</label>
      <input type="text" name="code" class="form-control" maxlength="6" style="margin-bottom:10px" required>
      <button class="btn btn-primary">Confirm & Enable</button>
    </form>
    <?php endif; ?>
  </div>

  <!-- SMS -->
  <div class="card" style="padding:20px">
    <div style="font-weight:600;margin-bottom:6px">💬 SMS Code</div>
    <div style="font-size:.85rem;color:var(--muted);margin-bottom:14px">A one-time code is texted to your phone at each login (via Africa's Talking).</div>

    <?php if(empty($_SESSION['pending_sms_phone'])): ?>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="set_phone_and_start_sms">
      <label style="display:block;font-size:.8rem;color:var(--muted);margin-bottom:6px">Phone number (international format)</label>
      <input type="text" name="phone" class="form-control" placeholder="+2557XXXXXXXX" value="<?= e($userPhone ?? '') ?>" style="margin-bottom:10px" required>
      <button class="btn btn-primary">Send Verification Code</button>
    </form>
    <?php else: ?>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="confirm_sms">
      <label style="display:block;font-size:.8rem;color:var(--muted);margin-bottom:6px">Enter the code sent to <?= e($_SESSION['pending_sms_phone']) ?></label>
      <input type="text" name="code" class="form-control" maxlength="6" style="margin-bottom:10px" required>
      <button class="btn btn-primary">Confirm & Enable</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>
<div class="card" style="padding:20px">
  <div style="font-weight:600;margin-bottom:8px">Backup Codes</div>
  <div style="font-size:.85rem;color:var(--muted);margin-bottom:14px">
    <?= count(json_decode($twoFactor['backup_codes'] ?? '[]', true) ?: []) ?> unused backup code(s) remaining.
  </div>
  <form method="POST" onsubmit="return confirm('This invalidates all existing backup codes. Continue?')">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="regenerate_backup">
    <button class="btn btn-ghost">Regenerate Backup Codes</button>
  </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
