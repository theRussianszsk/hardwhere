<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

// The Inertia starter page needs a built Vite bundle. This deployment is an API for the mobile apps, so without a build
// the root answers with a small JSON descriptor instead of a 500.
Route::get('/', fn () => file_exists(public_path('build/manifest.json'))
    ? inertia('welcome')
    : response()->json([
        'name' => config('app.name'),
        'status' => 'ok',
        'api' => url('/api/v1'),
        'health' => url('/api/v1/health'),
        'docs' => 'Mobile apps talk to /api/v1; see README.md.',
    ]))->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
    });

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
