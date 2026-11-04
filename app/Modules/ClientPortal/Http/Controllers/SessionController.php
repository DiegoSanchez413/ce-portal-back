<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Http\Controllers;

use App\Modules\ClientPortal\Application\Reactivation;
use App\Modules\ClientPortal\Domain\Eligibility;
use App\Modules\ClientPortal\Http\ClientOptions;
use App\Modules\ClientPortal\Http\PortalCookie;
use App\Modules\ClientPortal\Infrastructure\ClientDirectory;
use App\Modules\ClientPortal\Infrastructure\EventLog;
use App\Modules\ClientPortal\Infrastructure\PortalSession;
use App\Modules\ClientPortal\Infrastructure\PortalSettings;
use App\Modules\ClientPortal\Infrastructure\SessionStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

// Sesión abierta: datos del cliente para el header, cambio de cliente o cuenta, idioma y salida.
final class SessionController
{
    public function __construct(
        private readonly ClientDirectory $directory,
        private readonly PortalSettings $settings,
        private readonly SessionStore $sessions,
        private readonly EventLog $events,
        private readonly Reactivation $reactivation,
        private readonly ClientOptions $options,
    ) {}

    #[OA\Get(
        path: '/session',
        operationId: 'getSession',
        summary: 'Current client, account and language',
        tags: ['Session'],
        responses: [
            new OA\Response(response: 200, description: 'Session', content: new OA\JsonContent(ref: '#/components/schemas/PortalSessionInfo')),
            new OA\Response(response: 401, description: 'Signed out', content: new OA\JsonContent(ref: '#/components/schemas/LoginError')),
            new OA\Response(response: 403, description: 'No access', content: new OA\JsonContent(ref: '#/components/schemas/NoAccess')),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($this->session($request)));
    }

    #[OA\Get(
        path: '/session/clients',
        operationId: 'listSessionClients',
        summary: 'Clients linked to the shared phone',
        tags: ['Session'],
        responses: [new OA\Response(response: 200, description: 'Clients', content: new OA\JsonContent(
            type: 'array', items: new OA\Items(ref: '#/components/schemas/ClientOption'),
        ))],
    )]
    public function clients(Request $request): JsonResponse
    {
        $session = $this->session($request);

        return response()->json($this->options->of($session->eligibleLeadIds, $session->leadId));
    }

    #[OA\Post(
        path: '/session/switch-client',
        operationId: 'switchClient',
        summary: 'Switch to another client of the shared phone without a new code',
        tags: ['Session'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['lead_id'], properties: [new OA\Property(property: 'lead_id', type: 'integer')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Session', content: new OA\JsonContent(ref: '#/components/schemas/PortalSessionInfo')),
            new OA\Response(response: 403, description: 'No access for that client', content: new OA\JsonContent(ref: '#/components/schemas/NoAccess')),
        ],
    )]
    public function switchClient(Request $request): JsonResponse
    {
        $session = $this->session($request);
        $leadId = (int) $request->validate(['lead_id' => ['required', 'integer']])['lead_id'];

        if (! in_array($leadId, $session->eligibleLeadIds, true)) {
            abort(404);
        }

        $account = $this->directory->eligibleAccountsOf($leadId)[0] ?? null;
        if ($account === null || ! $this->settings->isEnabled($leadId)) {
            $latest = $account ?? ($this->directory->accountsOf($leadId)[0] ?? null);

            return response()->json([
                'code' => 'no_access',
                'message' => "Your account doesn't have portal access right now",
                'reactivation' => [
                    'token' => $latest === null ? null : $this->reactivation->token($leadId, (string) $latest->id),
                    'available_at' => $latest === null ? null : $this->reactivation->availableAt((string) $latest->id)?->toIso8601String(),
                ],
            ], 403);
        }

        return $this->switchTo($session, $leadId, (string) $account->id);
    }

    #[OA\Post(
        path: '/session/switch-account',
        operationId: 'switchAccount',
        summary: 'Switch between the client Credit Experts accounts',
        tags: ['Session'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['account_id'], properties: [new OA\Property(property: 'account_id', type: 'string')],
        )),
        responses: [new OA\Response(response: 200, description: 'Session', content: new OA\JsonContent(ref: '#/components/schemas/PortalSessionInfo'))],
    )]
    public function switchAccount(Request $request): JsonResponse
    {
        $session = $this->session($request);
        $accountId = (string) $request->validate(['account_id' => ['required', 'string', 'max:36']])['account_id'];

        $account = $this->directory->account($accountId);
        if ($account === null || (int) $account->lead_id !== $session->leadId || ! Eligibility::isEligibleStatus((int) $account->status)) {
            abort(404);
        }

        return $this->switchTo($session, $session->leadId, $accountId);
    }

    #[OA\Post(
        path: '/session/language',
        operationId: 'setLanguage',
        summary: 'Save the portal language of the client',
        tags: ['Session'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['language'], properties: [new OA\Property(property: 'language', type: 'string', enum: ['en', 'es'])],
        )),
        responses: [new OA\Response(response: 204, description: 'Saved')],
    )]
    public function language(Request $request): Response
    {
        $session = $this->session($request);
        $language = (string) $request->validate(['language' => ['required', 'in:en,es']])['language'];

        $this->settings->setLanguage($session->leadId, $language);

        return response()->noContent();
    }

    #[OA\Post(
        path: '/session/logout',
        operationId: 'logout',
        summary: 'Sign out of this device',
        tags: ['Session'],
        responses: [new OA\Response(response: 204, description: 'Signed out')],
    )]
    public function logout(Request $request): Response
    {
        $session = $this->session($request);

        $this->sessions->end($session, 'logout');
        $this->events->record('logout', $session->id, $session->leadId, $session->accountId);

        return response()->noContent()->withCookie(PortalCookie::forget());
    }

    private function switchTo(PortalSession $session, int $leadId, string $accountId): JsonResponse
    {
        $this->sessions->switchTo($session, $leadId, $accountId);
        $this->events->record('account_switched', $session->id, $leadId, $accountId);

        return response()->json($this->payload($this->sessions->findById($session->id)));
    }

    /** @return array<string, mixed> */
    private function payload(PortalSession $session): array
    {
        $lead = $this->directory->lead($session->leadId);
        $account = $this->directory->account($session->accountId);
        $first = ucwords(mb_strtolower(trim((string) ($lead->first_name ?? ''))));
        $last = ucwords(mb_strtolower(trim((string) ($lead->last_name ?? ''))));

        return [
            'client' => [
                'lead_id' => $session->leadId,
                'first_name' => $first,
                'full_name' => trim("{$first} {$last}"),
                'initials' => mb_strtoupper(mb_substr($first, 0, 1).mb_substr($last, 0, 1)),
            ],
            'account' => [
                'id' => $session->accountId,
                'number' => (string) ($account->account ?? ''),
            ],
            'accounts' => array_map(fn (\stdClass $a): array => [
                'id' => (string) $a->id,
                'number' => (string) $a->account,
            ], $this->directory->eligibleAccountsOf($session->leadId)),
            'shared_phone' => $session->isSharedPhone(),
            'language' => $this->settings->language($session->leadId),
        ];
    }

    private function session(Request $request): PortalSession
    {
        $session = $request->attributes->get('portal_session');

        return $session instanceof PortalSession ? $session : abort(401);
    }
}
