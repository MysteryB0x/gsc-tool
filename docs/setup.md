# GSC Tool — setup

The step-by-step install on Vimexx is [INSTALL.md](../INSTALL.md); this page explains each part.

## 1. Google Cloud (once)

Use one Google Cloud project for the tool. Replace `PROJECT_ID`.

```bash
gcloud services enable searchconsole.googleapis.com bigquery.googleapis.com analyticsdata.googleapis.com \
  iam.googleapis.com iamcredentials.googleapis.com --project=PROJECT_ID

# The tool's own identity
gcloud iam service-accounts create gsc-tool --project=PROJECT_ID
for ROLE in roles/bigquery.user roles/bigquery.dataEditor; do
  gcloud projects add-iam-policy-binding PROJECT_ID \
    --member=serviceAccount:gsc-tool@PROJECT_ID.iam.gserviceaccount.com --role=$ROLE
done
gcloud iam service-accounts keys create gsc-tool.json \
  --iam-account=gsc-tool@PROJECT_ID.iam.gserviceaccount.com

# Let Search Console Bulk Export write into this project
for ROLE in roles/bigquery.jobUser roles/bigquery.dataEditor; do
  gcloud projects add-iam-policy-binding PROJECT_ID \
    --member=serviceAccount:search-console-data-export@system.gserviceaccount.com --role=$ROLE
done
```

### Per-workspace Google accounts (before sign-ups open)

With `GOOGLE_WORKSPACE_ACCOUNTS=true` every workspace gets its own service account (`ws-<id>-…@PROJECT_ID.iam.gserviceaccount.com`), created by the app. Customers add *that* email in Search Console and GA4, so a grant only reaches their workspace. The app borrows each account's identity with short-lived tokens: still one key file. Your own workspace (made by `app:install`) keeps using the main account.

```bash
for ROLE in roles/iam.serviceAccountAdmin roles/iam.serviceAccountTokenCreator; do
  gcloud projects add-iam-policy-binding PROJECT_ID \
    --member=serviceAccount:gsc-tool@PROJECT_ID.iam.gserviceaccount.com --role=$ROLE
done
```

A project holds 100 service accounts by default; request more (IAM → Quotas) before you pass ~80 workspaces. Admin → Workspaces shows each account and can create a missing one. `app:check` refuses sign-ups without this switch on.

A site can be in one workspace only; Admin → Workspaces → *May add sites that another workspace already has* allows an exception (an agency and its client).

Set a **budget alert** on the project (Billing → Budgets & alerts), e.g. €10 and €25 a month.

The `gsc_api` dataset is created by the app on the first sync (location `BIGQUERY_LOCATION`, default EU).

### Per property

1. **Search Console → Settings → Users and permissions → Add user:** the email the app shows under Settings → Properties (the main service account, or the workspace's own one), permission *Restricted*.
2. **GA4 (optional):** GA4 → Admin → Property access management → add the same email as *Viewer*. In the app, enter the GA4 property ID (Admin → Property details) on the property. GA4 syncs nightly with Search Console; it uses no credits.
3. **Properties you own:** Search Console → Settings → **Bulk data export** → Cloud project ID = `PROJECT_ID`, location **EU**. It has no backfill: turn it on as early as possible. In the app, pick *Bulk Export* and enter the dataset name it creates (default `searchconsole`).
4. Add the property in the app (**Settings → Properties**). It checks access and starts the 16-month backfill in the background.

## 2. Vimexx / DirectAdmin

Same layout as the other apps on the account: code in `~/apps/gsc-tool`, backups in `~/backups`.

```bash
cd ~/apps
git clone git@github.com:MysteryB0x/gsc-tool.git
cd gsc-tool
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
mkdir -p ~/secrets && mv ~/gsc-tool.json ~/secrets/   # the key file, outside the web root
```

`.env` (production):

```env
APP_NAME="GSC Tool"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://gsc.example.com

DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_DATABASE=USER_gsctool
DB_USERNAME=USER_gsctool
DB_PASSWORD=change-me

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database

GOOGLE_SERVICE_ACCOUNT_JSON=/home/USER/secrets/gsc-tool.json
BIGQUERY_PROJECT=PROJECT_ID
BIGQUERY_LOCATION=EU
GSCT_REGISTRATION=false
GOOGLE_WORKSPACE_ACCOUNTS=false

# Alert emails (Vimexx SMTP: use a mailbox on your domain)
MAIL_MAILER=smtp
MAIL_HOST=mail.example.com
MAIL_PORT=587
MAIL_USERNAME=alerts@example.com
MAIL_PASSWORD=change-me
MAIL_FROM_ADDRESS=alerts@example.com
MAIL_FROM_NAME="${APP_NAME}"
```

**Branding:** Admin → Branding sets the name, logos, favicon, colours, font and the home screen (dashboard or opportunity inbox). Uploads are stored in `storage/app/private/branding` and served by the app (no `storage:link` needed); include that folder in file backups.

Two-factor login: the admin panel requires an authenticator app by default (`TWO_FACTOR_ADMIN=required`); on first login it asks you to scan a QR code and gives recovery codes. Keep those somewhere safe.

Then:

```bash
php artisan migrate --force
php artisan app:install        # plans, your admin account, your workspace
php artisan app:check          # service account, Search Console, BigQuery
php artisan filament:optimize && php artisan optimize
```

**Web root:** in DirectAdmin, point the (sub)domain's `public_html` at the app's `public/` folder:

```bash
rm -rf ~/domains/gsc.example.com/public_html
ln -s ~/apps/gsc-tool/public ~/domains/gsc.example.com/public_html
```

**Cron** (DirectAdmin → Cron Jobs, every minute):

```
* * * * * cd ~/apps/gsc-tool && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler starts a short queue worker each minute (`queue:work --stop-when-empty --max-time=55`), so syncs and report builds run without a long-running process. Nightly at 04:30 it syncs Search Console and GA4 and rebuilds reports; change alerts go out daily at 07:30 and weekly on Monday at 07:45 (`GSCT_TIMEZONE`), only to people with something big to see.

**Backups** (daily cron, 03:15):

```
15 3 * * * mysqldump USER_gsctool | gzip > ~/backups/gsc-$(date +\%F).gz
```

Raw search data is in BigQuery, so the MariaDB dump stays small.

**PHP:** 8.3+ with `curl`, `dom`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, also for the command-line PHP that runs cron. `php artisan app:check` warns about missing ones.

## 3. Updating

```bash
cd ~/apps/gsc-tool && git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force && php artisan filament:optimize && php artisan optimize
```

## 4. Moving to another server

Copy `.env`, the key file, `storage/app/private/branding` and a database dump; follow section 2. BigQuery data doesn't move: it stays in the Google project.
