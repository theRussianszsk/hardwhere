<?php

namespace App\Console\Commands;

use App\Services\DemandMonitor;
use App\Services\MarketplaceSearch;
use Illuminate\Console\Command;

/** Local demo helper: N fake shoppers run the same marketplace search, exactly as GET /public/search would record it. */
class SimulateDemand extends Command
{
    protected $signature = 'demand:simulate {query : What the shoppers search for} {--searchers=6 : Distinct shoppers} {--category= : Category slug filter} {--detect : Run demand:detect afterwards}';

    protected $description = 'Simulate many shoppers searching for the same thing (local demo only)';

    public function handle(MarketplaceSearch $search, DemandMonitor $demand): int
    {
        if (app()->isProduction()) {
            $this->error('demand:simulate is a local demo tool and refuses to run in production.');

            return self::FAILURE;
        }
        $filters = ['q' => $this->argument('query'), 'category' => $this->option('category')];
        $n = max(1, (int) $this->option('searchers'));
        $run = bin2hex(random_bytes(4));
        for ($i = 1; $i <= $n; $i++) {
            $results = $search->products($filters + ['per_page' => 20]);
            $demand->recordTextSearch(hash('sha256', "simulated:$run:$i"), $filters, $results);
        }
        $this->info(sprintf('%d shoppers searched for "%s" (%d results each).', $n, $filters['q'], $results->total()));

        return $this->option('detect') ? $this->call('demand:detect') : self::SUCCESS;
    }
}
