# Deploying SecureVault to Railway

Railway runs your app in a Docker container rather than shared hosting, which changes a few things from the InfinityFree setup — mostly for the better (real environment variables, no SMTP block, full control over PHP settings), but with two things you must not skip: **persistent storage** for uploads, and **setting Variables** instead of editing `config.php` directly.

⚠️ **Cost note:** Railway is not free like InfinityFree. New accounts get a one-time $5 trial credit (30 days), after which it's the Hobby plan at $5/month minimum (usage billed on top if you exceed the included credit). A small app like this will likely stay near that $5 floor, but budget for it.

## What's in this package for Railway

- **`Dockerfile`** — builds a PHP 8.2 + Apache container with the right extensions (`pdo_mysql`), upload limits, and `.htaccess` support enabled.
- **`docker-entrypoint.sh`** — sets Apache to listen on whatever port Railway assigns at container startup (Railway picks this dynamically, so it can't be hardcoded).
- **`.dockerignore`** — keeps `.git`, `database.sql`, `migrations/`, and docs out of the deployed image.
- **`includes/config.php`** — now reads DB credentials and all secrets (`ENCRYPTION_KEY`, `BREVO_API_KEY`, `AT_API_KEY`, etc.) from environment variables first, with placeholder fallbacks. On Railway, you set these as **Variables** in the dashboard — you don't need to hand-edit this file with real secrets at all.

## Step 1 — Push your code to GitHub

Railway deploys from a GitHub repo. If you haven't already:

```bash
cd path/to/securevault
git add .
git commit -m "Add Railway Dockerfile and env-based config"
git push
```

## Step 2 — Create the Railway project

1. Go to **https://railway.com** → sign up (GitHub sign-in is easiest, and gets you fuller trial network access).
2. **New Project** → **Deploy from GitHub repo** → select your `securevault` repo.
3. Railway will detect the `Dockerfile` and start building automatically.

## Step 3 — Add a MySQL database

1. In your project, click **+ New** → **Database** → **Add MySQL**.
2. Once it's provisioned, click into your **web service** (not the database) → **Variables** tab.
3. Add reference variables pointing at the MySQL service — Railway lets you reference another service's variables directly:
   - `MYSQLHOST` → `${{MySQL.MYSQLHOST}}`
   - `MYSQLDATABASE` → `${{MySQL.MYSQLDATABASE}}`
   - `MYSQLUSER` → `${{MySQL.MYSQLUSER}}`
   - `MYSQLPASSWORD` → `${{MySQL.MYSQLPASSWORD}}`
   - `MYSQLPORT` → `${{MySQL.MYSQLPORT}}`

   (Railway's variable-reference autocomplete will show you the exact names available — click the MySQL service's **Variables** tab first to confirm the exact names it exposes, they're consistently `MYSQLHOST`/`MYSQLUSER`/`MYSQLPASSWORD`/`MYSQLDATABASE`/`MYSQLPORT`.)

4. Import the schema: click into the MySQL service → **Data** tab (or connect with a MySQL client using the connection details shown there) → run `database.sql`, then `migrations/002_new_features.sql`.

## Step 4 — Set the rest of your Variables

Still on your web service's **Variables** tab, add:

```
ENCRYPTION_KEY=<your own random 32-character string>
BREVO_API_KEY=<from brevo.com, once you've set it up>
MAIL_FROM_EMAIL=<a verified Brevo sender>
MAIL_FROM_NAME=SecureVault
AT_USERNAME=<your Meseji username>
AT_API_KEY=<your rotated Meseji key>
AT_SENDER_ID=MESEJI
```

Brevo setup is the same as before: free account at brevo.com, verify a sender, generate an API key under SMTP & API.

## Step 5 — Add a Volume for uploads (do not skip this)

Railway's container filesystem is **wiped on every redeploy**. Without a Volume, every file your users upload disappears the next time you push code or Railway restarts the service.

1. Click your web service → **Settings** → **Volumes** → **+ New Volume**.
2. Mount path: `/var/www/html/uploads`
3. Save — Railway will redeploy with the volume attached.

## Step 6 — Generate a public domain

1. Web service → **Settings** → **Networking** → **Generate Domain**.
2. You'll get a URL like `https://securevault-production-xxxx.up.railway.app`.

## Step 7 — Test it

- [ ] Site loads at the generated domain
- [ ] Registration/login work
- [ ] Upload a file, then trigger a redeploy (e.g. push a trivial commit) — confirm the file is still there afterward (proves the Volume is working)
- [ ] Password reset email arrives
- [ ] Change the default admin password (`admin` / `Admin@1234`) immediately

## If the build fails

Check the **Deployments** tab → click the failed build → read the log. Common causes:
- Typo in the Dockerfile (unlikely if using this one unmodified)
- A Variable referenced in `config.php` isn't set — the app will still boot with placeholder fallbacks, but DB connection will fail if `MYSQLHOST` etc. aren't correctly referenced from the MySQL service

## Custom domain (optional)

Settings → Networking → **Custom Domain** → follow Railway's CNAME instructions with your domain registrar.
