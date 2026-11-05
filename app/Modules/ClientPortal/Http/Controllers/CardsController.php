<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Http\Controllers;

use App\Modules\ClientPortal\Application\StepUp;
use App\Modules\ClientPortal\Domain\CardView;
use App\Modules\ClientPortal\Domain\SoftRequestFailed;
use App\Modules\ClientPortal\Http\CurrentSession;
use App\Modules\ClientPortal\Infrastructure\EventLog;
use App\Modules\ClientPortal\Infrastructure\PortalSession;
use App\Modules\ClientPortal\Infrastructure\SoftCards;
use App\Modules\Shared\Soft\SoftGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * My cards: listar, agregar y elegir la tarjeta del cobro automático. Agregar y cambiar
 * piden el security check reciente; cada cambio queda en eventos y avisa al Agent.
 */
final class CardsController
{
    public function __construct(
        private readonly SoftCards $cards,
        private readonly StepUp $stepUp,
        private readonly EventLog $events,
    ) {}

    #[OA\Get(path: '/cards', operationId: 'listCards', summary: 'Active cards of the client', tags: ['Cards'], responses: [
        new OA\Response(response: 200, description: 'Cards', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Card'))),
    ])]
    public function index(Request $request): JsonResponse|Response
    {
        $session = CurrentSession::of($request);

        return $this->guard(fn (): JsonResponse => response()->json(array_map(
            [CardView::class, 'from'],
            $this->cards->list($session->accountId)['cards'],
        )));
    }

    #[OA\Post(path: '/cards', operationId: 'addCard', summary: 'Save a new card (security check required)', tags: ['Cards'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/NewCard')),
        responses: [
            new OA\Response(response: 201, description: 'Saved', content: new OA\JsonContent(ref: '#/components/schemas/Card')),
            new OA\Response(response: 403, description: 'Security check required', content: new OA\JsonContent(ref: '#/components/schemas/LoginError')),
            new OA\Response(response: 422, description: 'Invalid card'),
        ])]
    public function store(Request $request, SoftGateway $soft): JsonResponse|Response
    {
        $session = CurrentSession::of($request);
        if (! $this->stepUp->isFresh($session)) {
            return $this->stepUpRequired();
        }

        $data = self::validateCard($request);

        return $this->guard(function () use ($session, $data, $soft): JsonResponse {
            $card = $this->cards->add($session->accountId, $data['card'], $data['set_default']);
            $this->events->record('card_added', $session->id, $session->leadId, $session->accountId,
                ['brand' => $card['brand'], 'default' => $data['set_default']], 'card', (string) $card['id']);

            if ($data['set_default'] && $card['is_default']) {
                $this->afterDefaultChanged($session, $card['id'], $card['last4'], $soft);
            }

            return response()->json(CardView::from($card), 201);
        });
    }

    #[OA\Post(path: '/cards/{id}/default', operationId: 'setDefaultCard', summary: 'Use this card for monthly payments (security check required)', tags: ['Cards'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Changed'),
            new OA\Response(response: 403, description: 'Security check required', content: new OA\JsonContent(ref: '#/components/schemas/LoginError')),
        ])]
    public function setDefault(Request $request, int $id, SoftGateway $soft): JsonResponse|Response
    {
        $session = CurrentSession::of($request);
        if (! $this->stepUp->isFresh($session)) {
            return $this->stepUpRequired();
        }

        return $this->guard(function () use ($session, $id, $soft) {
            $card = collect($this->cards->list($session->accountId)['cards'])->firstWhere('id', $id);
            if ($card === null || CardView::from($card)['expired']) {
                abort(404);
            }

            $this->cards->setDefault($session->accountId, $id);
            $this->afterDefaultChanged($session, $id, $card['last4'], $soft);

            return response()->noContent();
        });
    }

    #[OA\Post(path: '/cards/scan', operationId: 'scanCard', summary: 'Read card number, name and expiration from a photo', tags: ['Cards'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['image'], properties: [
            new OA\Property(property: 'image', type: 'string', description: 'Base64 data URL (JPG or PNG)'),
        ])),
        responses: [new OA\Response(response: 200, description: 'Read', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'cardholdername', type: 'string', nullable: true),
            new OA\Property(property: 'cardnumber', type: 'string', nullable: true),
            new OA\Property(property: 'exp_month', type: 'integer', nullable: true),
            new OA\Property(property: 'exp_year', type: 'integer', nullable: true),
        ]))])]
    public function scan(Request $request): JsonResponse|Response
    {
        CurrentSession::of($request);
        $image = (string) $request->validate(['image' => ['required', 'string', 'max:8000000', 'regex:#^data:image/(jpeg|png);base64,#']])['image'];
        $mime = str_starts_with($image, 'data:image/png') ? 'image/png' : 'image/jpeg';

        return $this->guard(fn (): JsonResponse => response()->json($this->cards->scan($image, $mime)));
    }

    /** @return array{card: array{cardholdername: string, cardnumber: string, cardsecuritycode: string, card_expi_month: string, card_expi_year: string, zipcode: string}, set_default: bool} */
    public static function validateCard(Request $request, string $prefix = ''): array
    {
        $data = $request->validate([
            $prefix.'cardholdername' => ['required', 'string', 'max:100'],
            $prefix.'cardnumber' => ['required', 'string', 'regex:/^[\d ]{13,23}$/'],
            $prefix.'cvc' => ['required', 'digits_between:3,4'],
            $prefix.'exp_month' => ['required', 'integer', 'between:1,12'],
            $prefix.'exp_year' => ['required', 'integer', 'between:'.(int) now()->format('y').',99'],
            $prefix.'zipcode' => ['required', 'digits:5'],
            'set_default' => ['sometimes', 'boolean'],
        ]);
        $get = fn (string $key): mixed => data_get($data, rtrim($prefix, '.') === '' ? $key : rtrim($prefix, '.').'.'.$key);

        return [
            'card' => [
                'cardholdername' => trim((string) $get('cardholdername')),
                'cardnumber' => (string) preg_replace('/\D/', '', (string) $get('cardnumber')),
                'cardsecuritycode' => (string) $get('cvc'),
                'card_expi_month' => sprintf('%02d', (int) $get('exp_month')),
                'card_expi_year' => sprintf('%02d', (int) $get('exp_year')),
                'zipcode' => (string) $get('zipcode'),
            ],
            'set_default' => (bool) ($data['set_default'] ?? false),
        ];
    }

    private function afterDefaultChanged(PortalSession $session, int $cardId, string $last4, SoftGateway $soft): void
    {
        $this->events->record('card_set_default', $session->id, $session->leadId, $session->accountId, [], 'card', (string) $cardId);

        $soft->post('notifications', [
            'client_account_id' => $session->accountId,
            'recipient' => 'agent',
            'text' => "<b>Client Portal</b>: Client changed the payment card to •••• {$last4}",
        ]);
    }

    private function stepUpRequired(): JsonResponse
    {
        return response()->json(['code' => 'step_up_required', 'message' => 'Please confirm the security code.'], 403);
    }

    /** @param callable(): (JsonResponse|Response) $action */
    private function guard(callable $action): JsonResponse|Response
    {
        try {
            return $action();
        } catch (SoftRequestFailed $e) {
            return response()->json(['code' => 'soft_error', 'message' => $e->getMessage()], $e->status);
        }
    }
}
