# Hardwhere — multi-tenant hardware retail platform (Philippines)

Two mobile apps and one API for hardware stores:

| | What | Stack |
|---|---|---|
| **Vendor app** (`apps/vendor`) | inventOS inventory + POSsible point-of-sale. Photograph an unbarcoded product, let AI propose the catalog match, confirm, stock it, sell it. | React 19, TypeScript (strict), Vite, Tailwind 4, Flowbite React, Capacitor |
| **Customer app** (`apps/customer`) | Hardware Marketplace: search by text or photo, compare stores, prices and availability, get directions, reserve or order. | same |
| **API** (`api/`) | One authenticated multi-tenant JSON API: tenancy, shared catalog, stock ledger, atomic checkout/reservations, AI intake, sync. | PHP 8.4+, Laravel 13, Eloquent, Sanctum, PostgreSQL (Neon) |
| **Shared library** (`packages/shared`) | API types + client, SQLite layer, sync engine, outbox, session, UI kit. Frontend-safe only. | TypeScript |

> **Demo status — read this first.** The apps are demonstrated **as web apps in a phone-sized browser**. Capacitor
> Android/iOS projects are generated and configured for both apps (distinct ids `ph.hardwhere.vendor` / `ph.hardwhere.market`),
> but **no APK/AAB/IPA was built or run**: the development machine has no JDK/Android SDK/Xcode. Native-only guarantees
> (SQLCipher-encrypted database, Keychain/Keystore token storage, native camera and barcode scanner) are implemented
> against the plugins' real typings but are **unverified on a device**. In the browser the database is *not* encrypted and the
> token lives in `sessionStorage` (development only). Gemma 4 is wired but **not live-verified** (no API key); the demo uses a
> clearly-labelled deterministic mock.

## Architecture

```
 Capacitor/React app ──HTTPS+Bearer──▶ Laravel API ──▶ Neon PostgreSQL (authoritative)
   │  SQLite (SQLCipher on device):                │  Sanctum device tokens (tenant bound to the token row)
   │  scoped cache · drafts · durable outbox       │  Form Requests · Policies/Gates · API Resources
   │  sync cursor/state                            │  Services: Stock · Pos · Order · Reservation · Idempotency
   └──────── never talks to Postgres ─────────────┘  AI: VisionProvider (mock | Gemma 4)
```

* **Server is authoritative.** The phone keeps a scoped *cache*, user *drafts* and a *durable outbox*. Cached stock is always labelled
  "cached · as of …", never presented as live.
* **Multi-tenancy (server-enforced).** The tenant comes from the access-token row, not from the request, and the membership is
  re-checked on every request (`ResolveTenant`). Services take an explicit `TenantContext`; Eloquent global scope is only a
  backstop; composite `(id, tenant_id)` foreign keys make cross-tenant references impossible in the database itself.
* **Stock correctness.** `StockService` is the only writer of `stock_snapshots` and the **append-only** `stock_ledger_entries`
  (a database trigger forbids UPDATE/DELETE; CHECK constraints keep `0 ≤ reserved ≤ on_hand` and ledger arithmetic exact). Rows are
  locked `FOR UPDATE` in a deterministic order; availability is re-checked on the locked rows inside one transaction with the sale/order,
  line items, reservation lines, ledger and the idempotency row — all or nothing.
* **Exactly-once business effect.** Mutations carry an `Idempotency-Key`. The first statement of the transaction is
  `INSERT … ON CONFLICT DO NOTHING` on `(scope, key)`; a concurrent duplicate waits on the unique index and then replays the stored
  result (PostgreSQL aborts a transaction on a unique violation, so the code never relies on catching one). Keys are scoped to tenant + user.
* **Sync.** `snapshot` (paged, resumable) + cursor-based `changes` with **tombstones** (also sent when visibility is revoked, e.g. a
  listing is unpublished or a tenant suspended). Cursor = `txid.id`, served only below `pg_snapshot_xmin`, so a late-committing writer can
  never land behind a saved cursor. The client applies a batch **and** its cursor in one local transaction.
* **Offline contract.** Works offline: browse cached catalog/inventory/stores/orders, search SKU/barcode/text locally, edit drafts,
  capture photos and queue the upload, queue *non-final* stock-adjustment requests. Needs a connection: AI analysis, **POS checkout**
  (no offline paid sales — that would need stock leasing; out of scope), marketplace orders/reservations. A queued command is never "done"
  until the server answers 2xx (or replays an idempotent result); 409 → *needs review*, 422/403 → *rejected*, network/5xx/429 → bounded
  exponential backoff with jitter.
