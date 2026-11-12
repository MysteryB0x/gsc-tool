# GSC Tool — notes for working on this repo

Search Console analytics on BigQuery. Laravel 13 + Filament 5, MariaDB in production (SQLite locally and in tests). Built to run light on shared hosting (Vimexx/DirectAdmin): no Redis, database queue/cache/session, cron-driven. Same account model as WhoCitedMe (users, teams, plans, credit ledger) so both can later share one core package.

The name is configuration: `APP_NAME` / `GSCT_NAME` in `.env` (`config/gsctool.php`). Never hardcode it.

## Layout
- `/app` — the tool (Filament), multi-tenant by `Team` (slug in the URL). Pages in `app/Filament/App/Pages`, resources in `app/Filament/App/Resources`.
- `/admin` — owner panel, only for users with `is_admin`: users, workspaces (add credits), plans, BigQuery cost log.
- `config/gsctool.php` — name, colour, sign-ups, Google, BigQuery, credits, report thresholds, CTR curve.
- `app/Google` — `Contracts\CredentialProvider` with `ServiceAccountProvider` (JWT, no SDK) and `OAuthProvider` (built, switched off: `GOOGLE_OAUTH_ENABLED`); `SearchConsole` and `BigQuery` REST clients.
- `app/Search` — where data lives (`Tables`), the API → BigQuery sync (`GscSync`, `SyncPlanner`), and `QueryRunner`: the only way to run BigQuery SQL.
- `app/Reports` — report SQL (`Sql`), nightly build into MariaDB (`ReportBuilder`), the Explorer's SQL builder (`Explorer`).
- `app/Credits` — the ledger (`Credits`), bytes → credits conversion.
- `app/Services/PlanLimits.php` — every plan limit, enforced server-side.

## Rules
- Dashboards and report pages read MariaDB only. BigQuery runs at night (`ReportBuilder`, `charge: false`) or when a user spends credits (Explorer).
- Every BigQuery query goes through `QueryRunner::run()`: dry run → reserve credits → run with `maximumBytesBilled` → settle → log in `bq_queries`. Never call `BigQuery::query()` directly from features.
- Every report query filters on `data_date` (tables require a partition filter). Use `Tables::where()`.
- User input enters SQL only through `Sql::str()` / `Sql::date()` or fixed lists. No raw SQL from users.
- API-synced tables mirror Google's Bulk Export `searchdata_url_impression` columns, so the same SQL runs on both. Position = `SUM(sum_position) / SUM(impressions) + 1`.
- Customer-owned tables have `team_id` or hang off a property that has one; Filament scopes by tenant.
- Admin actions that change money or access are written to `audit_logs` (`AuditLog::record`).
- Hosting changes go into `docs/setup.md` in the same commit.
- English UI only.

## Commands
- `php artisan app:install` — plans + owner account + workspace (unlimited credits)
- `php artisan app:check` — service account, Search Console and BigQuery reachable?
- `php artisan gsc:sync [--property=ID] [--backfill]` — queue the sync + report build
- `php artisan reports:build [--property=ID]` — rebuild reports inline
- `php artisan test` — run the tests
- `vendor/bin/pint` — format
