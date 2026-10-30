<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Application;

use App\Modules\ClientPortal\Domain\LoginException;
use App\Modules\ClientPortal\Infrastructure\ChallengeStore;
use App\Modules\ClientPortal\Infrastructure\OtpService;
use App\Modules\ClientPortal\Infrastructure\PortalSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Security check: código nuevo al celular de la sesión antes de agregar una tarjeta o
 * cambiar la del cobro automático, cuando la última verificación tiene más de 15 minutos.
 */
final class StepUp
{
    public const FRESH_MINUTES = 15;

    public function __construct(
        private readonly ChallengeStore $challenges,
        private readonly OtpService $otp,
    ) {}

    public function isFresh(PortalSession $session): bool
    {
        return $session->lastVerifiedAt->addMinutes(self::FRESH_MINUTES)->isFuture();
    }

    /** @return array{challenge_id: string, resend_available_at: CarbonImmutable} */
    public function send(PortalSession $session, string $language, ?string $ip): array
    {
        $challenge = $this->challenges->create('phone', $session->mobileAtLogin, null, [$session->leadId], $session->accountId, $language, $ip);
        $resendAt = $this->otp->issue($challenge, 'sms', $ip, 'step_up');

        return ['challenge_id' => $challenge->id, 'resend_available_at' => $resendAt];
    }

    public function verify(PortalSession $session, string $challengeId, string $code): void
    {
        $challenge = $this->challenges->find($challengeId);
        if ($challenge === null || $challenge->mobile !== $session->mobileAtLogin || $challenge->leadIds !== [$session->leadId]) {
            throw LoginException::invalidChallenge();
        }

        $this->otp->verify($challenge, $code);
        $this->challenges->close($challenge);

        DB::table('client_portal_sessions')->where('id', $session->id)->update(['last_verified_at' => now()]);
    }
}
