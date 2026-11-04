<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

// Cookie del dispositivo: un uuid al azar que no da acceso a nada; solo agrupa las sesiones del mismo navegador.
final class DeviceCookie
{
    public const NAME = 'cp_device';

    private const DAYS = 400;

    public static function of(Request $request): ?string
    {
        $device = $request->attributes->get(self::NAME);

        return is_string($device) ? $device : null;
    }

    public static function make(string $device): Cookie
    {
        $domain = config('session.domain');

        return Cookie::create(self::NAME, $device, now()->addDays(self::DAYS), '/', is_string($domain) && $domain !== '' ? $domain : null,
            (bool) config('session.secure'), true, false, Cookie::SAMESITE_STRICT);
    }
}
