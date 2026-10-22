# Demo database and data set: what changed

Branch: `feat/demo-dataset-cebu` (based on `master` @ `04d8ef7`).

The PostgreSQL schema from `master` already covered the requirements (tenancy, canonical catalog, inventory, append-only ledger,
POS, orders, reservations, AI, sync). **No migration was changed or added.** This branch replaces the small demo seed with a
realistic data set built around the business plan: Metro Cebu beachhead, unbarcoded hardware, spec-sensitive variants.

## What was added

### 1. Canonical catalog (`api/database/seeders/data/catalog.php`)
- **70 categories**: the 13 required top-level categories, plus 57 subcategories (for example `angle-grinders`, `breakers-panels`,
  `hollow-blocks`, `pipe-fittings`, `cutting-discs`).
- **122 approved products, 216 variants.** Real brands sold in PH stores (Makita, Bosch, DeWalt, Stanley, Tolsen, Ingco, Royu, Phelps Dodge,
  Panasonic, Omni, Firefly, Neltex, Republic, APO, Boysen, Vulcaseal, Pioneer, Rugby, Motul, Petron, Motolite).
- **104 variants have no barcode**: loose nails per kg, PVC fittings, coco lumber, CHB, sand per cu.m. and repacked sacks, rebar.
  These are the items AI photo intake is meant for.
- **Variant traps** that must never be merged: 720W vs 840W 4" grinder, 15/20/30/40/60A breakers, 2.0/3.5/5.5 mm² THHN,
  per-meter vs 75m roll, 100- vs 500-pack Tek screws, per-kg vs 25kg box nails, Grade 33 vs Grade 40 rebar,
  GI sheet gauge and length, safety shoe sizes, 0.8L vs 1L oil.
- **Search synonyms** in Tagalog and Cebuano (`pako`/`lansang`, `yero`, `balas`/`buhangin`, `graba`, `kahoy`, `martilyo`, `kartilya`, `sundang` ...).
- **PH specifications** in `ph_specs` (220V 60Hz, plug type, PS/ICC mark, PNS standards for cement and rebar).
- **31 typed attribute definitions** (number/text, variant axis yes/no). Some are category-specific: `gauge_mm2`, `grade`, `sheet_gauge`, `teeth`, `shoe_size`.
- Indicative SRP per variant. Each tenant's own price and cost are derived from it.
- Barcodes: the existing codes are kept. New ones are **synthetic** EAN-13 codes in a `48099…` demo range, not manufacturer data.

### 2. Demo data set (`api/database/seeders/DemoSeeder.php`)
| Tenant | Plan | Store(s) | Role in the demo |
|---|---|---|---|
| Dela Cruz Hardware (`dela-cruz-hardware`) | Multi-branch | Mabolo (Cebu City), Mandaue | Hero tenant: does **not** stock Makita HP1630, so it can be added live through AI intake |
| Mabini Builders Supply (`mabini-builders`) | Pro | Subangdaku yard, Mandaue | Bulk construction supply, delivery, min. order 50 CHB |
| Visayas Tools Depot (`visayas-tools`) | Pro | Colon St, Cebu City | Power tools; has the last HP1630 unit |
| Mactan Electrical & Plumbing (`mactan-electrical`) | Starter | Pusok, Lapu-Lapu | POS only: listings stay off the marketplace |
| Talisay Home & Garden (`talisay-home`) | Pro | Tabunok, Talisay | Paint, garden, safety |
| Kamuning Hardware (`kamuning-hardware`) | Pro | Quezon City | Far away: sorts last for a Cebu buyer |
| Old Pier Hardware (`old-pier-hardware`) | Starter | Cebu City | **Suspended**: its published listings must never show |

- Every role is present. There is also a **revoked** former employee (`former.staff.a@`) and one person who works for two tenants
  (`bookkeeper@`), for account-switch tests. 5 customer personas: homeowner, foreman, electrician, Manila buyer.
- **30 days of history, created through the real services** under a moved clock (`Carbon::setTestNow`):
  daily POS sessions and about 500 sales during Philippine business hours (08:00-17:30, `Asia/Manila`), cash/GCash/Maya/bank payments,
  manager discounts, weekly purchase receipts, a branch-to-branch transfer, damage, loss, a correction and a void.
  About 30% of each store's assortment never sells (dead stock), and about 34 items sit at or below their reorder point.
- **Orders in every state**: reserved, confirmed, preparing, ready for pickup, fulfilled, cancelled by customer, cancelled by vendor, expired.
  There is also a delivery order with an address and a draft cart that holds no stock.
- **AI examples (all labelled mock)**: two items waiting for review (a variant, a low-confidence one), a confirmed unbarcoded intake
  (`selected_variant`), a rejected blurry photo, a tenant-submitted **catalog candidate** (Kingfoam, visible only to its tenant),
  a conflicting barcode, a POS photo search and a customer photo search.
- Tenant plan and limits from the business plan are stored in `tenants.settings` (`plan`, `plan_limits`). They are **data only** and not enforced yet.
- All accounts use the password `password`. Never run this seed against production.

### 3. Small code changes
- `CatalogService::searchText` also indexes the parent category name, so "power tools" finds products in subcategories.
- Weights use `weight_kg` (cement used to be stored as `weight_oz: 1411`).
- `World::catalog()` in tests seeds only the 31 original "core" products (`CatalogSeeder::CORE`), so unit tests stay fast and their matcher data stays the same.
- `DemoScenarioTest` now uses the Cebu stores (`DCH-MB`, buyer in Mabolo) and also checks the distance order and that the suspended tenant is hidden.
- New `tests/Feature/DemoSeedIntegrityTest.php`.

## What was verified
- `php artisan migrate:fresh --seed` on PostgreSQL 17 (Docker): about 12 s, no errors.
- **Ledger = snapshots** for every item and location (0 mismatches). Reserved units equal the open reservation lines.
- `php artisan test`: **141 passed** (871 assertions), about 57 s. Most of that time is the two tests that load the full demo seed.
- Pint is clean on the changed files. Pint reports 43 older files on `master` too; this branch does not touch them.

## What to check (reviewer)
1. Run `docker compose up -d postgres`, then `scripts/dphp php artisan migrate:fresh --seed`, then `scripts/dphp php artisan test`.
2. Public search: `GET /api/v1/public/search?q=circuit breaker` and `?q=20A breaker` (amperage filter), `?q=yero`, `?q=lansang`.
3. Store comparison for a Cebu buyer: `GET /api/v1/public/products/{id}/listings?lat=10.32&lng=123.91`. Dela Cruz should come first
   and Kamuning last. Old Pier and Mactan should be absent. There should be no `cost_cents` field.
4. Sign in as `owner.a@hardwhere.test` / `password` (tenant `dela-cruz-hardware`). Check the inventory, low-stock items, ledger, sales history, the voided sale and the AI review queue.
5. Sign in as `owner.c@hardwhere.test`. The Kingfoam candidate should be visible there and nowhere else.
6. Decide whether the plan limits in `tenants.settings` should be enforced (Starter: 300 SKUs, 50 AI intakes per month, no marketplace).
7. Brand names and SRPs are realistic demo values. Check them before a public demo if exact prices matter.

## Known limitations
- "Price on request" and "stock without price" (business plan risk mitigations) are not modelled yet. They need a listing flag and changes to the API resource.
- Current orders hold stock for the tenant's reservation TTL (2-24 h). After that the expiry job releases them, so reseed before a live demo.
- The mobile apps, Android/iOS builds and live Gemma inference are outside this change.
