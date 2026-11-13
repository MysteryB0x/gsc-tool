# GSC Tool — notes for working on this repo

Search Console analytics on BigQuery. Laravel 13 + Filament 5, MariaDB in production (SQLite locally and in tests). Built to run light on shared hosting (Vimexx/DirectAdmin): no Redis, database queue/cache/session, cron-driven. Stand-alone: its own code and its own database. It borrows proven patterns from WhoCitedMe (accounts, plans, credit ledger, service-account client, two-factor login) as copied code, never as a shared package or shared data.

The name is configuration: `APP_NAME` / `GSCT_NAME` in `.env` (`config/gsctool.php`). Never hardcode it.

## Layout
- `/app` — the tool (Filament), multi-tenant by `Team` (slug in the URL). Pages in `app/Filament/App/Pages`, resources in `app/Filament/App/Resources`.
- `/admin` — owner panel, only for users with `is_admin`: users, workspaces (add credits, Google account, site sharing), plans, BigQuery cost log, Branding (`App\Support\Branding`: name, logos, colours, font, home screen; stored in `settings` as `branding.*`, injected as CSS, no theme build).
- `config/gsctool.php` — name, colour, sign-ups, Google, BigQuery, credits, report thresholds, CTR curve.
- `app/Google` — `Contracts\CredentialProvider` with `ServiceAccountProvider` (JWT, no SDK), `ImpersonatedProvider` (a workspace's own account, `GOOGLE_WORKSPACE_ACCOUNTS`, made by `WorkspaceAccounts`) and `OAuthProvider` (built, switched off: `GOOGLE_OAUTH_ENABLED`); `Credentials` picks one per property. `SearchConsole`, `AnalyticsData` (GA4) and `BigQuery` REST clients.
- `app/Analytics` — GA4 sync into `ga4_daily`, `ga4_ai_referrals` (sources: `gsctool.ga4.ai_sources`), `ga4_landing_pages`; `Ga4Summary`. GA4 uses no credits.
- `app/Alerts` — `ChangeDetector` (daily: newest day vs an average day the week before; weekly: 7 vs 7 days, pages, action impact 4 weeks on), `DigestSender` (email + bell, once per period, only when something changed); per-user settings in `alert_preferences`, history in `alert_digests`.
- `app/Reports/Opportunities.php` — every report row as one ranked list: the inbox page.
- `app/Search/SiteKey.php` — one site, however it's written; a site belongs to one workspace unless `teams.may_share_properties`.
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
- Hosting changes go into `INSTALL.md` (the step-by-step Vimexx install) and `docs/setup.md` in the same commit.
- Calendar days use the `App\Casts\DateOnly` cast (stored `Y-m-d`), never Laravel's `date` cast.
- Google access for customers goes through `Credentials::forProperty()`: never the app's account for a workspace without `uses_app_account` when workspace accounts are on.
- English UI only.

## Commands
- `php artisan app:install` — plans + owner account + workspace (unlimited credits)
- `php artisan app:check` — service account, Search Console and BigQuery reachable?
- `php artisan gsc:sync [--property=ID] [--backfill]` — queue the sync + report build
- `php artisan reports:build [--property=ID]` — rebuild reports inline
- `php artisan alerts:send daily|weekly` — send change digests now
- `php artisan test` — run the tests
- `vendor/bin/pint` — format
