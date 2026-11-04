<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Shared\Soft\SoftGateway;
use App\Modules\Shared\Soft\SoftRequestSigner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
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
        RateLimiter::for('portal-code', fn (Request $request): Limit => self::portalLimit(Limit::perHour(10), $request));
        RateLimiter::for('portal-requests', fn (Request $request): Limit => self::portalLimit(Limit::perHour(10), $request));
        RateLimiter::for('portal-verify', fn (Request $request): Limit => self::portalLimit(Limit::perMinute(30), $request));
    }

    // Límites por IP del portal: al pasarse responde como los demás errores del login, con la hora en que vuelve a poder.
    private static function portalLimit(Limit $limit, Request $request): Limit
    {
        return $limit->by((string) $request->ip())->response(fn (Request $request, array $headers): JsonResponse => response()->json([
            'code' => 'too_many_requests',
            'message' => 'Too many attempts. Please try again later.',
            'until' => now()->addSeconds((int) ($headers['Retry-After'] ?? 60))->toIso8601String(),
        ], 429, $headers));
    }
}
