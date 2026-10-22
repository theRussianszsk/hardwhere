# Business Plan — inventOS + POSsible + Hardware Marketplace

*FUTURE FORGE: The ALMA Hackathon · Cebu City · 22 October 2026*

> Figures marked **(assumption)** are team estimates to be validated in the pilot. Market figures are cited in **Sources** at the end.

---

## 1. One-liner

**Photograph a product instead of scanning a barcode.** Neighborhood hardware stores digitize their shelves with AI in one afternoon, record sales with stock that is always current, and become discoverable to nearby customers who need an exact part today.

## 2. Problem

**For hardware store owners**
- Most neighborhood hardware stock has **no barcode**: loose nails, PVC fittings, bolts, cut lumber, repacked items. Barcode-based POS systems do not fit how these stores actually work.
- Inventory lives in notebooks, Excel, or the owner's memory. Owners do not know what is low, what is dead stock, or what was sold yesterday.
- Existing affordable POS products in the Philippines are built mainly for food & beverage, not for thousands of spec-sensitive hardware SKUs.

**For buyers (homeowners, contractors, technicians)**
- You need a specific item now: a 4" angle grinder, an M10 bolt, a 20A breaker. You do not know which nearby store has it, at what price, or whether it is in stock.
- Today the answer is calling stores, messaging on Facebook, or driving around. Online marketplaces deliver in days, which is too slow for a job site.

## 3. Solution

One platform, two apps, one shared backend.

| | Vendor app (inventOS + POSsible) | Customer app (Hardware Marketplace) |
|---|---|---|
| Core job | Know and sell what is on the shelf | Find the exact item nearby, now |
| Key feature | **AI photo intake**: photo → brand, model, specs, pack size → confirm → in inventory | Text or **photo search** across a shared catalog |
| Daily use | Record sales; stock updates instantly; low-stock alerts | Compare stores by price, stock, distance; directions; short reservation hold |
| Data | Private per store: cost, margin, sales, staff | Public only: selling price, stock status, store address |

**Why the two sides reinforce each other:** the store records sales for its own benefit (stock control), and that same data keeps the marketplace accurate for free. Competing local-stock marketplaces fail because stock data goes stale; ours stays fresh as a by-product of daily operations.

**Scope boundary:** POSsible records sales and decrements stock. It is **not** a payment processor and **not** a BIR-accredited receipting system in this version.

## 4. Target customers

### Primary (who pays): independent neighborhood hardware stores
- 1–3 branches, 1–10 staff, 500–5,000 SKUs **(assumption)**, many unbarcoded.
- Owner-operated; uses a smartphone daily; already sells via Facebook/Messenger.
- **Beachhead:** Cebu City and Mandaue, then Metro Manila.

### Secondary (who uses for free, creates demand): buyers
- Contractors and *foremen* buying daily for job sites.
- Electricians, plumbers, technicians who need exact specs.
- Homeowners doing repairs.

### Tertiary (later revenue): brands and distributors
- Power-tool, paint, and fastener brands and their distributors want to know **where their products are stocked and at what price**. Today they have no sell-through visibility into small independent stores.

## 5. Market

- The Philippines had about 1.24 million registered business establishments in 2023; ~99.6% are MSMEs, and **wholesale and retail trade is the largest sector with ~604,000 MSMEs** (PSA 2023 List of Establishments via DTI).
- The Philippine DIY & hardware store market was estimated at **about US$21 billion in revenue in 2024** (Statista).
- Nexdigm forecasts the Philippine hardware and home-improvement retail market to grow at **8.2% CAGR in 2025–2031**, driven by housing, renovation, and infrastructure.

**Bottom-up target market (assumptions to validate):**

| Layer | Estimate | Basis |
|---|---|---|
| Independent hardware / construction-supply stores nationwide (TAM) | ~25,000 stores | assumption, to verify via DTI/PSA FOI data |
| Stores in Metro Cebu + Metro Manila with smartphones (SAM) | ~6,000 stores | assumption |
| Year-2 realistic reach (SOM) | 1,000 paying stores | ~15% of SAM |
| Revenue at SOM | 1,000 × ₱799 × 12 ≈ **₱9.6M/year (~US$165k)** | pricing below |

## 6. Competition

| Alternative | Strength | Weakness for hardware stores |
|---|---|---|
| Notebook / Excel | Free, familiar | No real-time stock, no visibility, error-prone |
| Loyverse | Free basic POS; advanced inventory add-on at US$25/month per store | Barcode/SKU-centric, no AI intake, no buyer marketplace |
| UTAK, StoreHub, other local POS | Local support, BIR compliance | Built mainly for F&B; roughly ₱1,500–2,300/month; no hardware catalog |
| Wilcon, AllHome, ACE (big-box) | Selection, brand trust | Far away, often more expensive, do not help small stores |
| Shopee / Lazada | Huge reach | Delivery in days; useless for "need it today" |

