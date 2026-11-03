<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Domain;

use Carbon\CarbonImmutable;
use stdClass;

/**
 * Estado de un documento para el cliente: el SSN no vence; sin fecha de vencimiento cuenta
 * como vencido, como en el Soft; un documento en revisión manda sobre el vencimiento.
 */
final class DocumentStatus
{
    public const TYPES = [1 => 'id', 2 => 'utility_bill', 3 => 'ssn'];

    public const ID = 1;

    public const UTILITY_BILL = 2;

    public const SSN = 3;

    /** @return array{type: string, status: string, uploaded_at: string|null, expiration: string|null, expires_in_days: int|null, reject_reason: string|null} */
    public static function of(int $type, ?stdClass $file): array
    {
        $base = ['type' => self::TYPES[$type], 'uploaded_at' => null, 'expiration' => null, 'expires_in_days' => null, 'reject_reason' => null];

        if ($file === null) {
            return ['type' => $base['type'], 'status' => 'missing'] + $base;
        }

        $expiration = $file->expiration !== null ? CarbonImmutable::parse((string) $file->expiration)->startOfDay() : null;
        $today = CarbonImmutable::today();
        $base['uploaded_at'] = CarbonImmutable::parse((string) $file->created_at)->toDateString();
        $base['expiration'] = $type === self::SSN ? null : $expiration?->toDateString();

        $status = match ((string) $file->status) {
            'PENDING_APPROVE' => 'in_review',
            'REJECTED' => 'rejected',
            default => $type !== self::SSN && ($expiration === null || $expiration->lt($today)) ? 'expired' : 'approved',
        };

        if ($status === 'rejected') {
            $base['reject_reason'] = trim((string) $file->reject_motive) !== '' ? trim((string) $file->reject_motive) : null;
        }
        if ($status === 'approved' && $type !== self::SSN && $expiration !== null) {
            $base['expires_in_days'] = (int) $today->diffInDays($expiration);
        }

        return ['type' => $base['type'], 'status' => $status] + $base;
    }

    // Documentos que piden atención en Inicio: rechazados, vencidos, faltantes o que vencen en 30 días.
    /** @param array{type: string, status: string, expires_in_days: int|null} $document */
    public static function needsAttention(array $document): bool
    {
        return match ($document['status']) {
            'rejected', 'expired', 'missing' => true,
            'approved' => $document['expires_in_days'] !== null && $document['expires_in_days'] <= 30,
            default => false,
        };
    }
}
