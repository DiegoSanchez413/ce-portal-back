<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Http;

use App\Modules\ClientPortal\Domain\Names;
use App\Modules\ClientPortal\Infrastructure\ClientDirectory;

// Opciones del selector de cliente: nombre corto, últimos 4 dígitos de la cuenta que abriría y si es el actual.
final class ClientOptions
{
    public function __construct(private readonly ClientDirectory $directory) {}

    /**
     * @param  list<int>  $leadIds
     * @return list<array{lead_id: int, label: string, account_hint: string|null, current: bool}>
     */
    public function of(array $leadIds, ?int $currentLeadId = null): array
    {
        return array_map(function (int $leadId) use ($currentLeadId): array {
            $lead = $this->directory->lead($leadId);
            $account = $this->directory->eligibleAccountsOf($leadId)[0] ?? null;
            $digits = (string) preg_replace('/\D/', '', (string) ($account->account ?? ''));

            return [
                'lead_id' => $leadId,
                'label' => Names::short((string) ($lead->first_name ?? ''), (string) ($lead->last_name ?? '')).' · Credit Experts',
                'account_hint' => $digits === '' ? null : substr($digits, -4),
                'current' => $leadId === $currentLeadId,
            ];
        }, $leadIds);
    }
}
