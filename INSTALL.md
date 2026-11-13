# Install on Vimexx (DirectAdmin)

Everything you run to get the current version live, in order. Replace these placeholders:

| Placeholder | What |
| --- | --- |
| `USER` | your DirectAdmin username (home folder `/home/USER`) |
| `tool.example.com` | the (sub)domain the tool runs on |
| `USER_gsctool` | the database, user and password from step 2 |
| `PROJECT_ID` | your Google Cloud project (step 9, can wait) |

The app runs without Google: you can install, log in and set branding first. Search Console, GA4 and BigQuery start working once step 9 is done.

Same layout as your other apps on this account: code in `~/apps`, backups in `~/backups`, secrets in `~/secrets`.

## 1. In DirectAdmin (once)

1. **PHP:** *Select PHP version* → **8.3 or newer**. Extensions: `curl`, `dom`, `fileinfo`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `zip`.
2. **Database:** *MySQL Management* → *Create new database* → name it `gsctool`. DirectAdmin makes it `USER_gsctool`; write down the user and password.
3. **Domain:** either *Domain Setup* → add `tool.example.com`, or *Subdomain Management* → add a subdomain of a domain you have (e.g. `gsc` under seomarketeer.eu).
4. **SSL:** *SSL Certificates* → Let's Encrypt → tick the (sub)domain → issue. DNS must point at the server first.
5. **SSH** must be on for your package.

## 2. Check the server (SSH)

```bash
php -v                 # must say 8.3 or newer; if not, find the right one:
ls /usr/local/php8*/bin/php 2>/dev/null; which php
composer --version     # missing? see the note under step 4
php -m | grep -iE 'curl|dom|fileinfo|intl|mbstring|openssl|pdo_mysql|zip'
```

If plain `php` is older than 8.3, use the full path (for example `/usr/local/php83/bin/php`) instead of `php` in every command below **and** in the cron line.

## 3. Give the server read access to the repo

The repo is private, so the server needs its own deploy key (GitHub allows one repo per deploy key, so don't reuse WhoCitedMe's).

```bash
ssh-keygen -t ed25519 -f ~/.ssh/gsc-tool -N "" -C "vimexx gsc-tool"
cat >> ~/.ssh/config <<'EOF'
Host github-gsc-tool
  HostName github.com
  User git
  IdentityFile ~/.ssh/gsc-tool
  IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config
cat ~/.ssh/gsc-tool.pub
```

Copy the printed key to GitHub → **MysteryB0x/gsc-tool → Settings → Deploy keys → Add deploy key** (read access only). Then:

```bash
ssh -T github-gsc-tool    # "Hi MysteryB0x/gsc-tool! You've successfully authenticated…"
```

## 4. Get the code

```bash
mkdir -p ~/apps ~/backups ~/secrets
cd ~/apps
git clone github-gsc-tool:MysteryB0x/gsc-tool.git
cd gsc-tool
cp .env.example .env
```

No `composer` on the server: `curl -sS https://getcomposer.org/installer | php`, then use `php composer.phar` wherever it says `composer`.

## 5. Settings (`.env`)

```bash
nano .env
```

Change these lines (leave the rest as it is):

```env
APP_NAME="GSC Tool"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tool.example.com

DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=USER_gsctool
DB_USERNAME=USER_gsctool
DB_PASSWORD=the-password-from-step-1

SESSION_SECURE_COOKIE=true

# Only you for now
GSCT_REGISTRATION=false
GSCT_TIMEZONE=Europe/Amsterdam

# Google (step 9). Fine to leave like this until then.
GOOGLE_SERVICE_ACCOUNT_JSON=/home/USER/secrets/gsc-tool.json
BIGQUERY_PROJECT=
GOOGLE_WORKSPACE_ACCOUNTS=false

# Alert emails (step 8). "log" = alerts only in the app's bell until then.
MAIL_MAILER=log
```

The `DB_*` lines in `.env.example` may be commented out with `#`: remove the `#`.

## 6. Install

```bash
cd ~/apps/gsc-tool
composer install --no-dev --optimize-autoloader
php artisan key:generate --force
php artisan migrate --force
php artisan app:install
php artisan filament:optimize
php artisan optimize
```

`app:install` asks for your email, name, a password (12+ characters) and the workspace name. It creates the plans, your admin account and your workspace on the Owner plan (unlimited credits, every query still logged).

## 7. Web root and cron

Point the domain at the app's `public/` folder. The app itself stays outside `public_html`.

For a **domain** (`tool.example.com`):

```bash
cd ~/domains/tool.example.com
mv public_html public_html_old
ln -s ~/apps/gsc-tool/public public_html
```

For a **subdomain** (`gsc` under `example.com`):

```bash
cd ~/domains/example.com/public_html
mv gsc gsc_old
ln -s ~/apps/gsc-tool/public gsc
```

Check: `curl -sI https://tool.example.com/app/login | head -1` should say `200`.

**Cron:** DirectAdmin → *Advanced Features* → *Cron Jobs*, every minute:

```
* * * * * cd /home/USER/apps/gsc-tool && php artisan schedule:run >> /dev/null 2>&1
```

(Full PHP path instead of `php` if step 2 told you so.) That one line does everything: a short queue worker every minute for syncs and report builds, the nightly Search Console + GA4 sync at 04:30, alerts daily at 07:30 and weekly on Monday at 07:45, monthly plan credits.

