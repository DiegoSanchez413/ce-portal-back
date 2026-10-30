<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Http;

use App\Modules\ClientPortal\Infrastructure\PortalSession;
use Illuminate\Http\Request;

// Sesión que dejó AuthenticatePortalSession en el request.
final class CurrentSession
{
    public static function of(Request $request): PortalSession
    {
        $session = $request->attributes->get('portal_session');

        return $session instanceof PortalSession ? $session : abort(401);
    }
}
