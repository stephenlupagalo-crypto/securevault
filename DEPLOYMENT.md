# Deploying SecureVault to InfinityFree

This guide walks through deploying your `securevault` PHP/MySQL app to InfinityFree free hosting, using the fixed package `securevault_infinityfree_ready.zip`.

## What was fixed in the package before you deploy

Your original code had a few things that would have broken or been insecure on InfinityFree specifically:

1. **Missing `send_email()` function** — `change_password.php` called `send_email()` for password resets, but that function didn't exist anywhere in the codebase, which is why it fatal-errored. It's now implemented in `includes/config.php`.
2. **SMTP won't work on InfinityFree** — InfinityFree's free tier blocks all outbound SMTP (ports 25/465/587) at the firewall level, so PHPMailer-over-SMTP (e.g. Gmail SMTP) will fail with `SMTP connect() failed` no matter how correctly it's configured. Instead, `send_email()` now uses **Brevo's HTTPS API** (a plain cURL POST to `api.brevo.com`, port 443 — the same pattern your app already uses for Meseji SMS), which works fine on free hosting.
3. **35 hardcoded `/securevault/` paths** — every redirect, cookie path, and share link assumed the app lived in a `/securevault/` subfolder. On InfinityFree you'll almost always upload straight into `htdocs/` (the domain root), so these are now driven by a single `BASE_PATH` constant in `includes/config.php`.
4. **Exposed Meseji SMS API key** — a live key (`zs_543d...`) was hardcoded in `config.php`. It's been removed from the package and replaced with a placeholder. **Treat that key as compromised and regenerate it** at meseji.co.tz before using SMS features again.
5. **Unrealistic upload limit** — `MAX_FILE_SIZE` was 100MB; InfinityFree's free tier hard-caps uploads around 10MB regardless of PHP settings, so it's now set to 10MB to match reality and give users an accurate error instead of a silent failure.
6. Removed a stray local test file from `uploads/` and excluded `.git/` from the package (no need to upload your git history to shared hosting).
7. **Wrong timezone** — `Africa/Harare` (Zimbabwe, UTC+2) was set instead of `Africa/Dar_es_Salaam` (Tanzania, UTC+3). All timestamps (uploads, logs, 2FA codes, session expiry) would have been an hour off. Fixed.
8. **Sensitive files were publicly downloadable** — `database.sql` and `migrations/*.sql` contain your full schema *and the seeded admin password hash*, and nothing was stopping someone from just visiting `yoursite.com/database.sql` and downloading it. Added `.htaccess` rules blocking direct access to `.sql` and `.md` files, and the guide below tells you not to upload them to the public folder in the first place.
9. **Errors could leak to visitors** — nothing was disabling `display_errors`, so a database or PHP error could show visitors a stack trace with file paths and query details. Added a `SHOW_ERRORS` toggle in `config.php` (off by default, errors still get logged) so you can flip it on temporarily while debugging.
10. **Default admin account uses a public, well-known password** — `database.sql` seeds an admin user (`admin` / `Admin@1234`). This is fine for local testing but **must be changed immediately after your first deploy**, since anyone who's seen this pattern in a tutorial could try logging in with it. Flagged prominently below.

⚠️ **Security note on rotating credentials:** the Meseji SMS key mentioned above, and this default admin password, both need action from you — this fix can't cover things a human needs to actually go change (regenerate the key, log in and change the admin password). I've made sure the app itself won't silently misbehave, but these two items are on you before you consider the site "live."

---

## Step 1 — Create your InfinityFree account & hosting

1. Go to **https://infinityfree.com** and sign up.
2. Create a new hosting account. You'll get either a free subdomain like `yourname.infinityfreeapp.com`, or you can point your own custom domain to it.
3. Wait for account activation (usually instant to a few minutes).

## Step 2 — Create the MySQL database

1. In **vPanel** (InfinityFree's control panel), go to **MySQL Databases**.
2. Create a new database. Note down the three values it gives you:
   - Database host (something like `sqlXXX.infinityfree.com`)
   - Database name (prefixed like `epiz_XXXXXXXX_securevault`)
   - Database username (like `epiz_XXXXXXXX`)
   - Set a strong database password
3. Open **phpMyAdmin** from vPanel for that database.
4. Go to the **Import** tab, and import `database.sql` from the package.
5. Also import `migrations/002_new_features.sql` after that (it adds 2FA, sharing, trash, and folder tables).

## Step 3 — Edit `includes/config.php` before uploading

Open `securevault/includes/config.php` in a text editor and fill in the block marked **EDIT THESE FOR YOUR HOST**:

```php
define('DB_HOST', getenv('MYSQLHOST') ?: 'sqlXXX.infinityfree.com');
define('DB_NAME', getenv('MYSQLDATABASE') ?: 'epiz_XXXXXXXX_securevault');
define('DB_USER', getenv('MYSQLUSER') ?: 'epiz_XXXXXXXX');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: 'your-db-password');

define('BASE_PATH', '/');   // leave as '/' if uploading straight into htdocs
```

Use the exact values from Step 2.

### Set up email (for password reset)

1. Create a free Brevo account at **https://www.brevo.com** (free tier: 300 emails/day).
2. Under **Senders & IP**, verify a sender email address (or your domain, if you have one).
3. Under **SMTP & API → API Keys**, generate an API key.
4. Fill in:

```php
define('BREVO_API_KEY',   'your-real-brevo-api-key');
define('MAIL_FROM_EMAIL', 'no-reply@yourdomain.com'); // must match a verified sender
define('MAIL_FROM_NAME',  'SecureVault');
```

If you skip this, the app won't crash — `send_email()` just logs "would have sent" and returns `false` — but users won't actually receive reset emails.

### Set up SMS (optional, for 2FA)

Your Meseji key was removed for security. Get a new one at **https://meseji.co.tz** and paste it into `AT_API_KEY`.

### Set your encryption key

Change `ENCRYPTION_KEY` to your own random 32-character string — this is what encrypts every uploaded file, so treat it like a password and don't lose it (you can't decrypt files without it).

