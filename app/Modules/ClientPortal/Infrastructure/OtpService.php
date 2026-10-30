<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Infrastructure;

use App\Modules\ClientPortal\Domain\LoginException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Códigos de 6 dígitos: 5 minutos de vida, 5 intentos, reenvío a los 60 s y como máximo
 * 5 envíos por hora; al pasarse, el celular queda bloqueado 15 minutos.
 */
final class OtpService
{
    public const TTL_MINUTES = 5;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    public const MAX_SENDS_PER_HOUR = 5;

    public const LOCK_MINUTES = 15;

    public function __construct(
        private readonly CodeSender $sender,
        private readonly EventLog $events,
    ) {}

    public function issue(LoginChallenge $challenge, string $channel, ?string $ip, string $purpose = 'login'): CarbonImmutable
    {
        $this->assertNotLocked($challenge);

        $hash = $challenge->destinationHash();
        $last = DB::table('client_portal_otps')->where('challenge_id', $challenge->id)->max('created_at');
        if ($last !== null) {
            $availableAt = CarbonImmutable::parse((string) $last)->addSeconds(self::RESEND_SECONDS);
            if ($availableAt->isFuture()) {
                throw LoginException::resendTooSoon($availableAt);
            }
        }

        $sentLastHour = DB::table('client_portal_otps')->where('destination_hash', $hash)
            ->where('created_at', '>', now()->subHour())->count();
        if ($sentLastHour >= self::MAX_SENDS_PER_HOUR) {
            $this->lock($challenge);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        if (! $challenge->isGhost()) {
            $destination = $channel === 'email' ? (string) $challenge->email : $challenge->mobile;
            $this->sender->send($channel, $destination, $code, $challenge->language);
        }

        DB::table('client_portal_otps')->where('challenge_id', $challenge->id)->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        DB::table('client_portal_otps')->insert([
            'challenge_id' => $challenge->id,
            'channel' => $channel,
            'destination_hash' => $hash,
            'code_hash' => $this->hashCode($challenge, $code),
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'ip' => $ip,
            'created_at' => now(),
        ]);

        if (! $challenge->isGhost()) {
            $this->events->record('otp_sent', null, $challenge->singleLeadId(), $challenge->accountId, [
                'channel' => $channel,
                'login_method' => $challenge->loginMethod,
            ]);
        }

        return CarbonImmutable::now()->addSeconds(self::RESEND_SECONDS);
    }

    // Devuelve el canal del código válido; si falla suma el intento y bloquea al agotarlos.
    public function verify(LoginChallenge $challenge, string $code): string
    {
        $this->assertNotLocked($challenge);

        $otp = DB::table('client_portal_otps')->where('challenge_id', $challenge->id)
            ->whereNull('consumed_at')->orderByDesc('id')->first();

        if ($otp === null || CarbonImmutable::parse((string) $otp->expires_at)->isPast()) {
            throw LoginException::expiredCode();
        }

        if (! $challenge->isGhost() && hash_equals((string) $otp->code_hash, $this->hashCode($challenge, $code))) {
            DB::table('client_portal_otps')->where('id', $otp->id)->update(['consumed_at' => now()]);

            return (string) $otp->channel;
        }

        $attempts = (int) $otp->attempts + 1;
        DB::table('client_portal_otps')->where('id', $otp->id)->update(['attempts' => $attempts]);
        $meta = ['channel' => (string) $otp->channel, 'login_method' => $challenge->loginMethod];

        if ($attempts >= self::MAX_ATTEMPTS) {
            DB::table('client_portal_otps')->where('id', $otp->id)->update(['consumed_at' => now()]);
            if (! $challenge->isGhost()) {
                $this->events->record('otp_locked', null, $challenge->singleLeadId(), $challenge->accountId, $meta + ['failed_codes' => $attempts]);
            }
            $this->lock($challenge);
        }

        if (! $challenge->isGhost()) {
            $this->events->record('otp_failed', null, $challenge->singleLeadId(), $challenge->accountId, $meta);
        }

        throw LoginException::incorrectCode(self::MAX_ATTEMPTS - $attempts);
    }

    public function lockedUntil(LoginChallenge $challenge): ?CarbonImmutable
    {
        $until = DB::table('client_portal_locks')->where('destination_hash', $challenge->destinationHash())
            ->where('locked_until', '>', now())->value('locked_until');

        return $until === null ? null : CarbonImmutable::parse((string) $until);
    }

    private function assertNotLocked(LoginChallenge $challenge): void
    {
        $until = $this->lockedUntil($challenge);
        if ($until !== null) {
            throw LoginException::locked($until);
        }
    }

    private function lock(LoginChallenge $challenge): never
    {
        $until = CarbonImmutable::now()->addMinutes(self::LOCK_MINUTES);

        DB::table('client_portal_locks')->upsert(
            [['destination_hash' => $challenge->destinationHash(), 'locked_until' => $until, 'created_at' => now(), 'updated_at' => now()]],
            ['destination_hash'],
            ['locked_until', 'updated_at'],
        );

        throw LoginException::locked($until);
    }

    private function hashCode(LoginChallenge $challenge, string $code): string
    {
        return hash_hmac('sha256', $challenge->id.'|'.$code, (string) config('app.key'));
    }
}
