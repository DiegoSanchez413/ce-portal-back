<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Infrastructure;

use Illuminate\Support\Facades\Http;
use Throwable;

// Valida el token de Cloudflare Turnstile; sin clave secreta solo se omite fuera de producción.
final class Turnstile
{
    public function passes(?string $token, ?string $ip): bool
    {
        $secret = (string) config('services.turnstile.secret');

        if ($secret === '') {
            return ! app()->isProduction();
        }

        if ($token === null || $token === '') {
            return false;
        }

        try {
            return (bool) Http::asForm()->timeout(5)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ])->json('success', false);
        } catch (Throwable) {
            return false;
        }
    }
}
