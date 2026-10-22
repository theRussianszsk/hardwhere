<?php

use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetTeamUrlDefaults;
use App\Support\ApiErrors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a load balancer set TRUSTED_PROXIES (comma separated IPs/CIDRs, or *) so rate limits see the real client IP.
        // Read from the real process environment: env() is unavailable here once config is cached.
        $middleware->trustProxies(at: getenv('TRUSTED_PROXIES') ? explode(',', (string) getenv('TRUSTED_PROXIES')) : null);
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        $middleware->alias(['abilities' => CheckAbilities::class, 'ability' => CheckForAnyAbility::class, 'tenant' => ResolveTenant::class, 'platform_admin' => EnsurePlatformAdmin::class]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetTeamUrlDefaults::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        ApiErrors::register($exceptions);
    })->create();
