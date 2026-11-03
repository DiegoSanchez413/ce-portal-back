<?php

declare(strict_types=1);

use App\Modules\ClientPortal\Domain\PaymentHistory;

function paymentRow(array $values): stdClass
{
    return (object) ($values + ['transaction_id' => 'T', 'settlement_date' => '2026-01-01 10:00:00', 'type' => 'Monthly Payment', 'amount' => '59.00',
        'method_payment' => 'Card(XXXX2787)', 'type_transaction_id' => 1, 'status_transaction_id' => 1, 'modality_transaction_id' => 1, 'order_query_sp' => 1]);
}

it('clasifica concepto y estado de cada pago, del más reciente al más antiguo', function (): void {
    $items = PaymentHistory::fromRows([
        paymentRow(['transaction_id' => 'T1', 'settlement_date' => '2026-01-01 10:00:00']),
        paymentRow(['transaction_id' => 'T2', 'settlement_date' => '2026-02-01 10:00:00', 'status_transaction_id' => 2]),
        paymentRow(['transaction_id' => 'T3', 'settlement_date' => '2026-03-01 10:00:00', 'type' => 'Void', 'type_transaction_id' => 10]),
        paymentRow(['transaction_id' => 'T4', 'settlement_date' => '2026-04-01 10:00:00', 'type' => 'Refund', 'type_transaction_id' => 11, 'modality_transaction_id' => 3]),
    ]);

    expect(array_column($items, 'transaction_id'))->toBe(['T4', 'T3', 'T2', 'T1'])
        ->and(array_column($items, 'concept'))->toBe(['refund', 'void', 'monthly_payment', 'monthly_payment'])
        ->and(array_column($items, 'status'))->toBe(['refunded', 'voided', 'declined', 'approved'])
        ->and($items[3]['card_last4'])->toBe('2787')
        ->and($items[3]['method'])->toBe('card')
        ->and(array_column($items, 'receipt_id'))->toBe([null, null, null, 'T1']);
});

it('muestra el resumen de un año antiguo sin tarjeta y con el año como etiqueta', function (): void {
    [$item] = PaymentHistory::fromRows([
        paymentRow(['transaction_id' => ' YEAR 2014', 'settlement_date' => '2014-01-01 03:00:00', 'type' => 'Payments of year ', 'amount' => '719.92',
            'method_payment' => ' ', 'type_transaction_id' => 9, 'order_query_sp' => 5]),
    ]);

    expect($item)->toMatchArray(['concept' => 'yearly_total', 'receipt_id' => null, 'label' => '2014', 'method' => null, 'card_last4' => null, 'amount' => 719.92, 'plan_payment' => null]);
});

it('reconoce el pago inicial en efectivo que llega sin concepto', function (): void {
    [$item] = PaymentHistory::fromRows([
        paymentRow(['transaction_id' => 'Cash', 't_id' => 'uuid-cash', 'type' => null, 'method_payment' => 'Cash', 'type_transaction_id' => 3, 'modality_transaction_id' => 2, 'order_query_sp' => 2]),
    ]);

    expect($item)->toMatchArray(['concept' => 'initial_payment', 'method' => 'cash', 'card_last4' => null, 'status' => 'approved', 'plan_payment' => 1,
        'transaction_id' => null, 'receipt_id' => 'uuid-cash']);
});