**Backups:** first let `mysqldump` log in without a password on the command line:

```bash
cat > ~/.my.cnf.gsctool <<'EOF'
[mysqldump]
user=USER_gsctool
password=the-password-from-step-1
EOF
chmod 600 ~/.my.cnf.gsctool
```

Then a second cron line (daily 03:15):

```
15 3 * * * mysqldump --defaults-extra-file=/home/USER/.my.cnf.gsctool USER_gsctool | gzip > /home/USER/backups/gsc-$(date +\%F).gz && tar czf /home/USER/backups/gsc-files-$(date +\%F).tgz -C /home/USER/apps/gsc-tool .env storage/app/private
```

Delete old ones now and then (`ls -t ~/backups/gsc-* | tail -n +29 | xargs rm -f` keeps the newest 14 days).

Raw search data lives in BigQuery, so these stay small. The second part keeps `.env` and your uploaded logos.

## 8. First login

1. Open `https://tool.example.com/admin` and log in. It asks you to set up two-factor login: scan the QR code with an authenticator app and **store the recovery codes** somewhere safe.
2. **Admin → Branding:** name, logos, colours, font and home screen (dashboard or opportunity inbox).
3. The tool itself is at `https://tool.example.com/app`.

**Alert emails** (optional now): make a mailbox in DirectAdmin (*E-mail Manager*, e.g. `alerts@yourdomain`), then in `.env`:

```env
MAIL_MAILER=smtp
MAIL_HOST=mail.yourdomain
MAIL_PORT=587
MAIL_USERNAME=alerts@yourdomain
MAIL_PASSWORD=the-mailbox-password
MAIL_FROM_ADDRESS=alerts@yourdomain
MAIL_FROM_NAME="${APP_NAME}"
```

and run `php artisan optimize` (needed after **every** `.env` change, because the settings are cached).

## 9. Google (when you're ready)

Run these **on your laptop or in Google Cloud Shell** (console.cloud.google.com → the `>_` icon), not on Vimexx. Replace `PROJECT_ID`.

```bash
gcloud services enable searchconsole.googleapis.com bigquery.googleapis.com analyticsdata.googleapis.com \
  iam.googleapis.com iamcredentials.googleapis.com --project=PROJECT_ID

gcloud iam service-accounts create gsc-tool --project=PROJECT_ID
for ROLE in roles/bigquery.user roles/bigquery.dataEditor; do
  gcloud projects add-iam-policy-binding PROJECT_ID \
    --member=serviceAccount:gsc-tool@PROJECT_ID.iam.gserviceaccount.com --role=$ROLE
done

# Search Console Bulk Export may write into this project
for ROLE in roles/bigquery.jobUser roles/bigquery.dataEditor; do
  gcloud projects add-iam-policy-binding PROJECT_ID \
    --member=serviceAccount:search-console-data-export@system.gserviceaccount.com --role=$ROLE
done

gcloud iam service-accounts keys create gsc-tool.json \
  --iam-account=gsc-tool@PROJECT_ID.iam.gserviceaccount.com
```

Upload the key to the server, outside the web root:

```bash
scp gsc-tool.json USER@your-vimexx-host:~/secrets/      # from your laptop
```

On the server: `chmod 600 ~/secrets/gsc-tool.json`, set `BIGQUERY_PROJECT=PROJECT_ID` in `.env`, then:

```bash
cd ~/apps/gsc-tool
php artisan optimize
php artisan app:check     # service account, Search Console and BigQuery: all three should be OK
```

Also in Google Cloud: **Billing → Budgets & alerts**, e.g. €10 and €25 a month.

Then per site:

1. **Search Console → Settings → Users and permissions → Add user:** the email shown under *Settings → Properties* in the app, permission *Restricted*.
2. **GA4** (optional): *Admin → Property access management* → same email as *Viewer*. Copy the GA4 *Property ID* (Admin → Property details).
3. **Sites you own:** Search Console → *Settings → Bulk data export* → project `PROJECT_ID`, location **EU**. Start this early: it has no history.
4. In the app: **Settings → Properties → Add property**. It checks access and starts the 16-month backfill in the background (allow an hour or two); reports appear when it's done.

`GOOGLE_WORKSPACE_ACCOUNTS` stays `false` while it's only you. Before opening sign-ups, see *Per-workspace Google accounts* in `docs/setup.md`.

## Updating later

```bash
cd ~/apps/gsc-tool
php artisan down
git pull --ff-only
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan filament:optimize
php artisan optimize
php artisan up
```

## If something's wrong

| Symptom | Check |
| --- | --- |
| 500 error | `tail -50 ~/apps/gsc-tool/storage/logs/laravel.log` |
| Changed `.env`, nothing happens | `php artisan optimize` |
| Syncs or reports never run | the cron line (step 7) and its PHP path; `php artisan schedule:list` |
| "Service account key file not readable" | path in `GOOGLE_SERVICE_ACCOUNT_JSON`, and `chmod 600` on the file |
| A property says "No access" | the app's email isn't added in Search Console (or GA4) yet |
| No alert emails | `MAIL_*` in `.env`, then Settings → Alerts → *Preview this week* in the app |

More detail on each part: `docs/setup.md`.