**Our differentiation**
1. **AI photo intake for unbarcoded hardware**: onboarding takes hours, not weeks.
2. **Shared canonical hardware catalog**: specs are structured (voltage, size, pack quantity), so an NVMe is never confused with a SATA drive and a 720W grinder is not merged with an 840W one.
3. **Local discovery built into the POS**: no other affordable POS turns a store's stock into nearby demand.

## 7. Business model and pricing

### Vendor subscription (per store)

| Plan | Price | Includes |
|---|---|---|
| **Starter** | Free | POS sale recording, inventory up to 300 SKUs, 50 AI photo intakes/month, manual entry |
| **Pro** | **₱799/month** (₱7,990/year) | Unlimited SKUs, 1,000 AI intakes/month, low-stock alerts, stock ledger, marketplace listing |
| **Multi-branch** | ₱1,999/month | Up to 3 branches, staff roles, branch stock view |

Pro is priced **below** Loyverse's advanced-inventory add-on (US$25 ≈ ₱1,450) and below typical local POS subscriptions, and adds AI intake and buyer traffic.

### Other revenue (Phase 2+)
- **Done-for-you onboarding:** ₱2,500 one-time; our team photographs and catalogs the shelf in one visit.
- **Featured listing** in marketplace search: ₱300–500/month per store **(assumption)**.
- **Brand/distributor insights:** anonymized, aggregated stock-and-price coverage reports, sold to brands. Only with store opt-in and in compliance with the Data Privacy Act.

The **customer app is free**.

### Unit economics (Pro store, per month, assumptions)

| Item | Amount |
|---|---|
| Revenue | ₱799 |
| AI inference (≈600 photos/month on Gemma 4, with 3× safety margin) | ~₱50 |
| Hosting, database, storage share | ~₱60 |
| Support share | ~₱80 |
| **Gross margin** | **~75%** |

The heaviest AI usage is in the **first month** (initial shelf digitization). After that, stores mostly add new arrivals, so AI cost per store falls sharply.

## 8. Go-to-market

**Phase 1 — Pilot (months 0–3): Cebu**
- 10 pilot stores in one area so marketplace density is visible from day one.
- **Walk in, digitize the shelf for free in one afternoon** with the AI intake. The owner sees the full catalog before paying anything.
- Success metrics: 70% of pilot stores still recording sales after 30 days; ≥5 marketplace reservations per store per month.

**Phase 2 — Density (months 3–12)**
- Expand street by street, not city-wide. The marketplace needs ~20 stores within 5 km before buyers find it useful **(assumption)**.
- Channels: contractor and tradesperson Facebook groups, foremen word of mouth, referral credit (one free month per referred store), partnership with local hardware associations.
- Convert free → Pro when a store passes 300 SKUs or wants marketplace listing.

**Operator-champion channel — the "1C model" (from month 3)**

In 1990s Russia, the accounting software 1C won small businesses not by selling to owners but through **accountants**: they learned it in courses, earned certification, and refused to work without it at their next job. Owners paid because arguing with the bookkeeper cost more than the license. The skill became a profession, and the job market sold the product.

Our equivalent champions are the people who do the daily stock and bookkeeping work, not the owner:
- **External bookkeepers** who serve many small businesses at once; one bookkeeper can bring 10–30 stores **(assumption)**.
- **In-store stock keepers**: the senior clerk or family member who counts stock.
- **Accounting and retail students** who enter the job market already trained.

What makes them demand us (bookkeeper hook, Phase 2 features built on the existing stock ledger):
- Month-end **inventory valuation** report at cost.
- **Cost of goods sold** for any period.
- **Excel/CSV export** in the shape bookkeepers use to prepare tax filings.
- **One login, many client stores** for external bookkeepers.

Ecosystem:
- Free **"inventOS Certified Bookkeeper"** course and résumé badge.
- **Training partnerships** with schools and education centers (e.g. TESDA-accredited providers, colleges, BYU-Pathway Global Education Center) with free student accounts.
- **Partner revenue share:** bookkeepers receive 20% of the subscription of every store they bring and keep active **(assumption)**.
- **Portable personal account:** a trained person's login moves with them between employers; store data access is revoked on exit, including on-device cache, so owners do not fear data leaving with ex-staff.

Contractors and foremen add demand-side pressure on top: "you are not in the app, so I'm buying next door."

**AI growth agents — research automated, outreach human-approved (from month 3)**

