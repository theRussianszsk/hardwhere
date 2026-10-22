<?php

namespace App\Providers;

use App\Services\Vision\MockVisionProvider;
use App\Services\Vision\OpenAiCompatibleVisionProvider;
use App\Services\Vision\ProductVisionProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ProductVisionProvider::class, function (): ProductVisionProvider {
            $config = config('services.vision');

            return match ($config['driver']) {
                'openai' => new OpenAiCompatibleVisionProvider(
                    baseUrl: (string) $config['base_url'],
                    apiKey: (string) $config['api_key'],
                    model: (string) $config['model'],
                    timeoutSeconds: (int) $config['timeout'],
                ),
                default => new MockVisionProvider,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
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
