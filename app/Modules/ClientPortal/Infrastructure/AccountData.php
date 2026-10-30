<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Infrastructure;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Lecturas de solo lectura de la cuenta Credit Experts en las tablas y SP del Soft,
 * con los mismos criterios que el Client Dashboard.
 */
class AccountData
{
    private const UPCOMING_APPOINTMENTS = ['SCHEDULED', 'RESCHEDULED', 'REMINDED'];

    private const LEGACY_LETTER_SENT = 7;

    private const LEGACY_LETTER_TYPES = [1, 2];

    // Plan vigente (renovación o plan de la venta), mensualidad del recurrente y meses pagados.
    public function plan(string $accountId): ?stdClass
    {
        return DB::selectOne(<<<'SQL'
            SELECT ca.status,
                   COALESCE(JSON_UNQUOTE(JSON_EXTRACT(pr.new_plan_info, '$.name')), p.name) AS plan_name,
                   COALESCE(JSON_UNQUOTE(JSON_EXTRACT(pr.new_plan_info, '$.months')), p.months) AS months,
                   COALESCE(JSON_UNQUOTE(JSON_EXTRACT(pr.new_plan_info, '$.monthly_amount')), p.monthly_amount) AS plan_monthly,
                   rb.monthly_amount AS rb_monthly,
                   (SELECT COUNT(DISTINCT DATE_FORMAT(t.settlement_date, '%Y-%m'))
                      FROM transactions t
                     WHERE t.client_acount_id = ca.id
                       AND t.modality_transaction_id IN (1, 2)
                       AND t.type_transaction_id NOT IN (8, 14, 16, 17)
                       AND t.status_transaction_id IN (1, 5, 8)
                       AND (pr.id IS NULL OR t.settlement_date >= pr.created_at)) AS paid_months
              FROM client_accounts ca
              LEFT JOIN sales s ON s.client_id = ca.client_id AND s.program_id = ca.program_id AND s.status_id = 4
              LEFT JOIN plans p ON p.id = s.plan_id
              LEFT JOIN plan_renewals pr ON pr.client_account_id = ca.id AND pr.status = 'RENEWED' AND pr.updated_at IS NULL
              LEFT JOIN recurring_billings rb ON rb.client_acount_id = ca.id AND rb.updater_id IS NULL AND rb.updated_at IS NULL
             WHERE ca.id = ?
             LIMIT 1
            SQL, [$accountId]);
    }

    // Totales que mantiene el Soft por triggers (lo mismo que muestra Account Balance).
    public function balance(string $accountId): ?stdClass
    {
        return DB::table('client_balance_financial')
            ->where('client_account_id', $accountId)
            ->first(['total_payment', 'total_charged']);
    }

    /** @return array{date: CarbonImmutable, amount: float}|null próximo cobro automático con tarjeta */
    public function nextDebit(string $accountId): ?array
    {
        if ((bool) DB::table('client_accounts')->where('id', $accountId)->value('client_payment_paused')) {
            return null;
        }

        $charges = DB::table('recurring_billings as rb')
            ->leftJoin('recurring_billing_detail as rbd', fn ($join) => $join->on('rbd.recurring_billing_id', '=', 'rb.id')->where('rbd.status', 'ACTIVE'))
            ->where('rb.client_acount_id', $accountId)
            ->whereNull('rb.updated_at')
            ->whereNull('rb.updater_id')
            ->where('rb.status', 'ACTIVE')
            ->where('rb.method_payment', 0)
            ->where('rb.type_payment', 0)
            ->selectRaw('COALESCE(rbd.day_payment, rb.day_payment) AS day_payment, COALESCE(rbd.monthly_amount, rb.monthly_amount) AS amount')
            ->get();

        $next = null;
        foreach ($charges as $charge) {
            if ((int) $charge->day_payment < 1 || (float) $charge->amount <= 0) {
                continue;
            }
            $date = $this->nextChargeDate((int) $charge->day_payment);
            if ($next === null || $date->lt($next['date'])) {
                $next = ['date' => $date, 'amount' => round((float) $charge->amount, 2)];
            }
        }

        return $next;
    }

    /**
     * @param  list<string>  $accountIds
     * @return list<stdClass> Credit Reports del cliente (todas sus cuentas), del más reciente al más antiguo
     */
    public function creditReports(int $leadId, array $accountIds): array
    {
        return array_values(DB::table('ncr_requests as nr')
            ->join('ncr_request_details as nrd', fn ($join) => $join->on('nrd.ncr_request_id', '=', 'nr.id')->where('nrd.status', 1))
            ->join('scores as s', 's.id', '=', 'nrd.score_id')
            ->where(fn ($q) => $q->whereIn('nr.client_account_id', $accountIds)->orWhere('nr.lead_id', $leadId))
            ->where('nr.status', 'COMPLETED')
            ->orderByDesc('nr.created_at')
            ->get(['s.id as score_id', 's.equifax', 's.experian', 's.transunion', 's.date', 's.route_pdf', 'nr.created_at'])
            ->unique('score_id')
            ->values()
            ->all());
    }

