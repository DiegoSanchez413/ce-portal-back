<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Domain;

final class UserAgent
{
    private function __construct(
        public readonly string $device,
        public readonly string $os,
        public readonly string $browser,
    ) {}

    // Dispositivo, sistema y navegador legibles ("iPhone · Safari") a partir del user agent.
    public static function parse(string $ua): self
    {
        $device = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') && str_contains($ua, 'Mobile') => 'Android phone',
            str_contains($ua, 'Android') => 'Android tablet',
            default => 'Computer',
        };

        $os = match (true) {
            preg_match('/iPhone|iPad|iPod/', $ua) === 1 => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Other',
        };

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'CriOS') || str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'FxiOS') || str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Other',
        };

        return new self($device, $os, $browser);
    }
}
