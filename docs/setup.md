# GSC Tool — setup

## 1. Google Cloud (once)

Use one Google Cloud project for the tool. Replace `PROJECT_ID`.

```bash
gcloud services enable searchconsole.googleapis.com bigquery.googleapis.com analyticsdata.googleapis.com --project=PROJECT_ID

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

Set a **budget alert** on the project (Billing → Budgets & alerts), e.g. €10 and €25 a month.

The `gsc_api` dataset is created by the app on the first sync (location `BIGQUERY_LOCATION`, default EU).

### Per property

1. **Search Console → Settings → Users and permissions → Add user:** the service account email, permission *Restricted*.
2. **Properties you own:** Search Console → Settings → **Bulk data export** → Cloud project ID = `PROJECT_ID`, location **EU**. It has no backfill: turn it on as early as possible. In the app, pick *Bulk Export* and enter the dataset name it creates (default `searchconsole`).
3. Add the property in the app (**Settings → Properties**). It checks access and starts the 16-month backfill in the background.

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
```

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

The scheduler starts a short queue worker each minute (`queue:work --stop-when-empty --max-time=55`), so syncs and report builds run without a long-running process. Nightly at 04:30 it syncs the last days and rebuilds reports.

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

Copy `.env`, the key file and a database dump; follow section 2. BigQuery data doesn't move: it stays in the Google project.
