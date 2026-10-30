<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Application;

use App\Modules\ClientPortal\Infrastructure\AccountData;

/**
 * Monto fijo de Pay now: una mensualidad al día, los meses del status si está atrasada
 * y el saldo pendiente si es Loyal; nunca más que lo que falta del plan.
 */
final class PaymentDue
{
    private const LATE_MONTHS = [8 => 1, 9 => 1, 10 => 2];

    private const LOYAL = [11, 12, 13];

    public function __construct(private readonly AccountData $data) {}

    /** @return array{amount: float, count: int|null, monthly_amount: float|null}|null */
    public function for(string $accountId, int $status): ?array
    {
        $balance = $this->data->balance($accountId);
        $remaining = max(0, round((float) ($balance->total_charged ?? 0) - (float) ($balance->total_payment ?? 0), 2));

        if ($remaining <= 0) {
            return null;
        }

        if (in_array($status, self::LOYAL, true)) {
            return ['amount' => $remaining, 'count' => null, 'monthly_amount' => null];
        }

        $months = self::LATE_MONTHS[$status] ?? null;
        $plan = $this->data->plan($accountId);
        $monthly = round((float) ($plan->rb_monthly ?? $plan->plan_monthly ?? 0), 2);

        if ($months === null || $monthly <= 0) {
            return null;
        }

        $amount = round($months * $monthly, 2);

        return $amount <= $remaining
            ? ['amount' => $amount, 'count' => $months, 'monthly_amount' => $monthly]
            : ['amount' => $remaining, 'count' => null, 'monthly_amount' => null];
    }
}