* **AI.** `VisionProvider` interface → `MockVisionProvider` (default, deterministic, every result labelled *Demo AI*) or
  `GemmaVisionProvider` (Gemini API `generateContent`, model ids documented by Google: `gemma-4-26b-a4b-it`, `gemma-4-31b-it`).
  Model output is validated strictly. A matcher combines barcode/GTIN, manufacturer+model, SKU/part numbers, trigram text similarity,
  brand, category and structured variant attributes and returns *exact / likely same / likely variant / possible duplicate / new
  candidate / insufficient confidence*. **Nothing is merged or stocked without a human decision**; the AI result, the user's decision
  and their original input are all stored (`ai_analyses`, `ai_match_candidates`, `ai_decisions`).

## Repository layout

```
api/                  Laravel API (own composer.json). Migrations, models, services, controllers, tests.
apps/vendor|customer  React + Capacitor apps (web-first). android/ and ios/ are generated Capacitor projects.
packages/shared/      @hardwhere/shared — contract, API client, db/sync/session/platform/ui/react.
packages/shared/contract/   endpoints.json + real-response fixtures (see "API contract")
docker-compose.yml    Dev Postgres 17 (host port 5455) + PHP 8.5 runtime / API on :8787
scripts/dphp          Run any command inside the PHP container:  scripts/dphp php artisan test
scripts/demo-reset.sh Fresh schema + deterministic demo data
docs/PROGRESS.md      Running build notes and decisions
```
The repository also still contains the Laravel React starter kit's Inertia/teams web app under `api/` (untouched; its tests still pass).

## Quick start (local, from a clean checkout)

Prerequisites: Docker (recommended) **or** PHP ≥ 8.4.1 with `pdo_pgsql gd intl zip bcmath pcntl exif` + Composer + PostgreSQL 15+; Node ≥ 22.

