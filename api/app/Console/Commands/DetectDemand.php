<?php

namespace App\Console\Commands;

use App\Services\DemandMonitor;
use Illuminate\Console\Command;

class DetectDemand extends Command
{
    protected $signature = 'demand:detect';

    protected $description = 'Raise demand alerts for subjects many shoppers searched for, and email member and prospect vendors';

    public function handle(DemandMonitor $demand): int
    {
        $alerts = $demand->detect()->load('deliveries');
        foreach ($alerts as $a) {
            $this->line(sprintf('Alert #%d: "%s" — %d searchers%s, %d member / %d prospect emails', $a->id, $a->subject_label, $a->searcher_count,
                $a->unserved ? ' (unserved)' : '', $a->deliveries->where('audience', 'member')->count(), $a->deliveries->where('audience', 'prospect')->count()));
        }
        $this->info($alerts->isEmpty() ? 'No new demand alerts.' : $alerts->count().' demand alert(s) raised.');

        return self::SUCCESS;
    }
}
