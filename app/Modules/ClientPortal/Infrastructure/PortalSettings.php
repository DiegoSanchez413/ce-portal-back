<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Infrastructure;

use Illuminate\Support\Facades\DB;

// Switch Portal access e idioma del portal por lead; sin fila, el acceso está encendido.
final class PortalSettings
{
    public function isEnabled(int $leadId): bool
    {
        $enabled = DB::table('client_portal_settings')->where('lead_id', $leadId)->value('portal_enabled');

        return $enabled === null || (bool) $enabled;
    }

    public function language(int $leadId): ?string
    {
        $language = DB::table('client_portal_settings')->where('lead_id', $leadId)->value('portal_language');

        return is_string($language) ? $language : null;
    }

    public function setLanguage(int $leadId, string $language): void
    {
        DB::table('client_portal_settings')->upsert(
            [['lead_id' => $leadId, 'portal_language' => $language, 'created_at' => now(), 'updated_at' => now()]],
            ['lead_id'],
            ['portal_language', 'updated_at'],
        );
    }
}
