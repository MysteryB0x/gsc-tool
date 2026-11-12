# GSC Tool

Search Console analytics on BigQuery: content decay, cannibalisation, striking-distance keywords, CTR benchmark, an Explorer on the raw data, and actions you can mark done and see on the trend. Laravel 13 + Filament 5, built to run on shared hosting.

The name is a setting (`APP_NAME` / `GSCT_NAME` in `.env`), so rebranding needs no code change.

## How it works

```
Search Console API ─┐                         nightly SQL
                    ├─► BigQuery (EU) ─────────────────────► MariaDB report tables ─► Filament UI
Bulk Export ────────┘   partitioned by day                    small, pre-computed
                                          ▲
                        Explorer queries ─┘  (metered in credits)
```

- **Properties** use a Google service account: add its email as a (Restricted) user in Search Console. Properties you own can use **Bulk Export** for full data; the rest are synced through the API into a table with the same columns.
- **Reports** are built nightly in BigQuery and stored in MariaDB. Pages never query BigQuery live.
- **Credits:** 1 credit = 1 GiB billed by BigQuery. Every query is dry-run first, the credits are reserved, BigQuery is told never to bill more (`maximumBytesBilled`), and unused credits go back. Repeats within 24 hours are free. The owner plan has unlimited credits but every query is still logged (Admin → BigQuery queries).
- **Sign-ups are off** (`GSCT_REGISTRATION=false`): it's your tool until you switch paid plans on.

## Local setup

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate --seed
php artisan app:install          # your admin account + workspace
php artisan serve                # http://localhost:8000/app
```

Google setup and production hosting (Vimexx/DirectAdmin) are in [docs/setup.md](docs/setup.md).

## Tests

```bash
php artisan test
```
