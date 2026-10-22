<?php

namespace App\Providers;

use App\Ai\GemmaVisionProvider;
use App\Ai\MockVisionProvider;
use App\Ai\VisionProvider;
use App\Enums\TenantRole;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Provider is chosen by config: "mock" (labelled demo) or "gemma" (Gemini API). Add new providers here only.
        $this->app->bind(VisionProvider::class, fn () => config('hardwhere.ai.provider') === 'gemma' ? new GemmaVisionProvider : new MockVisionProvider);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureApi();
    }

    private function configureApi(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // One Gate ability per permission string; policies for everything else live next to the models they guard.
        foreach (TenantRole::all() as $permission) {
            Gate::define($permission, fn (User $user) => TenantContext::current()?->can($permission) ?? false);
        }

        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(8)->by(strtolower((string) $r->input('email')).'|'.$r->ip()));
        RateLimiter::for('register', fn (Request $r) => Limit::perHour(10)->by($r->ip()));
        RateLimiter::for('public', fn (Request $r) => Limit::perMinute(120)->by($r->ip()));
        RateLimiter::for('photo', fn (Request $r) => Limit::perMinute(12)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(600)->by($r->user()?->id ?: $r->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
