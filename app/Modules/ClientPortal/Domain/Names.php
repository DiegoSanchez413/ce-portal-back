<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Domain;

final class Names
{
    public static function full(?string $first, ?string $last, ?string $middle = null): string
    {
        $parts = array_filter([$first, $middle, $last], fn (?string $p): bool => trim((string) $p) !== '');

        return ucwords(mb_strtolower(trim(implode(' ', array_map(fn (?string $p): string => trim((string) $p), $parts)))));
    }

    // "Elvis R.": nombre y la inicial del apellido.
    public static function short(?string $first, ?string $last): string
    {
        $first = ucwords(mb_strtolower(trim((string) $first)));
        $initial = mb_strtoupper(mb_substr(trim((string) $last), 0, 1));

        return trim($first.($initial !== '' ? " {$initial}." : ''));
    }
}
