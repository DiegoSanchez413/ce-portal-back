<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Domain;

final class Phone
{
    // Formato del Soft: (###) ###-####.
    public static function format(string $value): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $value);

        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) {
            return null;
        }

        return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
    }

    public static function mask(string $mobile): string
    {
        return '***-***-'.substr((string) preg_replace('/\D/', '', $mobile), -4);
    }

    public static function maskEmail(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($user, 0, 1).'*****@'.$domain;
    }
}
