<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Http\Controllers;

use App\Modules\ClientPortal\Application\StepUp;
use App\Modules\ClientPortal\Domain\LoginException;
use App\Modules\ClientPortal\Domain\Phone;
use App\Modules\ClientPortal\Http\CurrentSession;
use App\Modules\ClientPortal\Infrastructure\PortalSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

// Quick security check: envía y valida el código antes de cambiar tarjetas.
final class SecurityController
{
    public function __construct(private readonly StepUp $stepUp) {}

    #[OA\Post(path: '/security/code', operationId: 'sendSecurityCode', summary: 'Send a security check code to the session phone', tags: ['Cards'], responses: [
        new OA\Response(response: 200, description: 'Sent', content: new OA\JsonContent(required: ['challenge_id', 'destination', 'resend_available_at'], properties: [
            new OA\Property(property: 'challenge_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'destination', type: 'string', example: '***-***-7392'),
            new OA\Property(property: 'resend_available_at', type: 'string', format: 'date-time'),
        ])),
        new OA\Response(response: 423, description: 'Locked', content: new OA\JsonContent(ref: '#/components/schemas/LoginError')),
        new OA\Response(response: 429, description: 'Too soon', content: new OA\JsonContent(ref: '#/components/schemas/LoginError')),
    ])]
    public function send(Request $request, PortalSettings $settings): JsonResponse
    {
        $session = CurrentSession::of($request);

        try {
            $sent = $this->stepUp->send($session, $settings->language($session->leadId) ?? 'en', $request->ip());
        } catch (LoginException $e) {
            return response()->json(['code' => $e->reason, 'message' => $e->getMessage(), 'until' => $e->until?->toIso8601String()], $e->status);
        }

        return response()->json([
            'challenge_id' => $sent['challenge_id'],
            'destination' => Phone::mask($session->mobileAtLogin),
            'resend_available_at' => $sent['resend_available_at']->toIso8601String(),
        ]);
    }

    #[OA\Post(path: '/security/verify', operationId: 'verifySecurityCode', summary: 'Validate the security check code', tags: ['Cards'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['challenge_id', 'code'], properties: [
            new OA\Property(property: 'challenge_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'code', type: 'string'),
        ])),
        responses: [
            new OA\Response(response: 204, description: 'Verified'),
            new OA\Response(response: 422, description: 'Incorrect code', content: new OA\JsonContent(ref: '#/components/schemas/LoginError')),
            new OA\Response(response: 423, description: 'Locked', content: new OA\JsonContent(ref: '#/components/schemas/LoginError')),
        ])]
    public function verify(Request $request): JsonResponse|Response
    {
        $data = $request->validate(['challenge_id' => ['required', 'uuid'], 'code' => ['required', 'digits:6']]);

        try {
            $this->stepUp->verify(CurrentSession::of($request), (string) $data['challenge_id'], (string) $data['code']);
        } catch (LoginException $e) {
            return response()->json(array_filter([
                'code' => $e->reason, 'message' => $e->getMessage(), 'attempts_left' => $e->attemptsLeft, 'until' => $e->until?->toIso8601String(),
            ], fn ($v) => $v !== null), $e->status);
        }

        return response()->noContent();
    }
}
