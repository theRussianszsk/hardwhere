<?php

use Illuminate\Support\Facades\DB;

// Guards against RefreshDatabase ever running on the dev database.
it('runs on the disposable PostgreSQL test database', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('hardwhere_test');
});
