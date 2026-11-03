<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Infrastructure;

use App\Modules\ClientPortal\Domain\Eligibility;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Lectura de leads y cuentas Credit Experts en las tablas del Soft.
 *
 * Un cliente del portal es un lead con al menos una cuenta de programa 3.
 */
final class ClientDirectory
{
    /** @return list<stdClass> leads con cuenta Credit Experts que tienen ese celular */
    public function leadsByMobile(string $mobile): array
    {
        return array_values(DB::table('leads as l')
            ->select('l.id', 'l.first_name', 'l.last_name', 'l.mobile', 'l.email', 'l.language')
            ->where('l.mobile', $mobile)
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('clients as c')
                ->join('client_accounts as ca', 'ca.client_id', '=', 'c.id')
                ->whereColumn('c.lead_id', 'l.id')
                ->where('ca.program_id', Eligibility::CREDIT_EXPERTS))
            ->orderBy('l.id')
            ->get()
            ->all());
    }

    public function accountByNumber(string $account): ?stdClass
    {
        return DB::table('client_accounts as ca')
            ->join('clients as c', 'c.id', '=', 'ca.client_id')
            ->select('ca.id', 'ca.account', 'ca.status', 'c.lead_id')
            ->where('ca.account', $account)
            ->where('ca.program_id', Eligibility::CREDIT_EXPERTS)
            ->first();
    }

    public function lead(int $leadId): ?stdClass
    {
        return DB::table('leads')
            ->select('id', 'first_name', 'last_name', 'mobile', 'email', 'language')
            ->where('id', $leadId)
            ->first();
    }

    /** @return list<string> ids de todas las cuentas del lead, de cualquier servicio */
    public function allAccountIds(int $leadId): array
    {
        return array_values(DB::table('client_accounts as ca')
            ->join('clients as c', 'c.id', '=', 'ca.client_id')
            ->where('c.lead_id', $leadId)
            ->pluck('ca.id')
            ->map(fn ($id): string => (string) $id)
            ->all());
    }

    /** @return list<stdClass> cuentas Credit Experts del lead, de la más reciente a la más antigua */
    public function accountsOf(int $leadId): array
    {
        return array_values(DB::table('client_accounts as ca')
            ->join('clients as c', 'c.id', '=', 'ca.client_id')
            ->select('ca.id', 'ca.account', 'ca.status', 'c.lead_id')
            ->where('c.lead_id', $leadId)
            ->where('ca.program_id', Eligibility::CREDIT_EXPERTS)
            ->orderByDesc('ca.created_at')
            ->get()
            ->all());
    }

    public function account(string $accountId): ?stdClass
    {
        return DB::table('client_accounts as ca')
            ->join('clients as c', 'c.id', '=', 'ca.client_id')
            ->select('ca.id', 'ca.account', 'ca.status', 'c.lead_id')
            ->where('ca.id', $accountId)
            ->where('ca.program_id', Eligibility::CREDIT_EXPERTS)
            ->first();
    }

    /** @return list<stdClass> */
    public function eligibleAccountsOf(int $leadId): array
    {
        return array_values(array_filter(
            $this->accountsOf($leadId),
            fn (stdClass $account): bool => Eligibility::isEligibleStatus((int) $account->status),
        ));
    }
}
