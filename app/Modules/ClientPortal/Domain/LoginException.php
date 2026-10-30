<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Domain;

use Carbon\CarbonImmutable;
use RuntimeException;

// Errores del login que el front muestra tal cual: código incorrecto, bloqueo, reenvío antes de tiempo.
final class LoginException extends RuntimeException
{
    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status,
        public readonly ?int $attemptsLeft = null,
        public readonly ?CarbonImmutable $until = null,
    ) {
        parent::__construct($message);
    }

    public static function incorrectCode(int $attemptsLeft): self
    {
        return new self('incorrect_code', "Incorrect code. {$attemptsLeft} attempts left.", 422, attemptsLeft: $attemptsLeft);
    }

    public static function expiredCode(): self
    {
        return new self('expired_code', 'This code has expired. Please request a new one.', 422);
    }

    public static function locked(CarbonImmutable $until): self
    {
        return new self('locked', 'Too many attempts. Please try again in 15 minutes.', 423, until: $until);
    }

    public static function resendTooSoon(CarbonImmutable $availableAt): self
    {
        return new self('resend_too_soon', 'Please wait before requesting another code.', 429, until: $availableAt);
    }

    public static function invalidChallenge(): self
    {
        return new self('invalid_challenge', 'Your sign-in expired. Please start again.', 410);
    }

    public static function sendFailed(): self
    {
        return new self('send_failed', "We couldn't send the code. Please try again.", 502);
    }

    public static function noEmail(): self
    {
        return new self('no_email', 'There is no email on file.', 422);
    }
}
