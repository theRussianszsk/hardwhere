<?php

namespace Database\Seeders;

use App\Models\ProspectVendor;
use App\Services\DemandMonitor;
use App\Services\MarketplaceSearch;
use Illuminate\Database\Seeder;

/**
 * Local demand-alert demo. Seeds hardware stores that are NOT on the platform yet, has groups of simulated shoppers run real marketplace
 * searches, then runs detection — so the demand emails to member vendors (registered users) and prospect vendors (non-members) land in
 * the local Mailpit inbox (http://localhost:8025). Needs DemoSeeder's tenants and catalog. Every address is @*.test; never run in production.
 */
class DemandDemoSeeder extends Seeder
{
    public function run(MarketplaceSearch $search, DemandMonitor $demand): void
    {
        foreach ([
            ['Sampaguita Power Tools Center', 'sales@sampaguita-tools.test', 'Rowena Cruz', 'Marikina', ['power-tools', 'accessories-parts'], ['Makita', 'Bosch', 'DeWalt']],
            ['Pasig Tools & Hardware', 'hello@pasig-tools.test', 'Arnel Tan', 'Pasig', ['hand-tools', 'power-tools', 'measuring-tools'], ['Stanley', 'Makita']],
            ['Bayanihan Construction Supply', 'orders@bayanihan-supply.test', 'Jun Ramos', 'Caloocan', ['construction-materials', 'building-supplies', 'plumbing'], ['Republic', 'Neltex']],
            ['Liwanag Electrical Trading', 'info@liwanag-electrical.test', 'Grace Lim', 'Manila', ['electrical'], ['Royu', 'Philips', 'Phelps Dodge']],
            ['Kulay Paint Center', 'kulay@kulay-paint.test', 'Dolores Garcia', 'Makati', ['paint-coatings'], ['Boysen', 'Davies']],
            ['Bicol Agri & Solar Supply', 'contact@bicol-solar.test', null, 'Legazpi', ['electrical', 'outdoor-garden'], []],
        ] as [$name, $email, $contact, $city, $categories, $brands]) {
            ProspectVendor::updateOrCreate(['email' => $email], ['name' => $name, 'contact_name' => $contact, 'city' => $city, 'categories' => $categories, 'brands' => $brands]);
        }
        // Opted out earlier: must never be emailed.
        ProspectVendor::updateOrCreate(['email' => 'owner@oldtown-hardware.test'], ['name' => 'Old Town Hardware', 'city' => 'Quezon City', 'categories' => ['power-tools', 'hand-tools'], 'brands' => ['Makita'], 'unsubscribed_at' => now()->subMonth()]);

        $min = (int) config('hardwhere.demand.min_searchers');
        foreach ([
            // listed product: stores that carry it are told to check stock, power-tool/Makita prospects are invited
            ['q' => 'makita hp1630', 'shoppers' => $min + 3],
            // nobody lists it, category known from the filter: only construction-supply prospects are invited
            ['q' => 'tile adhesive', 'category' => 'construction-materials', 'shoppers' => $min + 1],
            // nobody lists it and there is no category or brand hint: every member and every prospect hears about it
            ['q' => 'solar panel 100w', 'shoppers' => $min],
            // below the threshold: no alert
            ['q' => 'bosch angle grinder', 'shoppers' => max(1, $min - 2)],
        ] as $i => $s) {
            $filters = ['q' => $s['q'], 'category' => $s['category'] ?? null];
            for ($n = 1; $n <= $s['shoppers']; $n++) {
                $demand->recordTextSearch(hash('sha256', "demo-shopper:$i:$n"), $filters, $search->products($filters + ['per_page' => 20]));
            }
        }

        foreach ($demand->detect()->load('deliveries') as $a) {
            $this->command?->info(sprintf('  demand alert "%s": %d member + %d prospect emails', $a->subject_label,
                $a->deliveries->where('audience', 'member')->count(), $a->deliveries->where('audience', 'prospect')->count()));
        }
    }
}
