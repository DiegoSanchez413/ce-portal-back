<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Application;

use App\Modules\ClientPortal\Infrastructure\ClientDirectory;
use App\Modules\ClientPortal\Infrastructure\EventLog;
use App\Modules\Shared\Soft\SoftGateway;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Pantalla sin acceso: el token firmado identifica cliente y cuenta sin sesión abierta, y
 * la solicitud de reactivación avisa al Specialist una vez cada 24 horas por cuenta.
 */
final class Reactivation
{
    private const BLOCK_HOURS = 24;

    private const TOKEN_MINUTES = 30;

    public function __construct(
        private readonly ClientDirectory $directory,
        private readonly SoftGateway $soft,
        private readonly EventLog $events,
    ) {}

    public function token(int $leadId, string $accountId): string
    {
        return Crypt::encryptString((string) json_encode([
            'lead_id' => $leadId,
            'account_id' => $accountId,
            'exp' => now()->addMinutes(self::TOKEN_MINUTES)->timestamp,
        ]));
    }

    /** @return array{lead_id: int, account_id: string}|null */
    public function readToken(string $token): ?array
    {
        try {
            /** @var array{lead_id?: int, account_id?: string, exp?: int} $data */
            $data = (array) json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return null;
        }

        if (! isset($data['lead_id'], $data['account_id'], $data['exp']) || $data['exp'] < now()->timestamp) {
            return null;
        }

        return ['lead_id' => (int) $data['lead_id'], 'account_id' => (string) $data['account_id']];
    }

    public function availableAt(string $accountId): ?CarbonImmutable
    {
        $last = DB::table('client_portal_requests')->where('client_account_id', $accountId)
            ->where('type', 'reactivation')->where('created_at', '>', now()->subHours(self::BLOCK_HOURS))
            ->max('created_at');

        return $last === null ? null : CarbonImmutable::parse((string) $last)->addHours(self::BLOCK_HOURS);
    }

    /** @return array{sent: bool, available_at: CarbonImmutable} */
    public function request(int $leadId, string $accountId): array
    {
        $blockedUntil = $this->availableAt($accountId);
        if ($blockedUntil !== null) {
            return ['sent' => false, 'available_at' => $blockedUntil];
        }

        $lead = $this->directory->lead($leadId);
        $account = $this->directory->account($accountId);
        $name = trim(($lead->first_name ?? '').' '.($lead->last_name ?? ''));

        $response = $this->soft->post('notifications', [
            'client_account_id' => $accountId,
            'recipient' => 'specialist',
            'text' => sprintf('<b>Client Portal · Reactivation request</b>: %s (%s)', e($name), e((string) ($account->account ?? ''))),
        ]);
        $response->throw();

        DB::transaction(function () use ($leadId, $accountId): void {
            $id = DB::table('client_portal_requests')->insertGetId([
                'type' => 'reactivation',
                'lead_id' => $leadId,
                'client_account_id' => $accountId,
                'created_at' => now(),
            ]);
            $this->events->record('reactivation_request', null, $leadId, $accountId, [], 'request', (string) $id);
        });

        return ['sent' => true, 'available_at' => CarbonImmutable::now()->addHours(self::BLOCK_HOURS)];
    }
}
