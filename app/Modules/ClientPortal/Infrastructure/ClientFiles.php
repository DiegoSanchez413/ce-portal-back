<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Infrastructure;

use App\Modules\ClientPortal\Domain\DocumentStatus;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Documentos del cliente (ID, Utility bill, SSN) y cartas enviadas desde el portal,
 * leídos de las tablas del Soft con el mismo criterio de "archivo vigente" del Soft.
 */
class ClientFiles
{
    private const OPEN_SSN_REQUESTS = ['REQUESTED', 'UPLOADED', 'REJECTED'];

    /** @return array<int, stdClass> archivo vigente por tipo de documento, incluido el rechazado */
    public function currentDocuments(int $leadId): array
    {
        $rows = DB::select(<<<'SQL'
            WITH cur AS (
                SELECT fl.*, ROW_NUMBER() OVER (PARTITION BY fl.document_type_id ORDER BY fl.created_at DESC, fl.id DESC) AS rn
                  FROM files_leads fl
                 WHERE fl.lead_id = ? AND fl.deleted_at IS NULL AND fl.document_type_id IN (1, 2, 3)
            )
            SELECT cur.id, cur.document_type_id, cur.status, cur.created_at, cur.route, cur.file_name,
                   COALESCE(cur.expiration, fa.expiration) AS expiration,
                   COALESCE((SELECT t.motive FROM file_leads_tracking t
                              WHERE t.file_lead_id = cur.id AND t.status = 'REJECTED'
                              ORDER BY t.id DESC LIMIT 1), cur.description) AS reject_motive
              FROM cur
              LEFT JOIN files_accounts fa ON fa.id = cur.file_account_id
             WHERE cur.rn = 1
            SQL, [$leadId]);

        $byType = [];
        foreach ($rows as $row) {
            $byType[(int) $row->document_type_id] = $row;
        }

        return $byType;
    }

    /** @return list<int> documentos que se le muestran al cliente: ID y UB siempre; SSN si ya lo tiene o si se lo pidieron en el WorkPlan Inbox */
    public function documentTypes(int $leadId): array
    {
        $types = [DocumentStatus::ID, DocumentStatus::UTILITY_BILL];

        $hasSsn = DB::table('files_leads')->where('lead_id', $leadId)->where('document_type_id', DocumentStatus::SSN)->whereNull('deleted_at')->exists()
            || DB::table('wpi_file_requests')->where('lead_id', $leadId)->where('document_type_id', DocumentStatus::SSN)
                ->whereIn('status', self::OPEN_SSN_REQUESTS)->exists();

        return $hasSsn ? [...$types, DocumentStatus::SSN] : $types;
    }

    /** @return list<stdClass> cartas enviadas por el cliente, de la más reciente a la más antigua */
    public function letters(int $leadId): array
    {
        return array_values(DB::table('client_portal_letters as l')
            ->where('l.lead_id', $leadId)
            ->orderByDesc('l.created_at')
            ->orderByDesc('l.id')
            ->get(['l.id', 'l.type', 'l.bureau', 'l.status', 'l.created_at',
                DB::raw('(SELECT COUNT(*) FROM client_portal_letter_files f WHERE f.letter_id = l.id) AS files')])
            ->all());
    }
}