```bash
# 1. Database + API runtime (Docker). Postgres listens on localhost:5455, API on localhost:8787
docker compose up -d postgres
scripts/dphp composer install
cp api/.env.example api/.env && scripts/dphp php artisan key:generate
scripts/dphp php artisan migrate --seed            # or: scripts/demo-reset.sh
docker compose up -d api                           # http://localhost:8787/api/v1/health

# 2. Mobile apps (web)
npm install                                         # .npmrc sets legacy-peer-deps (npm's resolver crashes on the peer graph otherwise)
npm run dev:vendor                                  # http://localhost:5173
npm run dev:customer                                # http://localhost:5174   (use a 390×844 viewport)
```
Without Docker: copy `api/.env.example` to `api/.env`, point `DB_*` at your PostgreSQL, then `composer install && php artisan key:generate && php artisan migrate --seed && php artisan serve --port=8787`.
(In Docker the API runs under PHP's built-in server rather than `artisan serve`, because `artisan serve` strips environment variables that also appear in `.env`.)

### Demo accounts (all passwords: `password`; seeded data only — never run the demo seeder in production)

| Role | Email | Business slug |
|---|---|---|
| Owner / Store manager (Kamuning) / Inventory manager / Cashier (Kamuning) / Marketplace manager / Staff | `owner.a@`, `manager.a@`, `inventory.a@`, `cashier.a@`, `market.a@`, `staff.a@hardwhere.test` | `dela-cruz-hardware` |
| Owner / Cashier | `owner.b@`, `cashier.b@hardwhere.test` | `mabini-builders` |
| Owner | `owner.c@hardwhere.test` | `visayas-tools` |
| Customers | `ana.customer@`, `ben.customer@hardwhere.test` | — |
| Platform admin | `admin@hardwhere.test` | — |

## Environment variables

**API (`api/.env`)** — see `api/.env.example` (placeholders only):

| Variable | Purpose |
|---|---|
| `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSLMODE` (or `DB_URL`) | PostgreSQL / Neon |
| `VENDOR_TOKEN_TTL_MINUTES` (1440), `CUSTOMER_TOKEN_TTL_MINUTES` (43200) | Sanctum token lifetime |
| `AI_PROVIDER` (`mock` \| `gemma` \| `openai`), `AI_MAX_IMAGE_KB` | AI provider selection |
| `GEMMA_API_KEY`, `GEMMA_MODEL`, `GEMMA_BASE_URL`, `GEMMA_TIMEOUT` | Gemma 4 via the Gemini API |
| `AI_OPENAI_BASE_URL`, `AI_OPENAI_API_KEY`, `AI_OPENAI_MODEL`, `AI_OPENAI_TIMEOUT`, `AI_OPENAI_JSON_MODE` | any OpenAI-compatible vision endpoint (OpenRouter, vLLM, Ollama) |
| `CORS_ALLOWED_ORIGINS` | extra origins (Capacitor `capacitor://localhost`, `https://localhost`, `http://localhost` and Vite dev origins are built in) |
| `TRUSTED_PROXIES` | set behind a load balancer so throttling sees the client IP |
| `SYNC_XMIN_HORIZON` | keep `true`; `false` only inside wrapped test transactions |

**Apps (`apps/*/.env`)** — only `VITE_API_BASE_URL` (public HTTPS origin of the API). It ships in the bundle, so it must never hold a secret. No database credentials or AI keys exist in any mobile bundle.

## Neon PostgreSQL

1. Create a Neon project/database; copy the connection details (`sslmode=require`).
2. Set `DB_*` (or `DB_URL`) in the API's environment. Use the **direct** (non-pooled) endpoint for migrations. For the running app a session-mode pool is fine; avoid PgBouncer *transaction* mode unless you have tested prepared-statement behaviour (unverified here).
3. `php artisan migrate --force` (creates the `pg_trgm` extension; Neon allows it). Never run `--seed` with the demo seeder in production.
4. Schedule `php artisan schedule:run` every minute (expires marketplace reservations, prunes expired tokens).

Rollback: `php artisan migrate:rollback`. Local schema migrations of the phone's SQLite are independent and versioned in `packages/shared/src/db/migrations.ts`.

## AI configuration (Gemma 4)

Three providers sit behind one `VisionProvider` interface; pick one with `AI_PROVIDER` and **restart the API** (`docker compose restart api`) after editing `api/.env`.

**1. `mock` (default) — demo only, never looks at pixels.** The scenario comes from an explicit `hint` (the apps' *Demo photos* chooser), else a keyword in the uploaded
file name (`makita-drill.jpg`), else the mock returns *insufficient confidence* with an explanation instead of guessing. Every result is labelled **Demo AI**.

**2. `openai` — Gemma 4 through any OpenAI-compatible endpoint (OpenRouter, vLLM, local Ollama).**
```
AI_PROVIDER=openai
AI_OPENAI_BASE_URL=https://openrouter.ai/api/v1
AI_OPENAI_API_KEY=<your OpenRouter key>          # server-side only, api/.env is gitignored
AI_OPENAI_MODEL=google/gemma-4-26b-a4b-it:free   # or google/gemma-4-26b-a4b-it, google/gemma-4-31b-it (image input confirmed in OpenRouter's model list)
AI_OPENAI_JSON_MODE=false                        # true adds response_format=json_object where the backend supports it
```
Sends `POST {base}/chat/completions` with a text part and an `image_url` data URI. Local Ollama: `AI_OPENAI_BASE_URL=http://localhost:11434/v1`, no key needed.
(The request shape was first prototyped by Mark Heramis on branch `feature/image-recognition`.)

**3. `gemma` — Gemma 4 through the Gemini API** (`GEMMA_API_KEY`, `GEMMA_MODEL=gemma-4-26b-a4b-it` or `gemma-4-31b-it`): `POST {base}/models/{model}:generateContent` with `inline_data`.

In every live mode the model's reply is validated strictly (clamped confidences, whitelisted attribute keys, bounded lengths) and fed to the same catalog matcher; a failure answers `503 ai_unavailable`
and the apps offer manual entry (inference is never faked offline). **The request contracts are covered by HTTP-fake tests; no live endpoint has been exercised in this repository** — to verify live,
set the variables, upload a photo to `POST /api/v1/vendor/ai/analyses` (multipart `image`, header `Idempotency-Key`) and confirm `is_mock=false` in the response.

## API contract (server ↔ TypeScript)

PHP validation is *not* shared with TypeScript. Instead: `packages/shared/contract/endpoints.json` lists every endpoint the apps call and a PHP test
checks it against the Laravel router **in both directions** (new routes must be declared); the same tests write real JSON responses to
`contract/fixtures/`, and `contract/check.ts` type-checks them against the DTOs with `satisfies`, so drift fails `tsc`.

## Tests

```bash
scripts/dphp php artisan test                 # API: 247 tests on a real PostgreSQL database (hardwhere_test)
scripts/dphp vendor/bin/pint --test           # style
scripts/dphp vendor/bin/phpstan analyse       # level 5 + baseline
npm test                                      # shared TS: SQLite migrations, partitioning, sync, outbox, session (185 tests)
npm run typecheck && npm run build            # strict TS for shared + both apps, static Vite builds (dist/index.html)
```
PHP coverage includes: tenant isolation (API, Eloquent scope, composite FKs), role matrix, token lifecycle, stock ledger integrity (append-only trigger, CHECKs), POS checkout, marketplace orders and reservations (state machine, expiry), listing visibility, price isolation, AI extraction validation and the Gemma request contract, matcher (barcode/SKU/variants/duplicates), upload hardening, sync (snapshot, deltas, tombstones, revoked visibility), seed integrity, error envelope, and **real parallel-process concurrency** (`tests/Concurrency`: N PHP processes race for the same stock on committed data; plus an xmin-horizon test with two live connections).

## Demo script (the 18-step scenario)

`api/tests/Feature/DemoScenarioTest.php` runs this end to end over HTTP; the apps reproduce it by hand:

1. Vendor app → sign in as `staff.a@hardwhere.test` (Dela Cruz Hardware). 2. **Add** → photograph an unbarcoded power tool (use *Demo photo → makita-hp1630*).
3. Review the AI result: confidence, matched fields, warnings, candidate variants (HP1630 vs HP1630K). 4. Confirm the variant; enter SKU, cost, price, qty, store → stock + opening ledger entry created.
5. `market.a@` publishes the listing. 6. Customer app → search "hammer drill" (or photo) → Dela Cruz now appears among stores with price, availability, address, **Directions**, **Call**.
7. Reserve 1 unit → *Reserved* with expiry. 8. Cashier tries to sell the same last unit → server refuses (`stock_unavailable`), even from a stale cache. 9. Restock; sale succeeds → receipt, ledger entry, snapshot updated; both caches refresh on sync.
10. Marketplace manager moves the order Confirmed → Preparing → Ready → Fulfilled. 11. `owner.b@` (another business) cannot see any of tenant A's inventory, costs, staff, sales or orders — the canonical product is still shared.

Reset anytime: `scripts/demo-reset.sh`.

## Native builds (not verified here)

```bash
cd apps/vendor && npm run cap:sync && npx cap open android    # needs JDK + Android Studio
cd apps/vendor && npx cap open ios                              # needs macOS + Xcode (SPM, no CocoaPods)
# debug builds that call a plain-http LAN API:  CAP_ALLOW_CLEARTEXT=1 npx cap sync   (production must use HTTPS)
```
Camera/photos (both apps) and coarse location (customer) permission strings are declared in `AndroidManifest.xml` / `Info.plist`.

## Known limitations & hackathon trade-offs

* No native build/device verification (see above). Browser storage is unencrypted dev storage.
* Gemma 4 not exercised live (the `openai` and `gemma` providers are tested against HTTP fakes only). Image *similarity* uses AI-extracted attributes only; there is no vector index (`products.embedding_ref` is reserved).
* Offline *sales* are out of scope by design: stock cannot be kept consistent across disconnected devices without allocation/leasing.
* Customer photo search requires a signed-in customer (AI cost control); text search is anonymous.
* Browser dev note: the browser SQLite (jeep-sqlite) hydrates via `requestAnimationFrame`, so a tab opened in the *background* shows "Opening your store…" until it is shown. Use one focused tab; the web token lives in `sessionStorage`, so a new tab needs a fresh sign-in.
* Trimmed UI scope for the demo: store/staff/supplier/sales-history/purchase-receipt screens are not built (their API endpoints exist and are tested).
* `sync_changes` is append-only and not pruned; cursors are cluster-local (`xid8`) — restoring to a new cluster needs a cursor epoch.
* Payment is recorded, not processed: no card data is ever accepted (card-number-shaped references are rejected).

## Production hardening checklist

HTTPS only and HSTS; `APP_DEBUG=false`; `config:cache`/`route:cache`; real queue worker and scheduler; log redaction review; `TRUSTED_PROXIES`; per-IP + per-account login throttling and email verification flow; absolute session lifetime; prune `sync_changes` and add a cursor epoch; replace the trigram scan in public search with an index-assisted query; object storage (private bucket) for AI uploads with retention policy; load-test the checkout path on Neon; verify PgBouncer mode; native device test pass (encryption, keystore, camera, permissions, back-button, low-memory kill); store signing.
