<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Http;

use App\Modules\ClientPortal\Infrastructure\SessionStore;
use Symfony\Component\HttpFoundation\Cookie;

// Cookie del token de sesión: httpOnly, SameSite=Strict y Secure fuera de local.
final class PortalCookie
{
    public static function make(string $token, bool $trusted): Cookie
    {
        return Cookie::create(
            SessionStore::COOKIE,
            $token,
            $trusted ? now()->addDays(SessionStore::TRUSTED_DAYS) : 0,
            '/',
            self::domain(),
            (bool) config('session.secure'),
            true,
            false,
            Cookie::SAMESITE_STRICT,
        );
    }

    public static function forget(): Cookie
    {
        return Cookie::create(SessionStore::COOKIE, null, 1, '/', self::domain(), (bool) config('session.secure'), true, false, Cookie::SAMESITE_STRICT);
    }

    private static function domain(): ?string
    {
        $domain = config('session.domain');

        return is_string($domain) && $domain !== '' ? $domain : null;
    }
}
