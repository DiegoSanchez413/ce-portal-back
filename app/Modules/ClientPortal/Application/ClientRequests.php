<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Application;

use App\Modules\ClientPortal\Domain\Names;
use App\Modules\ClientPortal\Infrastructure\ClientDirectory;
use App\Modules\ClientPortal\Infrastructure\EventLog;
use App\Modules\ClientPortal\Infrastructure\PortalSession;
use App\Modules\Shared\Soft\SoftGateway;
use Illuminate\Support\Facades\DB;

/**
 * Solicitudes del cliente (interés en otros servicios, cambio de datos): avisa en la campana
 * del Soft y guarda la solicitud con su evento en la misma transacción.
 */
final class ClientRequests
{
    private const EVENTS = ['services_interest' => 'services_interest', 'data_change' => 'change_request'];

    private const TITLES = ['services_interest' => 'Services interest', 'data_change' => 'Data change request'];

    public function __construct(
        private readonly ClientDirectory $directory,
        private readonly SoftGateway $soft,
        private readonly EventLog $events,
    ) {}

    /** @param array<string, mixed> $payload */
    public function create(PortalSession $session, string $type, string $recipient, array $payload = [], string $detail = ''): int
    {
        $lead = $this->directory->lead($session->leadId);
        $account = $this->directory->account($session->accountId);
        $text = sprintf(
            '<b>Client Portal · %s</b>: %s (%s)%s',
            self::TITLES[$type],
            e(Names::full($lead->first_name ?? null, $lead->last_name ?? null)),
            e((string) ($account->account ?? '')),
            $detail === '' ? '' : ' · '.e($detail),
        );

        $this->soft->post('notifications', [
            'client_account_id' => $session->accountId,
            'recipient' => $recipient,
            'text' => mb_substr($text, 0, 255),
        ])->throw();

        return DB::transaction(function () use ($session, $type, $payload): int {
            $id = (int) DB::table('client_portal_requests')->insertGetId([
                'type' => $type,
                'lead_id' => $session->leadId,
                'client_account_id' => $session->accountId,
                'session_id' => $session->id,
                'payload' => $payload === [] ? null : json_encode($payload),
                'created_at' => now(),
            ]);

            $this->events->record(self::EVENTS[$type], $session->id, $session->leadId, $session->accountId,
                self::category($payload), 'request', (string) $id);

            return $id;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private static function category(array $payload): array
    {
        $changes = $payload['changes'] ?? null;

        return is_array($changes) ? ['category' => implode(',', array_column($changes, 'field'))] : [];
    }
}
