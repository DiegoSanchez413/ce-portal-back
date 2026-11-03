<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Http\Controllers;

use App\Modules\ClientPortal\Domain\Names;
use App\Modules\ClientPortal\Domain\PaymentHistory;
use App\Modules\ClientPortal\Http\CurrentSession;
use App\Modules\ClientPortal\Infrastructure\AccountData;
use App\Modules\ClientPortal\Infrastructure\ClientDirectory;
use App\Modules\ClientPortal\Infrastructure\EventLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

// Resumen de pagos, historial de Transaction Records y datos del recibo de cada pago aprobado.
final class PaymentsController
{
    public function __construct(private readonly AccountData $data) {}

    #[OA\Get(path: '/payments', operationId: 'getPayments', summary: 'Payment totals and history', tags: ['Account'], responses: [
        new OA\Response(response: 200, description: 'Payments', content: new OA\JsonContent(ref: '#/components/schemas/Payments')),
    ])]
    public function index(Request $request): JsonResponse
    {
        $session = CurrentSession::of($request);
        $balance = $this->data->balance($session->accountId);
        $paid = round((float) ($balance->total_payment ?? 0), 2);
        $total = round((float) ($balance->total_charged ?? 0), 2);
        $plan = $this->data->plan($session->accountId);

        return response()->json([
            'plan_name' => $plan?->plan_name,
            'monthly_amount' => round((float) ($plan->rb_monthly ?? $plan->plan_monthly ?? 0), 2),
            'plan_total' => $total,
            'paid' => $paid,
            'remaining' => max(0, round($total - $paid, 2)),
            'history' => PaymentHistory::fromRows($this->data->payments($session->accountId)),
        ]);
    }

    #[OA\Post(path: '/payments/{transactionId}/receipt', operationId: 'getReceipt', summary: 'Receipt data of an approved payment', tags: ['Account'],
        parameters: [new OA\Parameter(name: 'transactionId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Receipt', content: new OA\JsonContent(ref: '#/components/schemas/Receipt')),
            new OA\Response(response: 404, description: 'Not found'),
        ])]
    public function receipt(Request $request, string $transactionId, ClientDirectory $directory, EventLog $events): JsonResponse
    {
        $session = CurrentSession::of($request);
        $payment = collect(PaymentHistory::fromRows($this->data->payments($session->accountId)))
            ->first(fn (array $p): bool => $p['receipt_id'] !== null && $p['receipt_id'] === $transactionId);

        if ($payment === null) {
            abort(404);
        }

        $plan = $this->data->plan($session->accountId);
        $lead = $directory->lead($session->leadId);
        $account = $directory->account($session->accountId);
        $events->record('receipt_downloaded', $session->id, $session->leadId, $session->accountId,
            ['amount' => $payment['amount']], 'transaction', $transactionId);

        return response()->json($payment + [
            'plan' => $plan === null || trim((string) $plan->plan_name) === '' || (int) $plan->months <= 0 ? null
                : ['name' => (string) $plan->plan_name, 'total_payments' => (int) $plan->months],
            'client' => Names::full($lead->first_name ?? null, $lead->last_name ?? null),
            'account' => (string) ($account->account ?? ''),
        ]);
    }
}
