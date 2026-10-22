# Build progress & decisions (living file)

## Layout
- `api/` — Laravel 13 API (PHP >= 8.4.1; dev container uses 8.5). Pre-existing Inertia "teams" starter left untouched; the marketplace domain lives beside it.
- `packages/shared`, `apps/vendor`, `apps/customer` — React/Vite/Capacitor mobile apps + shared TS lib (npm workspaces at repo root).
- `docker-compose.yml`, `docker/`, `scripts/dphp` — dev PostgreSQL 17 (port 5455) and PHP 8.5 runtime (API on 8787). `scripts/dphp <cmd>` runs a command in the PHP container.

## Key decisions
- Tenancy: own `tenants` + `tenant_memberships(role)` tables (not the starter's `Team`); tenant bound to the Sanctum token row (`personal_access_tokens.tenant_id`), membership re-checked every request (`ResolveTenant`). Composite `(id, tenant_id)` FKs make cross-tenant links impossible at the DB level.
- Money = integer centavos. PH VAT 12% VAT-inclusive prices.
- Stock: `stock_snapshots` (on_hand, reserved) + append-only `stock_ledger_entries` (DB trigger forbids UPDATE/DELETE; CHECK constraints on math and non-negative stock). `StockService` is the only writer; rows locked FOR UPDATE ordered by (item, location).
- Idempotency: `idempotency_keys` row inserted FIRST in the transaction via insertOrIgnore (no catching PG unique violations).
- Sync: `sync_changes` feed, cursor `txid.id`, xmin horizon (config `hardwhere.sync.xmin_horizon`) so late commits never land behind a cursor. Audiences: catalog / public / tenant:ID / user:ID. Null projection => tombstone.
- AI: `VisionProvider` interface; `MockVisionProvider` (default, labelled) and `GemmaVisionProvider` (Gemini API generateContent; models documented by Google: gemma-4-26b-a4b-it, gemma-4-31b-it). Gemma path is NOT live-verified (no key).
- Contract: PHP feature tests write real JSON to `packages/shared/contract/fixtures`; TS type-checks them with `satisfies`. `endpoints.json` is checked against the router.
- Photo search for customers requires a signed-in customer (AI cost control); text search is anonymous.

## Status
- [x] Migrations, models, services, controllers, routes (72), seeders (verified: ledger == snapshots)
- [ ] PHP tests (scenario spine, isolation, concurrency, ...)
- [ ] Shared TS lib, SQLite layer, vendor app, customer app
- [ ] Capacitor platforms, README, final verification report

## Not verifiable on this machine
No JDK/Android SDK/Xcode: APK/AAB and iOS builds are NOT built here. Gemma 4 live inference not run (no API key).
