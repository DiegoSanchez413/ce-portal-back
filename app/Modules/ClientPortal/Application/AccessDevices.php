<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Application;

use App\Modules\ClientPortal\Infrastructure\SessionStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Sesiones del cliente agrupadas por dispositivo: por la cookie cp_device y, en las sesiones
 * anteriores a ella, por navegador, sistema y ciudad. El más reciente va primero.
 */
final class AccessDevices
{
    private const DEVICES = 20;

    private const SESSIONS_PER_DEVICE = 10;

    private const DAYS = 90;

    /** @return list<array<string, mixed>> */
    public function of(int $leadId, int $currentSessionId): array
    {
        return array_values($this->groups($leadId)
            ->map(fn (Collection $rows, string $key): array => $this->present($key, $rows, $currentSessionId))
            ->sortByDesc('last_active_at')
            ->take(self::DEVICES)
            ->all());
    }

    // Cierra las sesiones abiertas de un dispositivo, salvo la actual; devuelve cuántas cerró o null si no existe.
    public function signOut(int $leadId, string $key, int $currentSessionId): ?int
    {
        $rows = $this->groups($leadId)->get($key);
        if ($rows === null) {
            return null;
        }

        $ids = $rows->filter(fn (stdClass $row): bool => self::active($row) && (int) $row->id !== $currentSessionId)->pluck('id')->all();

        return $ids === [] ? 0 : DB::table('client_portal_sessions')->whereIn('id', $ids)->whereNull('ended_at')
            ->update(['ended_at' => now(), 'end_reason' => 'signed_out_by_client']);
    }

    /** @return Collection<string, Collection<int, stdClass>> */
    private function groups(int $leadId): Collection
    {
        return DB::table('client_portal_sessions')
            ->where('lead_id', $leadId)
            ->where('started_at', '>=', now()->subDays(self::DAYS))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'device_id', 'device', 'os', 'browser', 'city', 'region', 'trusted_device', 'started_at', 'last_seen_at', 'absolute_expires_at', 'ended_at', 'end_reason'])
            ->groupBy(fn (stdClass $row): string => is_string($row->device_id) && $row->device_id !== ''
                ? $row->device_id
                : sha1(implode('|', [$row->device, $row->os, $row->browser, $row->city ?? '', $row->region ?? ''])));
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return array<string, mixed>
     */
    private function present(string $key, Collection $rows, int $currentSessionId): array
    {
        /** @var stdClass $latest */
        $latest = $rows->first();
        $lastActive = $rows->map(fn (stdClass $row): CarbonImmutable => CarbonImmutable::parse((string) ($row->ended_at ?? $row->last_seen_at)))->max();

        return [
            'key' => $key,
            'device' => $latest->device === 'Computer' && $latest->os !== 'Other' ? (string) $latest->os : (string) $latest->device,
            'browser' => (string) $latest->browser,
            'is_phone' => in_array($latest->device, ['iPhone', 'Android phone'], true),
            'city' => $latest->city,
            'region' => $latest->region,
            'last_active_at' => $lastActive?->toIso8601String(),
            'active' => $rows->contains(fn (stdClass $row): bool => self::active($row)),
            'current' => $rows->contains(fn (stdClass $row): bool => (int) $row->id === $currentSessionId),
            'total_sessions' => $rows->count(),
            'sessions' => $rows->take(self::SESSIONS_PER_DEVICE)->map(fn (stdClass $row): array => [
                'id' => (int) $row->id,
                'started_at' => CarbonImmutable::parse((string) $row->started_at)->toIso8601String(),
                'city' => $row->city,
                'region' => $row->region,
                'active' => self::active($row),
                'end_reason' => $row->end_reason,
                'current' => (int) $row->id === $currentSessionId,
            ])->values()->all(),
        ];
    }

    // Misma regla que el middleware de sesión: sin cierre, sin vencer y sin pasar el tiempo de inactividad.
    private static function active(stdClass $row): bool
    {
        if ($row->ended_at !== null || CarbonImmutable::parse((string) $row->absolute_expires_at)->isPast()) {
            return false;
        }
        $lastSeen = CarbonImmutable::parse((string) $row->last_seen_at);

        return ((bool) $row->trusted_device ? $lastSeen->addDays(SessionStore::TRUSTED_DAYS) : $lastSeen->addMinutes(SessionStore::IDLE_MINUTES))->isFuture();
    }
}