| Agent | What it does | Output |
|---|---|---|
| **Lead researcher** | Finds hardware stores per area through permitted sources (Google Places API, public business pages, DTI listings) and enriches them: category, hours, reviews, online presence | Street-by-street prioritized prospect list in the CRM |
| **Supplier & catalog scout** | Finds distributors, official brand catalogs and spec sheets | New canonical catalog entries (human-reviewed); better AI intake accuracy; distributor partnership leads |
| **Outreach writer** | Drafts a personalized message per store using its public data and nearby buyer demand | Draft in the funnel; a field rep or partner bookkeeper sends it or visits in person |
| **Programmatic SEO** | Publishes "where to buy [product] in [city]" pages generated from **real live stock** | Organic buyer traffic; stores see demand and ask to join |
| **Follow-up** | Replies to inbound leads and follows up only with people who gave their contact | Automated nurturing within consent |

Guardrails:
- **No fully automated cold messaging.** Agents research and draft; a human approves the first contact.
- Only permitted sources and official APIs; no scraping against platform terms, no mass automated Messenger or SMS.
- Personal data handled under the Data Privacy Act (RA 10173): business contact data only, clear opt-out, no data resale.

**Phase 3 — Scale (year 2)**
- Metro Manila; distributor partnerships (distributor sales reps onboard their own retailer network).
- Brand insights product.

## 9. Key risks and mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| **Owners do not want to change habits** | No adoption | Free tier + done-for-you onboarding; value visible on day one (full catalog, low-stock list) |
| **Sales recorded outside the app** | Stock drifts, marketplace lies | Every listing shows "stock updated X ago"; quick recount flow; we never claim guaranteed stock |
| **AI misidentifies an item** | Wrong catalog entries | Human confirms every intake; confidence and warnings shown; variants never auto-merged |
| **BIR compliance**: stores must issue official receipts | Owners see us as non-compliant POS | Position as inventory + sales record alongside their existing receipting; BIR accreditation is a Phase 3 decision |
| **Stores do not want public prices** | Thin marketplace | Per-listing visibility control; "price on request" option; show stock without price |
| **Chicken-and-egg marketplace** | No buyers | Vendor app is valuable on its own; marketplace switches on per area only after density |
| **Free competitors (Loyverse)** | Price pressure | Compete on hardware-specific AI intake and buyer traffic, not price |
| **Data privacy** (RA 10173) | Legal exposure | Cost, sales, and staff data never leave the tenant; customer location used only for search |
| **Unstable connectivity** | App unusable | Offline browsing and drafts; only final stock changes require the server |
| **AI provider cost or availability** | Margin or outage | Provider abstraction; deterministic fallback; manual entry always works |

## 10. Roadmap

| When | Milestone |
|---|---|
| Today | Working demo: AI intake → inventory → marketplace search → reservation → POS sale with oversell protection |
| Month 1 | 10 pilot stores in Cebu; feedback loop on intake accuracy |
| Month 3 | 30 stores; first paying Pro customers; marketplace live in one area |
| Month 6 | 100 stores; referral program; done-for-you onboarding service |
| Month 12 | 300 stores (~150 paying ≈ ₱120k MRR); Metro Manila entry |
| Year 2 | 1,000 paying stores; distributor channel; brand insights |

## 11. Budget and funding need

All figures are **team estimates (assumptions)** in Philippine pesos at Cebu cost levels. Founders are not paid in this budget. **Hackathon prototype cost: ₱0** (free event AI access and free tiers).

### One-time costs

