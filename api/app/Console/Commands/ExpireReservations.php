<?php

namespace App\Console\Commands;

use App\Services\ReservationService;
use Illuminate\Console\Command;

class ExpireReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Expire overdue marketplace reservations and release their held stock';

    public function handle(ReservationService $reservations): int
    {
        $this->info('Expired '.$reservations->expireDue().' reservation(s).');

        return self::SUCCESS;
    }
}
