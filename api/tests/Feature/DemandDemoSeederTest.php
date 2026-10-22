<?php

use App\Mail\DemandAlertMail;
use App\Mail\ProspectDemandMail;
use App\Models\DemandAlert;
use Database\Seeders\DemandDemoSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Mail;

it('seeds the local demo: alerts for member and non-member vendors, nothing for opted-out prospects', function () {
    Mail::fake();
    config(['hardwhere.sync.xmin_horizon' => false, 'hardwhere.demand.min_searchers' => 5]);
    $this->seed([DemoSeeder::class, DemandDemoSeeder::class]);

    expect(DemandAlert::query()->orderBy('id')->pluck('subject_label')->all())->toHaveCount(3);
    Mail::assertSent(DemandAlertMail::class, fn (DemandAlertMail $m) => $m->hasTo('owner.b@hardwhere.test'));
    Mail::assertSent(ProspectDemandMail::class, fn (ProspectDemandMail $m) => $m->hasTo('orders@bayanihan-supply.test'));
    Mail::assertNotSent(ProspectDemandMail::class, fn (ProspectDemandMail $m) => $m->hasTo('owner@oldtown-hardware.test'));
});