    /** @param list<string> $accountIds */
    public function nextAppointment(array $accountIds): ?stdClass
    {
        return DB::table('appointment_requests as ar')
            ->leftJoin('users as sp', 'sp.id', '=', 'ar.specialist_id')
            ->whereIn('ar.client_account_id', $accountIds)
            ->whereIn('ar.status', self::UPCOMING_APPOINTMENTS)
            ->whereRaw('DATE_ADD(ar.appointment_datetime, INTERVAL IFNULL(ar.appointment_duration, 0) MINUTE) >= NOW()')
            ->orderBy('ar.appointment_datetime')
            ->first(['ar.appointment_datetime', 'sp.first_name', 'sp.last_name']);
    }

    /**
     * @param  list<string>  $accountIds
     * @return array{number: int, sent_at: CarbonImmutable}|null última ronda enviada a los burós (Letter Bank o legacy)
     */
    public function lastRound(array $accountIds): ?array
    {
        $letterBank = DB::table('letter_bank_correspondence as lbc')
            ->join('letter_bank_rounds as lbr', 'lbr.id', '=', 'lbc.round_id')
            ->join('letter_bank_plans as lbp', 'lbp.id', '=', 'lbr.plan_id')
            ->whereIn('lbp.client_account_id', $accountIds)
            ->where('lbc.status', 'SENT')
            ->orderByDesc('lbc.sent_at')
            ->first(['lbc.sent_at', 'lbr.number']);

        $legacy = DB::table('ncr_round_letters')
            ->whereIn('client_account_id', $accountIds)
            ->where('state', self::LEGACY_LETTER_SENT)
            ->whereIn('type_send', self::LEGACY_LETTER_TYPES)
            ->whereNull('deleted_at')
            ->selectRaw('MAX(date_send) AS sent_at, COUNT(DISTINCT DATE(date_send)) AS rounds')
            ->first();

        $candidates = [];
        if ($letterBank !== null && $letterBank->sent_at !== null) {
            $candidates[] = ['number' => (int) $letterBank->number, 'sent_at' => CarbonImmutable::parse((string) $letterBank->sent_at)];
        }
        if ($legacy !== null && $legacy->sent_at !== null) {
            $candidates[] = ['number' => (int) $legacy->rounds, 'sent_at' => CarbonImmutable::parse((string) $legacy->sent_at)];
        }

        usort($candidates, fn (array $a, array $b): int => $b['sent_at'] <=> $a['sent_at']);

        return $candidates[0] ?? null;
    }

    /** @return list<stdClass> filas del panel Payments de Transaction Records */
    public function payments(string $accountId): array
    {
        return array_values(DB::select('CALL get_account_payments(?)', [$accountId]));
    }

    public function profile(int $leadId): ?stdClass
    {
        return DB::table('leads as l')
            ->leftJoin('states_eeuus as st', 'st.slug', '=', 'l.states_eeuu_slug')
            ->where('l.id', $leadId)
            ->first([
                'l.first_name', 'l.middle_name', 'l.last_name', 'l.mobile', 'l.email', 'l.street', 'l.street_address_2',
                'l.city', 'l.states_eeuu_slug', 'st.state as state_name', 'l.zipcode', 'l.dob', 'l.standard_time_zone',
            ]);
    }

    public function staff(string $accountId): ?stdClass
    {
        return DB::table('client_accounts as ca')
            ->leftJoin('users as sp', 'sp.id', '=', 'ca.specialist_id')
            ->leftJoin('users as ag', 'ag.id', '=', 'ca.agent_id')
            ->where('ca.id', $accountId)
            ->first([
                'sp.first_name as specialist_first', 'sp.last_name as specialist_last',
                'ag.first_name as agent_first', 'ag.last_name as agent_last',
            ]);
    }

    // El próximo día de cobro desde hoy; en un mes más corto, su último día.
    private function nextChargeDate(int $day): CarbonImmutable
    {
        $today = CarbonImmutable::today();
        $thisMonth = $today->day(min($day, $today->daysInMonth));

        if ($thisMonth->gte($today)) {
            return $thisMonth;
        }

        $nextMonth = $today->startOfMonth()->addMonth();

        return $nextMonth->day(min($day, $nextMonth->daysInMonth));
    }
}
