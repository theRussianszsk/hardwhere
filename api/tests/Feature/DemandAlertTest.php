<?php

use App\Enums\TenantRole;
use App\Mail\DemandAlertMail;
use App\Mail\ProspectDemandMail;
use App\Models\DemandAlert;
use App\Models\DemandAlertDelivery;
use App\Models\DemandSignal;
use App\Models\ProspectVendor;
use App\Services\DemandMonitor;
use Illuminate\Support\Facades\Mail;
use Tests\Support\World;

beforeEach(function () {
    Mail::fake();
    config(['hardwhere.demand.min_searchers' => 3]);
    $this->refs = World::catalog();
    $this->w = World::tenant('alpha');
    World::stocked($this->w, $this->refs['STH16'], 10, 35000);
    World::member($this->w['tenant'], TenantRole::InventoryManager, 'inventory@alpha.test');
    World::member($this->w['tenant'], TenantRole::Cashier, 'cashier@alpha.test', $this->w['store']);
    $this->search = fn (string $q, string $ip, array $extra = []) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->getJson('/api/v1/public/search?'.http_build_query(['q' => $q] + $extra))->assertOk();
    $this->prospect = fn (string $email, array $cats, array $attrs = []) => ProspectVendor::create($attrs + ['name' => ucfirst(strtok($email, '@')).' Store', 'email' => $email, 'categories' => $cats]);
});

it('records one signal per search, without browsing or later pages', function () {
    ($this->search)('Claw  Hammer', '10.0.0.1');
    ($this->search)('', '10.0.0.1');
    ($this->search)('hammer claw', '10.0.0.2', ['page' => 2]);

    $signal = DemandSignal::query()->sole();
    expect($signal->source)->toBe('text_search')->and($signal->category_id)->not->toBeNull()
        ->and($signal->searcher_key)->toHaveLength(64)->not->toContain('10.0.0.1');
});

it('groups word order and alerts once enough distinct shoppers searched', function () {
    ($this->search)('tile adhesive', '10.0.0.1');
    ($this->search)('tile adhesive', '10.0.0.1'); // same shopper twice counts once
    ($this->search)('Adhesive, tile', '10.0.0.2');
    expect(app(DemandMonitor::class)->detect())->toBeEmpty();

    ($this->search)('tile adhesive', '10.0.0.3');
    $alert = app(DemandMonitor::class)->detect()->sole();
    expect($alert->subject_key)->toBe('q:adhesive tile')->and($alert->searcher_count)->toBe(3)->and($alert->search_count)->toBe(4)->and($alert->unserved)->toBeTrue();
});

it('emails member owners and inventory managers, and only matching, subscribed, non-member prospects', function () {
    ($this->prospect)('tools@prospect.test', ['hand-tools']);
    ($this->prospect)('paint@prospect.test', ['paint-coatings']);
    ($this->prospect)('optout@prospect.test', ['hand-tools'], ['unsubscribed_at' => now()]);
    ($this->prospect)('owner@alpha.test', ['hand-tools']); // already a member
    foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $ip) {
        ($this->search)('claw hammer', $ip);
    }

    $alert = app(DemandMonitor::class)->detect()->sole();

    expect($alert->unserved)->toBeFalse();
    Mail::assertSent(DemandAlertMail::class, 2);
    Mail::assertSent(DemandAlertMail::class, fn (DemandAlertMail $m) => $m->hasTo('owner@alpha.test') && $m->tenantName === 'Alpha Hardware' && $m->alreadyListed);
    Mail::assertSent(DemandAlertMail::class, fn (DemandAlertMail $m) => $m->hasTo('inventory@alpha.test'));
    Mail::assertSent(ProspectDemandMail::class, 1);
    Mail::assertSent(ProspectDemandMail::class, fn (ProspectDemandMail $m) => $m->hasTo('tools@prospect.test'));
    expect(DemandAlertDelivery::query()->where('demand_alert_id', $alert->id)->pluck('audience')->countBy()->all())->toBe(['member' => 2, 'prospect' => 1]);
});

it('does not re-alert the same subject during the cooldown', function () {
    foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $ip) {
        ($this->search)('solar panel', $ip);
    }
    expect(app(DemandMonitor::class)->detect())->toHaveCount(1);
    ($this->search)('solar panel', '10.0.0.4');
    expect(app(DemandMonitor::class)->detect())->toBeEmpty()->and(DemandAlert::query()->count())->toBe(1);

    $this->travel(config('hardwhere.demand.cooldown_hours') + 1)->hours();
    foreach (['10.0.0.5', '10.0.0.6', '10.0.0.7'] as $ip) {
        ($this->search)('solar panel', $ip);
    }
    expect(app(DemandMonitor::class)->detect())->toHaveCount(1);
});

it('renders both emails', function () {
    ($this->prospect)('tools@prospect.test', ['hand-tools'], ['contact_name' => 'Rowena']);
    foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $ip) {
        ($this->search)('claw hammer', $ip);
    }
    app(DemandMonitor::class)->detect();

    Mail::assertSent(DemandAlertMail::class, fn (DemandAlertMail $m) => str_contains($m->render(), '3 different shoppers') && str_contains($m->render(), 'already lists this'));
    Mail::assertSent(ProspectDemandMail::class, fn (ProspectDemandMail $m) => str_contains($m->render(), 'Hi Rowena') && str_contains($m->render(), 'unsubscribe'));
});
