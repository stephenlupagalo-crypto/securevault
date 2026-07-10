# SecureVault — New Features Setup Guide

This update adds security hardening and new functionality on top of your
existing SecureVault system. Nothing in your original design was thrown
away — files are still AES-256-GCM encrypted, the dashboard/alerts/admin
pages still work the same way. This guide covers what's new and how to
turn it on.

## 1. Apply the database migration

Your existing `database.sql` still runs first (unchanged). Then run the
new migration on top of it:

```bash
mysql -u root -p securevault < migrations/002_new_features.sql
```

This adds: `login_attempts`, `two_factor`, `two_factor_otp`, `folders`,
`tags`, `file_tags`, `share_links`, `download_log` tables, plus new
columns on `users` (`locked_until`, `failed_attempts`, `phone`) and
`files` (`folder_id`).

> If you're on MySQL 5.7 (not MariaDB 10.3+/MySQL 8+), remove the
> `IF NOT EXISTS` clauses in the `ALTER TABLE` statements — older MySQL
> doesn't support that syntax for columns.

## 2. New folder layout (MVC-lite)

```
app/
  Services/   RateLimiter, TwoFactorAuth, SmsGateway, SessionManager, AuditService
  Models/     Folder, ShareLink
```

These are autoloaded automatically (see the `spl_autoload_register` block
added to `includes/config.php`) — you don't need Composer. Each existing
page (`login.php`, `files.php`, etc.) is a thin controller that calls into
these classes, which is the "MVC style" you asked for without rewriting
the entire app and risking breakage.

## 3. Configure the Africa's Talking SMS gateway

Open `includes/config.php` and fill in your credentials:

```php
define('AT_USERNAME',  'sandbox');   // your live username once ready
define('AT_API_KEY',   '');          // paste your API key here
define('AT_SENDER_ID', '');          // optional
```

Sign up free at https://africastalking.com — the `sandbox` username lets
you test SMS delivery to simulator numbers before paying for live SMS.
Until you add a key, SMS sending fails silently and logs a note in
`activity_log` instead of crashing the app (`sms_not_configured`).

## 4. What's new, feature by feature

### Rate limiting & brute-force protection
- 5 failed logins for the same username/email within 15 minutes → account
  locked for 15 minutes (`users.locked_until`).
- Every attempt is recorded in `login_attempts` for audit purposes.
- Registration is also capped per IP (6/hour) to slow down mass sign-ups.
- Admins can manually unlock an account early from **Manage Users**.

### Two-factor authentication (2FA)
- Users turn it on themselves at **Two-Factor Auth** in the sidebar.
- Two methods: authenticator app (TOTP, works with Google Authenticator
  etc. — no external library, implemented from scratch per RFC 6238) or
  SMS via Africa's Talking.
- 8 one-time backup recovery codes are generated when 2FA is enabled, in
  case the phone is lost.
- **Admin override**: if a user is locked out entirely, an admin can issue
  a temporary 6-digit fixed code from **Manage Users → 🔑 2FA Code**, valid
  for 30 minutes, usable once instead of the normal 2FA code.

### Session hardening
- `SessionManager` centralises cookie flags (`HttpOnly`, `SameSite=Lax`,
  auto `Secure` once served over HTTPS), regenerates the session ID on
  login (fixation protection), and enforces a 20-minute idle timeout plus
  an 8-hour absolute timeout.

### Role-based dashboard
- Admins now see a system-wide overview panel at the top of the dashboard
  (total users, active today, total files/storage, failed logins today,
  currently locked accounts) that regular users don't see.

### Audit trail
- `AuditService` wraps the existing activity log and adds a dedicated
  `download_log` table recording every file download/preview/share access
  — who, when, from which IP, and via which share link if anonymous.

### File sharing (shareable links)
- From **Files**, click 🔗 on any file to open **Share**.
- Create a link with an optional password, expiry (1h/24h/7d/30d/never),
  and a max-download cap.
- Anyone with the link can view/download the file at `s.php?t=...` —
  no account needed. Revoke a link any time.

### Trash / Restore
- Deleting a file now just soft-deletes it (as it already did) — it's
  visible in the new **Trash** page, where you can restore it or delete
  it forever. An "Empty Trash" button purges everything at once.

### Folders
- Create folders under **Folders**, then use the 🗂️ icon on any file
  in **Files** to move it into one. The files list can be filtered by
  folder.

### In-browser file preview
- Click 👁 on a file to preview images, PDFs, audio, video, and plain
  text directly in the browser (still decrypted server-side on the fly,
  nothing unencrypted touches disk).

## 5. Quick test checklist once deployed

1. Register a new user — confirm the stronger password rules apply.
2. Fail a login 5 times on purpose — confirm the account locks.
3. Enable TOTP 2FA, log out, log back in — confirm the challenge screen
   appears and a valid code from your authenticator app gets you in.
4. As admin, issue a fixed 2FA code for that user and use it instead.
5. Upload a file, create a folder, move the file into it.
6. Share the file with a password + 24h expiry, open the link in a
   private/incognito window.
7. Delete a file, check it in Trash, restore it.
