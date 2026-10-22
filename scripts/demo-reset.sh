#!/usr/bin/env bash
# Put the demo back to its starting state: fresh schema + deterministic demo data (Postgres in docker). Safe to run any time.
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose up -d postgres api >/dev/null
docker compose run --rm -T api php artisan migrate:fresh --seed --force
echo
echo "Demo data ready. API: http://localhost:8787  (password for every demo account: password)"
echo "  vendor   owner.a@hardwhere.test | manager.a | inventory.a | cashier.a | cashier2.a | market.a | staff.a   (business: dela-cruz-hardware, Cebu)"
echo "  vendor   owner.b (mabini-builders)  owner.c (visayas-tools)  owner.d (mactan-electrical, Starter)  owner.e (talisay-home)  owner.f (kamuning-hardware, Manila)"
echo "  customer ana.customer | ben.customer | jun.foreman | rico.electrician | liza.customer  (@hardwhere.test)"
echo "  Tenant A does NOT stock Makita HP1630 yet: photograph it with the vendor app (demo hint 'makita-hp1630')."
