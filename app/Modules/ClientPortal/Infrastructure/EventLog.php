<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Infrastructure;

use Illuminate\Support\Facades\DB;

// Escribe en client_portal_events; metadata solo con datos no sensibles.
final class EventLog
{
    /** @param array<string, scalar|null> $metadata */
    public function record(
        string $type,
        ?int $sessionId,
        ?int $leadId,
        ?string $accountId,
        array $metadata = [],
        ?string $entityType = null,
        ?string $entityId = null,
    ): void {
        DB::table('client_portal_events')->insert([
            'session_id' => $sessionId,
            'lead_id' => $leadId,
            'client_account_id' => $accountId,
            'event_type' => $type,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata' => $metadata === [] ? null : json_encode($metadata),
            'occurrences' => 1,
            'created_at' => now(),
            'last_at' => now(),
        ]);
    }

    // Una fila por sección y sesión; las visitas siguientes suman occurrences.
    public function sectionView(int $sessionId, int $leadId, string $accountId, string $section): void
    {
        DB::table('client_portal_events')->upsert(
            [[
                'session_id' => $sessionId,
                'lead_id' => $leadId,
                'client_account_id' => $accountId,
                'event_type' => 'section_view',
                'section' => $section,
                'occurrences' => 1,
                'created_at' => now(),
                'last_at' => now(),
            ]],
            ['session_id', 'event_type', 'section', 'client_account_id'],
            ['occurrences' => DB::raw('occurrences + 1'), 'last_at' => now()],
        );
    }
}
