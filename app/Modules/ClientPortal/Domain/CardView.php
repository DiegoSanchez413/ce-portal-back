<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Domain;

use Carbon\CarbonImmutable;

final class CardView
{
    /**
     * Tarjeta para el portal: marca, últimos 4, MM/YY y si está vencida o vence en 30 días.
     *
     * @param  array{id: int, last4: string, brand: string|null, exp_month: int, exp_year: int, is_default: bool}  $card
     * @return array{id: int, brand: string|null, last4: string, expiration: string, expired: bool, expires_soon: bool, is_default: bool}
     */
    public static function from(array $card): array
    {
        $year = $card['exp_year'] < 100 ? 2000 + $card['exp_year'] : $card['exp_year'];
        $lastDay = CarbonImmutable::createFromDate($year, max(1, min(12, $card['exp_month'])), 1)->endOfMonth();
        $expired = $lastDay->isPast();

        return [
            'id' => $card['id'],
            'brand' => $card['brand'],
            'last4' => $card['last4'],
            'expiration' => sprintf('%02d/%02d', $card['exp_month'], $year % 100),
            'expired' => $expired,
            'expires_soon' => ! $expired && $lastDay->lte(CarbonImmutable::now()->addDays(30)),
            'is_default' => $card['is_default'],
        ];
    }
}
