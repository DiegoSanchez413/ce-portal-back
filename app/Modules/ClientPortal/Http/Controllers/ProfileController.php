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
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

// Perfil de solo lectura (la fecha de nacimiento solo con el año) y solicitud de cambio al Agent.
final class ProfileController
{
    private const FIELDS = ['mobile' => 'Mobile number', 'email' => 'Email', 'address' => 'Address'];

    private const INVALID = ['mobile' => 'Enter a valid mobile number.', 'email' => 'Enter a valid email.', 'address' => 'Enter the new address.'];

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
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['changes'], properties: [
            new OA\Property(property: 'changes', type: 'array', minItems: 1, maxItems: 3, items: new OA\Items(required: ['field', 'value'], properties: [
                new OA\Property(property: 'field', type: 'string', enum: ['mobile', 'email', 'address']),
                new OA\Property(property: 'value', type: 'string', maxLength: 200),
            ])),
        ])),
        responses: [new OA\Response(response: 204, description: 'Agent notified')])]
    public function changeRequest(Request $request, ClientRequests $requests): Response
    {
        $data = $request->validate([
            'changes' => ['required', 'array', 'min:1', 'max:3'],
            'changes.*.field' => ['required', 'distinct', 'in:'.implode(',', array_keys(self::FIELDS))],
            'changes.*.value' => ['required', 'string', 'max:200'],
        ]);

        // Cada dato se valida según su tipo y se guarda ya normalizado; el aviso al Agent los lista todos.
        $changes = [];
        foreach ($data['changes'] as $i => $change) {
            $field = (string) $change['field'];
            $value = trim((string) $change['value']);
            $value = match ($field) {
                'mobile' => Phone::format($value),
                'email' => filter_var($value, FILTER_VALIDATE_EMAIL) === false ? null : mb_strtolower($value),
                default => $value === '' ? null : $value,
            };
            if ($value === null) {
                throw ValidationException::withMessages(["changes.$i.value" => self::INVALID[$field]]);
            }
            $changes[] = ['field' => $field, 'value' => $value];
        }

        $requests->create(CurrentSession::of($request), 'data_change', 'agent', ['changes' => $changes],
            implode(' · ', array_map(fn (array $c): string => self::FIELDS[$c['field']].': '.$c['value'], $changes)));

        return response()->noContent();
    }
}