| Item | ₱ |
|---|---|
| Business registration (DTI/SEC, BIR, mayor's permit) | 30,000 |
| Privacy policy, terms of service, NPC registration (Data Privacy Act) | 25,000 |
| App store accounts (Google Play one-time, Apple yearly) | 7,000 |
| Test devices (2 low-end Android phones) | 15,000 |
| Certified Bookkeeper course content | 20,000 |
| **Total one-time** | **97,000** |

### Monthly fixed costs

| Item | Months 1–6 | Months 7–12 |
|---|---|---|
| 2 part-time developers | 80,000 | 80,000 |
| Field onboarding reps incl. transport | 24,000 (1 rep) | 48,000 (2 reps) |
| Hosting, Postgres database, photo storage | 5,000 | 8,000 |
| AI growth agents + maps/places API | 8,000 | 12,000 |
| Tools (domain, email, CRM) | 3,000 | 3,000 |
| Marketing (print, Facebook ads, events) | 10,000 | 20,000 |
| **Total per month** | **130,000** | **171,000** |

### AI compute: cost per product photo

Assumed request: one product photo + ~800 tokens of instructions and catalog candidates in, ~400 tokens of structured JSON out. Public API list prices, October 2026; exchange rate ₱58/US$.

| Model | Price in / out per 1M tokens | Image tokens | **Per photo** | Per 1,000 photos |
|---|---|---|---|---|
| Gemma 4 E4B (edge) | $0.02 / $0.04 | up to 1,120 | **$0.00005** | $0.05 (₱3) |
| **Gemma 4 26B / 12B (primary)** | $0.13 / $0.40 | 1,120 (high detail for labels) | **$0.0004** | **$0.41 (₱24)** |
| Gemini 3.1 Flash-Lite | $0.25 / $1.50 | ~560 | $0.0009 | $0.94 (₱55) |
| Gemini 3 Flash | $0.50 / $3.00 | ~560 | $0.0019 | $1.90 (₱110) |
| Claude Haiku 4.5 (event model) | $1.00 / $5.00 | ~1,600 | $0.0044 | $4.40 (₱255) |

Gemma 4 lets us choose an image token budget of 70–1,120 per image, so label-reading intake uses 1,120 and simple re-identification can use 280 at a quarter of the cost. Output tokens dominate the cost on every model, so the JSON schema is kept short.

**Planning figures (Gemma 4 primary, ~3× margin for retries, customer photo searches and fallback to a larger model):**

| Item | Estimate |
|---|---|
| Initial shelf digitization, per new store (~2,000 photos) | ~₱150 one-off |
| Ongoing intake and photo search, per active store | ~₱50/month |
| Worst case if everything ran on Claude Haiku 4.5 | ~₱510 onboarding, ~₱150/month |

### 12-month projection

| | Months 1–6 | Months 7–12 | Year 1 total |
|---|---|---|---|
| Fixed costs | 780,000 | 1,026,000 | 1,806,000 |
| One-time costs | 97,000 | – | 97,000 |
| Variable AI (100 stores by M6, 300 by M12) | 30,000 | 90,000 | 120,000 |
| Contingency 10% | 91,000 | 112,000 | 203,000 |
| **Total spend** | **≈1.00M** | **≈1.23M** | **≈₱2.23M** |
| Revenue (paying stores grow to ~40 by M6, ~150 by M12) | ~80,000 | ~455,000 | ~₱535,000 |
| **Net burn** | **≈0.92M** | **≈0.77M** | **≈₱1.69M** |

AI is about 5% of total spend. Even in the Claude Haiku worst case it adds only ~₱250,000 a year; **people, not compute, are the main cost.**

### Unit economics at scale

- **Customer acquisition cost:** one field rep onboards ~20 stores/month → ~₱1,200 per store + ₱150 digitization AI ≈ **₱1,350 per store**.
- **Contribution per Pro store:** ₱799 − ~₱190 (AI ₱50, hosting ₱60, support ₱80) ≈ **₱610/month** → **CAC payback ≈ 2–3 months**.
- **Break-even:** ~₱180,000 monthly costs ÷ ₱610 ≈ **300 paying stores**, expected around **months 14–16**.

### The ask

- **Pre-seed ₱2M (~US$34k)** for 12 months of runway: ~₱1.69M net burn plus buffer.
- **10 pilot stores** in Cebu willing to try it for a month.
- **Introductions** to a hardware association, distributor, or training center in Cebu.

## 12. Team

*(Add names, roles, and one-line backgrounds.)*

---

## Sources

- DTI — 2023 Philippine MSME Statistics (PSA List of Establishments): https://dti.gov.ph/businesses/msmes/msmeresources/msme-statistics
- Statista — DIY & Hardware Store, Philippines: https://www.statista.com/outlook/cmo/diy-hardware-store/philippines
- Nexdigm — Philippines hardware stores retail industry: https://www.nexdigm.com/market-research/insights/blog/philippines-hardware-stores-retail-industry/
- Loyverse pricing: https://loyverse.com/en-us/pricing
- klikit — POS systems in the Philippines 2026 (UTAK pricing): https://klikit.io/uncategorized-en/best-pos-systems-in-the-philippines-for-2026-features-pricing-best-use-cases
- HashMicro — HashMicro vs UTAK (UTAK pricing): https://www.hashmicro.com/ph/blog/hashmicro-vs-utak/
- Gemini API pricing (Gemma 4, Gemini models): https://ai.google.dev/gemini-api/docs/pricing
- Gemma 4 vision image token budgets: https://ai.google.dev/gemma/docs/capabilities/vision
- Gemma 4 pricing trackers: https://anotherwrapper.com/llm-pricing/gemma-4-26b-a4b-it · https://anotherwrapper.com/llm-pricing/gemma-4-e4b-it
- Gemini API pricing overview: https://benchlm.ai/google/api-pricing
- Claude Haiku 4.5 pricing (Amazon Bedrock listing): https://custom.typingmind.com/tools/estimate-llm-usage-costs/amazon-bedrock/us-anthropic-claude-haiku-4-5-20251001-v1-0
