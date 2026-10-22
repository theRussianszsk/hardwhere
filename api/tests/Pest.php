<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Concurrency'); // committed data + real parallel processes: no wrapping transaction

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/** Empties every table (not wrapped in a rolled-back transaction) after tests that need committed data. */
function truncateAllTables(): void
{
    $tables = collect(Schema::getTableListing())->map(fn ($t) => preg_replace('/^public\./', '', $t))->reject(fn ($t) => $t === 'migrations')
        ->map(fn ($t) => '"'.$t.'"')->implode(',');
    DB::statement("TRUNCATE TABLE $tables RESTART IDENTITY CASCADE");
}

/** Idempotency-Key header for mutating API calls. */
function idem(string $seed = ''): array
{
    return ['Idempotency-Key' => 'test-key-'.($seed ?: bin2hex(random_bytes(6)))];
}
