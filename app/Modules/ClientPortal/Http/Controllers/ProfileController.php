<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Http\Controllers;

use App\Modules\ClientPortal\Application\ClientRequests;
use App\Modules\ClientPortal\Domain\Names;
use App\Modules\ClientPortal\Domain\Phone;
use App\Modules\ClientPortal\Http\CurrentSession;
use App\Modules\ClientPortal\Infrastructure\AccountData;
use App\Modules\ClientPortal\Infrastructure\ClientDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

// Perfil de solo lectura (la fecha de nacimiento solo con el año) y solicitud de cambio al Agent.
final class ProfileController
{
    private const FIELDS = ['mobile' => 'Mobile number', 'email' => 'Email', 'address' => 'Address', 'other' => 'Other'];

    #[OA\Get(path: '/profile', operationId: 'getProfile', summary: 'Client data on file', tags: ['Account'], responses: [
        new OA\Response(response: 200, description: 'Profile', content: new OA\JsonContent(ref: '#/components/schemas/Profile')),
    ])]
    public function show(Request $request, AccountData $data, ClientDirectory $directory): JsonResponse
    {
        $session = CurrentSession::of($request);
        $lead = $data->profile($session->leadId) ?? abort(404);
        $staff = $data->staff($session->accountId) ?? new \stdClass;
        $plan = $data->plan($session->accountId);
        $account = $directory->account($session->accountId);

        $street = trim(implode(' ', array_filter([trim((string) ($lead->street ?? '')), trim((string) ($lead->street_address_2 ?? ''))])));
        $address = implode(', ', array_filter([$street, trim((string) ($lead->city ?? '')), trim(trim((string) ($lead->states_eeuu_slug ?? '')).' '.trim((string) ($lead->zipcode ?? '')))]));

        return response()->json([
            'full_name' => Names::full($lead->first_name ?? null, $lead->last_name ?? null, $lead->middle_name ?? null),
            'account' => (string) ($account->account ?? ''),
            'mobile' => Phone::format((string) ($lead->mobile ?? '')),
            'email' => isset($lead->email) ? mb_strtolower((string) $lead->email) : null,
            'address' => $address === '' ? null : $address,
            'birth_year' => isset($lead->dob) ? (int) substr((string) $lead->dob, 0, 4) : null,
            'plan' => $plan?->plan_name,
            'specialist' => isset($staff->specialist_first) ? Names::short($staff->specialist_first, $staff->specialist_last ?? null) : null,
            'agent' => isset($staff->agent_first) ? Names::full($staff->agent_first, $staff->agent_last ?? null) : null,
        ]);
    }

    #[OA\Post(path: '/profile/change-request', operationId: 'requestDataChange', summary: 'Ask the agent to change client data', tags: ['Account'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['field', 'details'], properties: [
            new OA\Property(property: 'field', type: 'string', enum: ['mobile', 'email', 'address', 'other']),
            new OA\Property(property: 'details', type: 'string', maxLength: 500),
        ])),
        responses: [new OA\Response(response: 204, description: 'Agent notified')])]
    public function changeRequest(Request $request, ClientRequests $requests): Response
    {
        $data = $request->validate([
            'field' => ['required', 'in:'.implode(',', array_keys(self::FIELDS))],
            'details' => ['required', 'string', 'max:500'],
        ]);

        $requests->create(CurrentSession::of($request), 'data_change', 'agent',
            ['field' => (string) $data['field'], 'details' => (string) $data['details']],
            self::FIELDS[$data['field']].': '.mb_substr((string) $data['details'], 0, 80));

        return response()->noContent();
    }
}
