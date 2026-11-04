<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Infrastructure;

use Illuminate\Support\Facades\DB;

// Switch Portal access por lead (sin fila, encendido) e idioma del cliente tomado de leads.language.
final class PortalSettings
{
    public function isEnabled(int $leadId): bool
    {
        $enabled = DB::table('client_portal_settings')->where('lead_id', $leadId)->value('portal_enabled');

        return $enabled === null || (bool) $enabled;
    }

    // Idioma del cliente: el de leads.language (ES/EN en el Soft); cualquier otro valor cuenta como sin idioma.
    public function language(int $leadId): ?string
    {
        $language = strtolower((string) DB::table('leads')->where('id', $leadId)->value('language'));

        return in_array($language, ['en', 'es'], true) ? $language : null;
    }
}
