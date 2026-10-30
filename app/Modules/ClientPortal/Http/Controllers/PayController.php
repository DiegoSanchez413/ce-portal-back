<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Http\Controllers;

use App\Modules\ClientPortal\Application\PaymentDue;
use App\Modules\ClientPortal\Application\StepUp;
use App\Modules\ClientPortal\Domain\CardView;
use App\Modules\ClientPortal\Domain\SoftRequestFailed;
use App\Modules\ClientPortal\Http\CurrentSession;
use App\Modules\ClientPortal\Infrastructure\ClientDirectory;
use App\Modules\ClientPortal\Infrastructure\EventLog;
use App\Modules\ClientPortal\Infrastructure\PortalSession;
use App\Modules\ClientPortal\Infrastructure\SoftCards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use stdClass;

/**
 * Pay now: el monto lo fija el servidor y cada intento lleva una clave única; un reintento
 * con la misma clave devuelve el resultado guardado y nunca cobra dos veces.
 */
final class PayController
{
    public function __construct(
        private readonly PaymentDue $due,
        private readonly SoftCards $cards,
        private readonly ClientDirectory $directory,
        private readonly EventLog $events,
    ) {}

    #[OA\Get(path: '/pay', operationId: 'getPayNow', summary: 'Amount due and cards to pay with', tags: ['Pay'], responses: [
        new OA\Response(response: 200, description: 'Pay now', content: new OA\JsonContent(ref: '#/components/schemas/PayNow')),
    ])]
    public function show(Request $request): JsonResponse
    {
        $session = CurrentSession::of($request);
        $due = $this->due->for($session->accountId, $this->status($session));

        try {
            $cards = array_map([CardView::class, 'from'], $this->cards->list($session->accountId)['cards']);
        } catch (SoftRequestFailed $e) {
            return response()->json(['code' => 'soft_error', 'message' => $e->getMessage()], $e->status);
        }

        $default = collect($cards)->first(fn (array $c): bool => $c['is_default']);
        $preselected = $default !== null && ! $default['expired'] ? $default : collect($cards)->first(fn (array $c): bool => ! $c['expired']);

        return response()->json([
            'payable' => $due !== null,
            'amount' => $due['amount'] ?? 0,
            'count' => $due['count'] ?? null,
            'monthly_amount' => $due['monthly_amount'] ?? null,
            'cards' => $cards,
            'preselected_card_id' => $preselected['id'] ?? null,
            'default_card_expired' => $default !== null && $default['expired'],
        ]);
    }

    #[OA\Post(path: '/pay', operationId: 'payNow', summary: 'Charge the amount due', tags: ['Pay'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['idempotency_key'], properties: [
            new OA\Property(property: 'idempotency_key', type: 'string', format: 'uuid'),
            new OA\Property(property: 'card_id', type: 'integer', nullable: true),
            new OA\Property(property: 'new_card', ref: '#/components/schemas/NewCard', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Approved or declined', content: new OA\JsonContent(ref: '#/components/schemas/PaymentResult')),
            new OA\Response(response: 403, description: 'Security check required for a new card', content: new OA\JsonContent(ref: '#/components/schemas/LoginError')),
            new OA\Response(response: 409, description: 'Nothing to pay or payment in progress'),
        ])]
    public function pay(Request $request, StepUp $stepUp): JsonResponse
    {
        $session = CurrentSession::of($request);
        $key = (string) $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'card_id' => ['required_without:new_card', 'nullable', 'integer'],
            'new_card' => ['required_without:card_id', 'nullable', 'array'],
        ])['idempotency_key'];

        $previous = DB::table('client_portal_payment_attempts')->where('idempotency_key', $key)->first();
        if ($previous !== null) {
            return $previous->status === 'processing'
                ? response()->json(['code' => 'in_progress', 'message' => 'Your payment is being processed.'], 409)
                : response()->json($this->result($previous));
        }

        $due = $this->due->for($session->accountId, $this->status($session));
        if ($due === null) {
            return response()->json(['code' => 'nothing_due', 'message' => 'There is nothing to pay right now.'], 409);
        }

        try {
            $cardId = $request->filled('new_card') ? $this->addCard($request, $session, $stepUp) : (int) $request->input('card_id');
            if ($cardId === null) {
                return response()->json(['code' => 'step_up_required', 'message' => 'Please confirm the security code.'], 403);
            }

            $card = collect($this->cards->list($session->accountId)['cards'])->firstWhere('id', $cardId);
            if ($card === null || CardView::from($card)['expired']) {
                return response()->json(['code' => 'invalid_card', 'message' => 'Please choose an active card.'], 422);
            }

            $attemptId = DB::table('client_portal_payment_attempts')->insertGetId([
                'idempotency_key' => $key, 'session_id' => $session->id, 'lead_id' => $session->leadId,
                'client_account_id' => $session->accountId, 'card_id' => $cardId, 'amount' => $due['amount'],
                'status' => 'processing', 'created_at' => now(), 'updated_at' => now(),
            ]);

            $charge = $this->cards->charge($session->accountId, $cardId, $due['amount'], $key);
        } catch (SoftRequestFailed $e) {
            if (isset($attemptId)) {
                DB::table('client_portal_payment_attempts')->where('id', $attemptId)->update(['status' => 'error', 'message' => mb_substr($e->getMessage(), 0, 255), 'updated_at' => now()]);
            }

            return response()->json(['code' => 'soft_error', 'message' => $e->getMessage()], $e->status);
        }

        $status = in_array($charge['status'], ['approved', 'declined'], true) ? $charge['status'] : 'error';

        DB::transaction(function () use ($attemptId, $status, $charge, $session, $due, $card): void {
            DB::table('client_portal_payment_attempts')->where('id', $attemptId)->update([
                'status' => $status, 'transaction_id' => $charge['transaction_id'], 'message' => mb_substr($charge['message'], 0, 255), 'updated_at' => now(),
            ]);
            $this->events->record($status === 'approved' ? 'payment_made' : 'payment_failed', $session->id, $session->leadId, $session->accountId,
                ['amount' => $due['amount'], 'brand' => $card['brand'], 'result' => $status], 'transaction', $charge['transaction_id']);
        });

        return response()->json($this->result(DB::table('client_portal_payment_attempts')->where('id', $attemptId)->first() ?? new stdClass) + [
            'card' => ['brand' => $card['brand'], 'last4' => $card['last4']],
            'paid_at' => $charge['paid_at'],
        ]);
    }

    private function addCard(Request $request, PortalSession $session, StepUp $stepUp): ?int
    {
        if (! $stepUp->isFresh($session)) {
            return null;
        }

        $data = CardsController::validateCard($request, 'new_card.');
        $card = $this->cards->add($session->accountId, $data['card'], (bool) $request->boolean('new_card.set_default'));
        $this->events->record('card_added', $session->id, $session->leadId, $session->accountId, ['brand' => $card['brand']], 'card', (string) $card['id']);

        return $card['id'];
    }

    /** @return array{status: string, amount: float, transaction_id: string|null, message: string|null} */
    private function result(stdClass $attempt): array
    {
        return [
            'status' => (string) $attempt->status,
            'amount' => round((float) $attempt->amount, 2),
            'transaction_id' => $attempt->transaction_id,
            'message' => $attempt->message,
        ];
    }

    private function status(PortalSession $session): int
    {
        return (int) ($this->directory->account($session->accountId)->status ?? 0);
    }
}
