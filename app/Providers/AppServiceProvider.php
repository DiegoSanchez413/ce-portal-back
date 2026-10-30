<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Shared\Soft\SoftGateway;
use App\Modules\Shared\Soft\SoftRequestSigner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SoftGateway::class, fn (): SoftGateway => new SoftGateway(
            new SoftRequestSigner((string) config('services.soft.key')),
            (string) config('services.soft.url'),
            (int) config('services.soft.timeout'),
        ));
    }

    public function boot(): void
    {
        RateLimiter::for('client-logs', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->ip()));
        RateLimiter::for('portal-code', fn (Request $request): Limit => Limit::perHour(10)->by((string) $request->ip()));
        RateLimiter::for('portal-requests', fn (Request $request): Limit => Limit::perHour(10)->by((string) $request->ip()));
        RateLimiter::for('portal-verify', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->ip()));
    }
}
