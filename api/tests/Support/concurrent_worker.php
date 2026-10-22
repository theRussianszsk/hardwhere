<?php

// Child process used by tests/Concurrency: one fresh PHP process, one fresh DB connection, one command. Prints a JSON outcome.
use App\Enums\TenantRole;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PosService;
use App\Support\ApiException;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$p = json_decode($argv[1], true);

try {
    if ($p['kind'] === 'pos') {
        $ctx = new TenantContext($p['tenant'], $p['user'], TenantRole::Owner);
        [$sale, $replayed] = app(PosService::class)->checkout($ctx, $p['data'], $p['key']);
        $out = ['ok' => true, 'id' => $sale->id, 'replayed' => $replayed];
    } else {
        [$order, $replayed] = app(OrderService::class)->place(User::findOrFail($p['user']), $p['data'], $p['key']);
        $out = ['ok' => true, 'id' => $order->id, 'replayed' => $replayed];
    }
} catch (ApiException $e) {
    $out = ['ok' => false, 'code' => $e->errorCode];
} catch (Throwable $e) {
    $out = ['ok' => false, 'code' => 'exception', 'message' => get_class($e).': '.$e->getMessage()];
}

echo json_encode($out);
