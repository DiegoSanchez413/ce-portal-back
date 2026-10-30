<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Application;

use App\Modules\ClientPortal\Domain\Eligibility;
use App\Modules\ClientPortal\Infrastructure\ClientDirectory;
use App\Modules\ClientPortal\Infrastructure\EventLog;
use App\Modules\ClientPortal\Infrastructure\LoginChallenge;
use App\Modules\ClientPortal\Infrastructure\PortalSettings;
use App\Modules\ClientPortal\Infrastructure\SessionStore;
use Illuminate\Http\Request;

/**
 * Abre la sesión del cliente elegido tras verificar el código.
 *
 * Con número de cuenta entra a esa cuenta; con celular, a la cuenta elegible más reciente.
 * Si no hay cuenta elegible o el switch Portal access está apagado, devuelve la pantalla sin acceso.
 */
final class ClientAccess
{
    public function __construct(
        private readonly ClientDirectory $directory,
        private readonly PortalSettings $settings,
        private readonly SessionStore $sessions,
        private readonly EventLog $events,
    ) {}

    public function enter(LoginChallenge $challenge, int $leadId, bool $keepSignedIn, Request $request): AccessDecision
    {
        $account = $this->targetAccount($challenge, $leadId);

        if ($account === null) {
            $latest = $this->directory->accountsOf($leadId)[0] ?? null;

            return AccessDecision::denied($leadId, (string) ($latest->id ?? ''));
        }

        if (! $this->settings->isEnabled($leadId)) {
            return AccessDecision::denied($leadId, (string) $account->id);
        }

        [$session, $token] = $this->sessions->start(
            $challenge,
            $leadId,
            (string) $account->id,
            $this->eligibleLeads($challenge),
            $keepSignedIn,
            $request,
        );

        $this->events->record('login', $session->id, $leadId, $session->accountId, [
            'login_method' => $challenge->loginMethod,
            'channel' => (string) $challenge->verifiedChannel,
            'trusted_device' => $keepSignedIn,
        ]);

        return AccessDecision::granted($session, $token);
    }

    /** @return list<int> leads del celular que tienen alguna cuenta elegible */
    public function eligibleLeads(LoginChallenge $challenge): array
    {
        if ($challenge->loginMethod === 'account') {
            return $challenge->leadIds;
        }

        return array_values(array_filter(
            $challenge->leadIds,
            fn (int $leadId): bool => $this->directory->eligibleAccountsOf($leadId) !== [],
        ));
    }

    private function targetAccount(LoginChallenge $challenge, int $leadId): ?\stdClass
    {
        if ($challenge->loginMethod === 'account' && $challenge->accountId !== null) {
            $account = $this->directory->account($challenge->accountId);

            return $account !== null && Eligibility::isEligibleStatus((int) $account->status) ? $account : null;
        }

        return $this->directory->eligibleAccountsOf($leadId)[0] ?? null;
    }
}
