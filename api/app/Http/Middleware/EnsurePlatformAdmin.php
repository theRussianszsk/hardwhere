<?php

namespace App\Http\Middleware;

use App\Support\ApiException;
use Closure;
use Illuminate\Http\Request;

/** Admin tokens are only honoured while the account is STILL a platform administrator (demotion takes effect immediately). */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->is_platform_admin) {
            $request->user()?->currentAccessToken()?->delete();
            throw ApiException::forbidden('Platform administrator access was revoked.');
        }

        return $next($request);
    }
}
