<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Http\Middleware;

use App\Modules\ClientPortal\Application\Reactivation;
use App\Modules\ClientPortal\Domain\Eligibility;
use App\Modules\ClientPortal\Domain\Phone;
use App\Modules\ClientPortal\Http\PortalCookie;
use App\Modules\ClientPortal\Infrastructure\ClientDirectory;
use App\Modules\ClientPortal\Infrastructure\DevSession;
use App\Modules\ClientPortal\Infrastructure\PortalSession;
use App\Modules\ClientPortal\Infrastructure\PortalSettings;
use App\Modules\ClientPortal\Infrastructure\SessionStore;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Revalida la sesión del portal en cada request: vencimiento, cambio de celular, switch
 * Portal access y elegibilidad de la cuenta. Si algo falla, cierra la sesión con su motivo.
 */
final class AuthenticatePortalSession
{
    public function __construct(
        private readonly SessionStore $sessions,
        private readonly ClientDirectory $directory,
        private readonly PortalSettings $settings,
        private readonly Reactivation $reactivation,
        private readonly DevSession $devSession,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $cookie = $request->cookie(SessionStore::COOKIE);
        $token = is_string($cookie) ? $cookie : '';
        $session = $token === '' ? null : $this->sessions->findActiveByToken($token);

        $devToken = null;
        if ($session === null && ($dev = $this->devSession->open($request)) !== null) {
            [$session, $devToken] = $dev;
        }

        if ($session === null) {
            return $this->unauthenticated('unauthenticated');
        }

        $idleLimit = $session->trustedDevice
            ? $session->lastSeenAt->addDays(SessionStore::TRUSTED_DAYS)
            : $session->lastSeenAt->addMinutes(SessionStore::IDLE_MINUTES);

        if ($session->absoluteExpiresAt->isPast()) {
            return $this->close($session, 'expired', 'session_expired');
        }
        if ($idleLimit->isPast()) {
            return $this->close($session, 'inactivity', 'session_expired');
        }

        $mobile = Phone::format((string) ($this->directory->lead($session->leadId)->mobile ?? ''));
        if ($mobile !== $session->mobileAtLogin) {
            $this->sessions->endAllOfLead($session->leadId, 'phone_change');

            return $this->unauthenticated('session_expired');
        }

        if (! $this->settings->isEnabled($session->leadId)) {
            return $this->close($session, 'portal_disabled', 'portal_disabled');
        }

        $account = $this->directory->account($session->accountId);
        if ($account === null || ! Eligibility::isEligibleStatus((int) $account->status)) {
            $this->sessions->end($session, 'status_change');

            return $this->noAccess($session)->withCookie(PortalCookie::forget());
        }

        if ($session->lastSeenAt->diffInSeconds(now()) >= 60) {
            $this->sessions->touch($session);
        }

        $request->attributes->set('portal_session', $session);
        $response = $next($request);

        if ($devToken !== null) {
            $response->headers->setCookie(PortalCookie::make($devToken, true));
        }
        if ($this->devSession->enabled()) {
            $loggedOut = $request->route()?->getActionMethod() === 'logout' && $response->isSuccessful();
            if ($loggedOut || $request->cookie(DevSession::PAUSE_COOKIE) !== null) {
                $response->headers->setCookie($this->devSession->pauseCookie($loggedOut));
            }
        }

        if ($session->trustedDevice && $session->lastSeenAt->diffInHours(now()) >= 24) {
            $response->headers->setCookie(PortalCookie::make($token, true));
        }

        return $response;
    }

    private function close(PortalSession $session, string $reason, string $code): JsonResponse
    {
        $this->sessions->end($session, $reason);

        return $this->unauthenticated($code);
    }

    private function unauthenticated(string $code): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => 'Please sign in again.'], 401)
            ->withCookie(PortalCookie::forget());
    }

    private function noAccess(PortalSession $session): JsonResponse
    {
        return response()->json([
            'code' => 'no_access',
            'message' => "Your account doesn't have portal access right now",
            'reactivation' => [
                'token' => $this->reactivation->token($session->leadId, $session->accountId),
                'available_at' => $this->reactivation->availableAt($session->accountId)?->toIso8601String(),
            ],
        ], 403);
    }
}
