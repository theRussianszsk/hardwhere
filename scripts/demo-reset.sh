#!/usr/bin/env bash
# Put the demo back to its starting state: fresh schema + deterministic demo data (Postgres in docker). Safe to run any time.
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose up -d postgres api >/dev/null
docker compose run --rm -T api php artisan migrate:fresh --seed --force
echo
echo "Demo data ready. API: http://localhost:8787  (password for every demo account: password)"
echo "  vendor   owner.a@hardwhere.test | manager.a | inventory.a | cashier.a | market.a | staff.a   (business: dela-cruz-hardware)"
echo "  vendor   owner.b@hardwhere.test (mabini-builders)   owner.c@hardwhere.test (visayas-tools)"
echo "  customer ana.customer@hardwhere.test | ben.customer@hardwhere.test"
echo "  Tenant A does NOT stock Makita HP1630 yet: photograph it with the vendor app (demo hint 'makita-hp1630')."
