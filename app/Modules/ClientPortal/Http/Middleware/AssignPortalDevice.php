<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Http\Middleware;

use App\Modules\ClientPortal\Http\DeviceCookie;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

// Deja en cada pedido el identificador del navegador; si no traía la cookie cp_device, la crea en la respuesta.
final class AssignPortalDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $cookie = $request->cookie(DeviceCookie::NAME);
        $known = is_string($cookie) && Str::isUuid($cookie);
        $device = $known ? $cookie : (string) Str::uuid();
        $request->attributes->set(DeviceCookie::NAME, $device);

        $response = $next($request);
        if (! $known) {
            $response->headers->setCookie(DeviceCookie::make($device));
        }

        return $response;
    }
}
