<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Domain;

final class CreditScore
{
    // Score de un buró tal como lo guarda el Soft; los textos de "no reportado" (L1, Not Reported, -) quedan en null.
    public static function parse(mixed $value): ?int
    {
        $text = trim((string) $value);
        if (preg_match('/^\d{3}$/', $text) !== 1) {
            return null;
        }

        $score = (int) $text;

        return $score >= 300 && $score <= 850 ? $score : null;
    }
}
