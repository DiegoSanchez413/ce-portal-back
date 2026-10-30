<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Domain;

use Carbon\CarbonImmutable;

// Zona horaria del cliente a partir de leads.standard_time_zone; sin zona, Pacífico.
final class ClientTime
{
    private const ZONES = [
        'EST' => 'America/New_York',
        'CST' => 'America/Chicago',
        'MST' => 'America/Denver',
        'PST' => 'America/Los_Angeles',
        'AKST' => 'America/Anchorage',
        'HST' => 'Pacific/Honolulu',
    ];

    public static function zone(?string $standardTimeZone): string
    {
        $code = strtoupper(trim((string) $standardTimeZone, " []\"'"));

        return self::ZONES[$code] ?? 'America/Los_Angeles';
    }

    // Fecha y hora del servidor (Pacífico) llevadas a la zona del cliente.
    public static function fromPacific(string $datetime, string $zone): CarbonImmutable
    {
        return CarbonImmutable::parse($datetime, 'America/Los_Angeles')->setTimezone($zone);
    }
}