## Step 4 — Upload the files

Use **FTP** (recommended) via FileZilla, or InfinityFree's built-in Online File Manager:

- **FTP details** are in vPanel → FTP Accounts.
- Upload the **contents** of the `securevault/` folder directly into `htdocs/` — not the `securevault` folder itself. So `htdocs/index.php`, `htdocs/login.php`, `htdocs/includes/`, etc.
- Make sure the `uploads/` folder (with its `.htaccess`) comes along — that's where encrypted files are stored on disk.
- **Do not upload** `database.sql`, `migrations/`, `README.md`, or `SETUP_NEW_FEATURES.md` — those are only needed locally / for phpMyAdmin import in Step 2. The `.htaccess` now blocks direct web access to `.sql`/`.md` files as a backup, but it's cleaner to just leave them off the server entirely.

## Step 4.5 — Change the default admin password immediately

`database.sql` seeds a default admin account:

- Username: `admin`
- Password: `Admin@1234`

This is a known, public value the moment this pattern is shared anywhere. As soon as your site is live, **log in as admin and change this password** (Profile → Change Password), or update the seeded hash in `database.sql` before importing it. Don't leave the default in place on a publicly reachable site.

## Step 5 — Set the PHP version

In vPanel, find the PHP version selector for your domain and choose **PHP 8.1** (or newer if offered) — the code uses PHP 8.1 syntax (`never` return types).

## Step 6 — Test it

Visit your site (e.g. `https://yourname.infinityfreeapp.com`) and check:

- [ ] Registration and login work
- [ ] File upload/download/decrypt round-trips correctly
- [ ] Password reset email arrives (check spam folder too)
- [ ] Share links use the correct domain/path
- [ ] Admin dashboard is reachable only for the admin role
- [ ] You've changed the default admin password (Step 4.5)
- [ ] `yoursite.com/database.sql` returns a 403/404, not the file itself

If something breaks and you need to see the real error, temporarily set `SHOW_ERRORS` to `true` near the top of `includes/config.php`, reproduce the issue, then **set it back to `false`** before leaving the site live.

---

## Fixing "ERR_TOO_MANY_REDIRECTS" / "the page isn't working"

I audited every redirect in this codebase (`index.php`, `login.php`, `SessionManager.php`,
all `.htaccess` files). A fresh, logged-out visit to the site does exactly **one**
redirect — `/` → `/login.php` — and stops there. There is no loop in the app code.

When you see `ERR_TOO_MANY_REDIRECTS` on InfinityFree, it's almost always happening
**before PHP even runs**, at the SSL layer:

- InfinityFree's free SSL for custom domains proxies your site through Cloudflare.
- If Cloudflare's SSL/TLS mode is set to **"Flexible"** *and* InfinityFree (or a
  setting in your own DNS/Cloudflare page rules) also forces HTTP → HTTPS, you get
  a loop: Cloudflare sends the request to the origin as HTTP → origin redirects it
  to HTTPS → Cloudflare receives that, but re-sends the *next* hop to the origin as
  HTTP again → repeat forever. The browser sees this as endless redirects.

**How to fix it:**

1. In Cloudflare (if your domain uses it), go to **SSL/TLS** → set the mode to
   **"Full"** (not "Flexible"). This stops Cloudflare from downgrading to HTTP
   when it talks to the origin.
2. In InfinityFree's **vPanel**, check **Domains** → your domain → SSL settings,
   and make sure "Force HTTPS" isn't enabled in *two* places at once (e.g. both
   Cloudflare's "Always Use HTTPS" page rule and InfinityFree's own redirect).
   Turn one of them off.
3. Clear your browser cache/cookies for the domain (or test in a private/incognito
   window) — a previously-cached redirect can make it look like the loop is still
   happening after you've fixed the setting.
4. Give DNS/SSL changes 15–30 minutes to propagate before retesting.
5. If you're not using a custom domain (just the free `yourname.infinityfreeapp.com`
   subdomain), this shouldn't apply — in that case, double-check you don't have an
   old browser HSTS redirect cached for that exact hostname from a previous test;
   try a different browser or incognito window to confirm.

Separately — and this **will** also break the site, just with a different symptom
(a clear setup message now, instead of a blank page) — `includes/config.php` still
needs your real InfinityFree database values filled into the
`// EDIT THESE FOR YOUR HOST` block (Step 3 above). I added a check so that if you
forget, you'll get an explicit "setup incomplete" message telling you exactly what
to fill in, rather than a confusing crash.

## Known InfinityFree limitations to plan around

| Limit | Free tier | Impact |
|---|---|---|
| Daily hits | 30,000/day | Fine for coursework demo/small use |
| Database size | ~50MB | Metadata only (files are on disk), so this covers a lot of users |
| Upload size | ~10MB per file | Hard cap, not overridable on free tier |
| Outbound SMTP | Blocked | Solved via Brevo HTTP API above |
| Outbound HTTP/cURL | Allowed | Used for both SMS and email |
| Cron jobs | Not available on free tier | Not currently required by this app |
| Uptime | No SLA (community reports ~97%) | Fine for demos; not for production-critical use |

If this needs to be reliably available (e.g. for grading or a live demo), consider your existing fallback of **Koyeb**, which supports real SMTP and doesn't have these caps.

## Files in the package

- `securevault_infinityfree_ready.zip` — the fixed, ready-to-upload app
- This guide
