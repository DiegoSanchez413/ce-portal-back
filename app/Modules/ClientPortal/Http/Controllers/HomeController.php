<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Http\Controllers;

use App\Modules\ClientPortal\Application\ClientRequests;
use App\Modules\ClientPortal\Application\PaymentDue;
use App\Modules\ClientPortal\Domain\CardView;
use App\Modules\ClientPortal\Domain\ClientTime;
use App\Modules\ClientPortal\Domain\DocumentStatus;
use App\Modules\ClientPortal\Domain\Names;
use App\Modules\ClientPortal\Http\CurrentSession;
use App\Modules\ClientPortal\Infrastructure\AccountData;
use App\Modules\ClientPortal\Infrastructure\ClientDirectory;
use App\Modules\ClientPortal\Infrastructure\ClientFiles;
use App\Modules\ClientPortal\Infrastructure\SoftCards;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;
use Throwable;

// Inicio: plan, scores, pagos, próxima cita y avance de las disputas en una sola respuesta.
final class HomeController
{
    private const LATE_MONTHS = [9 => 1, 10 => 2];

    private const LOYAL = [11, 12, 13];

    private const BUREAU_RESPONSE_DAYS = 45;

    public function __construct(
        private readonly AccountData $data,
        private readonly ClientDirectory $directory,
        private readonly PaymentDue $due,
        private readonly SoftCards $cards,
        private readonly ClientFiles $files,
    ) {}

    #[OA\Get(path: '/home', operationId: 'getHome', summary: 'Home cards', tags: ['Account'], responses: [
        new OA\Response(response: 200, description: 'Home', content: new OA\JsonContent(ref: '#/components/schemas/Home')),
    ])]
    public function show(Request $request): JsonResponse
    {
        $session = CurrentSession::of($request);
        $plan = $this->data->plan($session->accountId);
        $status = (int) ($this->directory->account($session->accountId)->status ?? 0);
        $monthly = round((float) ($plan->rb_monthly ?? $plan->plan_monthly ?? 0), 2);
        $accountIds = array_map(fn (\stdClass $a): string => (string) $a->id, $this->directory->accountsOf($session->leadId));
        $zone = ClientTime::zone($this->data->profile($session->leadId)->standard_time_zone ?? null);

        return response()->json([
            'plan' => $plan === null ? null : [
                'name' => (string) $plan->plan_name,
                'status' => in_array($status, self::LOYAL, true) ? 'service_completed' : 'active',
                'paid_payments' => min((int) $plan->paid_months, (int) $plan->months),
                'total_payments' => (int) $plan->months,
                'monthly_amount' => $monthly,
            ],
            'scores' => $this->scores($session->leadId, $accountIds),
            'payments' => $this->payments($session->accountId, $status, $monthly),
            'card_alert' => $this->cardAlert($session->accountId),
            'files' => $this->filesNeedingAttention($session->leadId),
            'appointment' => $this->appointment($accountIds, $zone),
            'dispute' => $this->dispute($accountIds),
        ]);
    }

    #[OA\Post(path: '/home/services-interest', operationId: 'servicesInterest', summary: "I'm interested in other services", tags: ['Account'], responses: [
        new OA\Response(response: 204, description: 'Specialist notified'),
    ])]
    public function servicesInterest(Request $request, ClientRequests $requests): Response
    {
        $requests->create(CurrentSession::of($request), 'services_interest', 'specialist');

        return response()->noContent();
    }

    /**
     * @param  list<string>  $accountIds
     * @return array<string, mixed>|null
     */
    private function scores(int $leadId, array $accountIds): ?array
    {
        $reports = $this->data->creditReports($leadId, $accountIds);
        if ($reports === []) {
            return null;
        }

        $latest = $reports[0];
        $first = $reports[count($reports) - 1];
        $bureaus = [];
        foreach (['equifax', 'experian', 'transunion'] as $bureau) {
            $score = $latest->{$bureau} !== null ? (int) $latest->{$bureau} : null;
            $base = $first->{$bureau} !== null ? (int) $first->{$bureau} : null;
            $bureaus[$bureau] = ['score' => $score, 'delta' => $score !== null && $base !== null ? $score - $base : null];
        }

        return ['date' => CarbonImmutable::parse((string) ($latest->date ?? $latest->created_at))->toDateString()] + $bureaus;
    }

    /** @return array<string, mixed> */
    private function payments(string $accountId, int $status, float $monthly): array
    {
        if (in_array($status, self::LOYAL, true)) {
            return ['state' => 'loyal', 'amount_due' => $this->due->for($accountId, $status)['amount'] ?? null];
        }

        if (isset(self::LATE_MONTHS[$status])) {
            $count = self::LATE_MONTHS[$status];

            return ['state' => 'late', 'pending_count' => $count, 'monthly_amount' => $monthly, 'amount_due' => round($count * $monthly, 2)];
        }

        $next = $this->data->nextDebit($accountId);

        return ['state' => 'current', 'next_date' => $next === null ? null : $next['date']->toDateString(), 'next_amount' => $next['amount'] ?? null];
    }

    /** @return list<array<string, mixed>> */
    private function filesNeedingAttention(int $leadId): array
    {
        $current = $this->files->currentDocuments($leadId);

        return array_values(array_filter(
            array_map(fn (int $type): array => DocumentStatus::of($type, $current[$type] ?? null), array_keys(DocumentStatus::TYPES)),
            [DocumentStatus::class, 'needsAttention'],
        ));
    }

    /** @return array{state: string, expiration: string}|null aviso de la tarjeta del cobro automático */
    private function cardAlert(string $accountId): ?array
    {
        try {
            $default = collect($this->cards->list($accountId)['cards'])->first(fn (array $c): bool => $c['is_default']);
        } catch (Throwable) {
            return null;
        }

        if ($default === null) {
            return null;
        }

        $card = CardView::from($default);

        return match (true) {
            $card['expired'] => ['state' => 'expired', 'expiration' => $card['expiration']],
            $card['expires_soon'] => ['state' => 'expires_soon', 'expiration' => $card['expiration']],
            default => null,
        };
    }

    /**
     * @param  list<string>  $accountIds
     * @return array{date: string, time: string, specialist: string}|null
     */
    private function appointment(array $accountIds, string $zone): ?array
    {
        $row = $this->data->nextAppointment($accountIds);
        if ($row === null) {
            return null;
        }

        $at = ClientTime::fromPacific((string) $row->appointment_datetime, $zone);

        return ['date' => $at->toDateString(), 'time' => $at->format('H:i'), 'specialist' => Names::short($row->first_name, $row->last_name)];
    }

    /**
     * @param  list<string>  $accountIds
     * @return array{round: int, sent_at: string, respond_by: string, window_closed: bool}|null
     */
    private function dispute(array $accountIds): ?array
    {
        $round = $this->data->lastRound($accountIds);
        if ($round === null) {
            return null;
        }

        $respondBy = $round['sent_at']->addDays(self::BUREAU_RESPONSE_DAYS)->startOfDay();

        return [
            'round' => max(1, $round['number']),
            'sent_at' => $round['sent_at']->toDateString(),
            'respond_by' => $respondBy->toDateString(),
            'window_closed' => $respondBy->lt(CarbonImmutable::today()),
        ];
    }
}
