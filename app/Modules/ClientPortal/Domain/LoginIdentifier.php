<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Domain;

final class LoginIdentifier
{
    private function __construct(
        public readonly string $method,
        public readonly string $value,
    ) {}

    // Celular de 10 dígitos (con o sin formato o +1) o número de cuenta CE; null si no es ninguno.
    public static function parse(string $input): ?self
    {
        $trimmed = strtoupper(trim($input));

        if (preg_match('/^CE\s*(\d{4,12})$/', $trimmed, $match) === 1) {
            return new self('account', 'CE'.$match[1]);
        }

        if (preg_match('/[A-Z]/', $trimmed) === 1) {
            return null;
        }

        $mobile = Phone::format($trimmed);

        return $mobile === null ? null : new self('phone', $mobile);
    }
}
