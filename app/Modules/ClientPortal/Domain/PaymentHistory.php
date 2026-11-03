<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Domain;

use Carbon\CarbonImmutable;
use stdClass;

/**
 * Pasa las filas de get_account_payments al historial del portal: concepto, estado,
 * tarjeta enmascarada y el número de pago del plan que cubre cada mensualidad.
 */
final class PaymentHistory
{
    private const APPROVED = [1, 5, 8];

    private const DECLINED = [2, 3, 6, 7, 20, 22];

    private const VOID = 10;

    private const REFUND = 11;

    // Fila resumen del panel Payments con los pagos de un año antiguo.
    private const YEARLY_TOTAL = 5;

    private const MODALITY_MONTHLY = 1;

    private const MODALITY_INITIAL = 2;

    /**
     * @param  list<stdClass>  $rows
     * @return list<array{transaction_id: string|null, receipt_id: string|null, date: string, concept: string, label: string, amount: float, method: string|null, card_last4: string|null, status: string, plan_payment: int|null}>
     */
    public static function fromRows(array $rows): array
    {
        usort($rows, fn (stdClass $a, stdClass $b): int => strcmp((string) $a->settlement_date, (string) $b->settlement_date));

        $paidMonths = [];
        $items = [];

        foreach ($rows as $row) {
            $status = self::status($row);
            $concept = self::concept($row);
            $planPayment = null;

            if ($status === 'approved' && in_array($concept, ['initial_payment', 'monthly_payment'], true)) {
                $paidMonths[substr((string) $row->settlement_date, 0, 7)] = true;
                $planPayment = count($paidMonths);
            }

            $method = $concept === 'yearly_total' ? null : self::method((string) $row->method_payment);
            $transactionId = $method === 'card' ? (string) ($row->transaction_id ?? '') : null;
            $receiptId = $status !== 'approved' || $concept === 'yearly_total' ? null
                : ($transactionId !== null && $transactionId !== '' ? $transactionId : (isset($row->t_id) ? (string) $row->t_id : null));

            $items[] = [
                'transaction_id' => $transactionId === '' ? null : $transactionId,
                'receipt_id' => $receiptId,
                'date' => CarbonImmutable::parse((string) $row->settlement_date)->toDateString(),
                'concept' => $concept,
                'label' => $concept === 'yearly_total' ? substr((string) $row->settlement_date, 0, 4) : trim((string) $row->type),
                'amount' => abs(round((float) $row->amount, 2)),
                'method' => $method,
                'card_last4' => $concept === 'yearly_total' ? null : (preg_match('/(\d{4})\D*$/', (string) $row->method_payment, $m) === 1 ? $m[1] : null),
                'status' => $status,
                'plan_payment' => $planPayment,
            ];
        }

        return array_reverse($items);
    }

    // Medio de pago tal como lo escribe get_account_payments: Card(…)/MA(…), Cash, Check o MO.
    private static function method(string $method): ?string
    {
        $method = strtolower(trim($method));

        return match (true) {
            str_starts_with($method, 'card') || str_starts_with($method, 'ma(') => 'card',
            $method === 'cash' => 'cash',
            $method === 'check' => 'check',
            $method === 'mo' => 'money_order',
            default => null,
        };
    }

    private static function concept(stdClass $row): string
    {
        $type = strtolower(trim((string) $row->type));

        return match (true) {
            (int) ($row->order_query_sp ?? 0) === self::YEARLY_TOTAL => 'yearly_total',
            $type === 'void' => 'void',
            $type === 'refund' => 'refund',
            str_starts_with($type, 'initial payment') => 'initial_payment',
            str_starts_with($type, 'monthly payment') => 'monthly_payment',
            (int) ($row->order_query_sp ?? 0) === 4 || (int) ($row->modality_transaction_id ?? 0) === 4 => 'charge',
            $type === '' && (int) ($row->modality_transaction_id ?? 0) === self::MODALITY_INITIAL => 'initial_payment',
            $type === '' && (int) ($row->modality_transaction_id ?? 0) === self::MODALITY_MONTHLY => 'monthly_payment',
            default => 'other',
        };
    }

    private static function status(stdClass $row): string
    {
        $type = (int) ($row->type_transaction_id ?? 0);
        $status = (int) ($row->status_transaction_id ?? 0);

        return match (true) {
            $type === self::VOID => 'voided',
            $type === self::REFUND => 'refunded',
            in_array($status, self::APPROVED, true) => 'approved',
            in_array($status, self::DECLINED, true) => 'declined',
            default => 'other',
        };
    }
}
