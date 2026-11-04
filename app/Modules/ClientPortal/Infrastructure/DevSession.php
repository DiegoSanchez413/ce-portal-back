<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Infrastructure;

use App\Modules\ClientPortal\Domain\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

// Solo en local: con PORTAL_DEV_LEAD_ID abre una sesión de ese cliente sin pedir código.
// Tras un logout queda en pausa en ese navegador hasta un ingreso real o hasta cerrarlo.
final class DevSession
{
    public const PAUSE_COOKIE = 'cp_dev_paused';

    public function __construct(
        private readonly ClientDirectory $directory,
        private readonly SessionStore $sessions,
    ) {}

    /** @return array{0: PortalSession, 1: string}|null */
    public function open(Request $request): ?array
    {
        if (! $this->enabled() || $request->cookie(self::PAUSE_COOKIE) !== null) {
            return null;
        }

        $leadId = (int) config('client_portal.dev_lead_id');
        $lead = $this->directory->lead($leadId);
        $account = $this->directory->eligibleAccountsOf($leadId)[0] ?? null;
        $mobile = Phone::format((string) ($lead->mobile ?? ''));
        if ($lead === null || $account === null || $mobile === null) {
            return null;
        }

        $now = CarbonImmutable::now();
        $email = is_string($lead->email ?? null) ? $lead->email : null;
        $challenge = new LoginChallenge('dev', 'phone', $mobile, $email, [$leadId], (string) $account->id, 'en', $now, 'sms', $now);

        return $this->sessions->start($challenge, $leadId, (string) $account->id, [$leadId], true, $request);
    }

    public function enabled(): bool
    {
        return app()->isLocal() && (int) config('client_portal.dev_lead_id') !== 0;
    }

    public function pauseCookie(bool $paused): Cookie
    {
        return Cookie::create(self::PAUSE_COOKIE, $paused ? '1' : null, $paused ? 0 : 1, '/', null, false, true, false, Cookie::SAMESITE_STRICT);
    }
}
