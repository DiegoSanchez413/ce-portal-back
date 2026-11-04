<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Infrastructure;

use App\Modules\ClientPortal\Domain\UserAgent;
use App\Modules\ClientPortal\Http\DeviceCookie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sesiones del portal en client_portal_sessions; el navegador solo guarda el token en una
 * cookie httpOnly, y aquí se guarda su hash.
 */
final class SessionStore
{
    public const COOKIE = 'cp_session';

    public const IDLE_MINUTES = 30;

    public const TRUSTED_DAYS = 30;

    public const ABSOLUTE_DAYS = 90;

    /**
     * @param  list<int>  $eligibleLeadIds
     * @return array{0: PortalSession, 1: string}
     */
    public function start(LoginChallenge $challenge, int $leadId, string $accountId, array $eligibleLeadIds, bool $trusted, Request $request): array
    {
        $token = Str::random(64);
        $agent = UserAgent::parse((string) $request->userAgent());

        $id = DB::table('client_portal_sessions')->insertGetId([
            'token_hash' => hash('sha256', $token),
            'lead_id' => $leadId,
            'client_account_id' => $accountId,
            'eligible_lead_ids' => json_encode($eligibleLeadIds),
            'mobile_at_login' => $challenge->mobile,
            'login_method' => $challenge->loginMethod,
            'otp_channel' => $challenge->verifiedChannel ?? 'sms',
            'trusted_device' => $trusted,
            'device_id' => DeviceCookie::of($request),
            'device' => $agent->device,
            'os' => $agent->os,
            'browser' => $agent->browser,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'ip' => $request->ip(),
            'city' => $this->header($request, 'CF-IPCity', 100),
            'region' => $this->header($request, 'CF-Region', 100),
            'country' => $this->header($request, 'CF-IPCountry', 2),
            'started_at' => now(),
            'last_seen_at' => now(),
            'last_verified_at' => now(),
            'absolute_expires_at' => $trusted ? now()->addDays(self::ABSOLUTE_DAYS) : now()->addDays(1),
        ]);

        return [$this->findById((int) $id), $token];
    }

    public function findActiveByToken(string $token): ?PortalSession
    {
        $row = DB::table('client_portal_sessions')->where('token_hash', hash('sha256', $token))->whereNull('ended_at')->first();

        return $row === null ? null : PortalSession::fromRow($row);
    }

    public function touch(PortalSession $session): void
    {
        DB::table('client_portal_sessions')->where('id', $session->id)->update(['last_seen_at' => now()]);
    }

    public function switchTo(PortalSession $session, int $leadId, string $accountId): void
    {
        DB::table('client_portal_sessions')->where('id', $session->id)
            ->update(['lead_id' => $leadId, 'client_account_id' => $accountId, 'last_seen_at' => now()]);
    }

    public function end(PortalSession $session, string $reason): void
    {
        DB::table('client_portal_sessions')->where('id', $session->id)->whereNull('ended_at')
            ->update(['ended_at' => now(), 'end_reason' => $reason]);
    }

    public function endAllOfLead(int $leadId, string $reason): void
    {
        DB::table('client_portal_sessions')->where('lead_id', $leadId)->whereNull('ended_at')
            ->update(['ended_at' => now(), 'end_reason' => $reason]);
    }

    public function findById(int $id): PortalSession
    {
        $row = DB::table('client_portal_sessions')->where('id', $id)->first();

        return $row === null ? throw new \LogicException('Session not found.') : PortalSession::fromRow($row);
    }

    private function header(Request $request, string $name, int $max): ?string
    {
        $value = trim((string) $request->header($name, ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
