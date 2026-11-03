<?php

declare(strict_types=1);

use App\Modules\ClientPortal\Http\Controllers\CreditController;
use App\Modules\ClientPortal\Infrastructure\AccountData;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\SoftFixtures;

// Datos del Soft simulados: plan, saldo, reportes, pagos y perfil de una cuenta.
function fakeAccountData(array $overrides = []): void
{
    $data = new class($overrides) extends AccountData
    {
        public function __construct(private array $o) {}

        public function plan(string $accountId): ?stdClass
        {
            return (object) ($this->o['plan'] ?? ['status' => 8, 'plan_name' => 'LONG 12', 'months' => 12, 'plan_monthly' => 59, 'rb_monthly' => 59, 'paid_months' => 3]);
        }

        public function balance(string $accountId): ?stdClass
        {
            return (object) ['total_payment' => 177, 'total_charged' => 708];
        }

        public function nextDebit(string $accountId): ?array
        {
            return ['date' => CarbonImmutable::parse('2026-11-15'), 'amount' => 59.0];
        }

        public function creditReports(int $leadId, array $accountIds): array
        {
            return $this->o['reports'] ?? [
                (object) ['request_id' => 3, 'equifax' => 640, 'experian' => 600, 'transunion' => null, 'route_pdf' => '/SM/1/cr3.pdf', 'obtained_at' => '2026-10-10 09:00:00'],
                (object) ['request_id' => 2, 'equifax' => 630, 'experian' => 610, 'transunion' => 650, 'route_pdf' => '', 'obtained_at' => '2026-08-10 09:00:00'],
                (object) ['request_id' => 1, 'equifax' => 600, 'experian' => 590, 'transunion' => 640, 'route_pdf' => '/SM/1/cr1.pdf', 'obtained_at' => '2026-06-10 09:00:00'],
            ];
        }

        public function nextAppointment(array $accountIds): ?stdClass
        {
            return (object) ['appointment_datetime' => '2026-10-29 13:00:00', 'first_name' => 'ELVIS', 'last_name' => 'ramos'];
        }

        public function lastRound(array $accountIds): ?array
        {
            return $this->o['round'] ?? ['number' => 2, 'sent_at' => CarbonImmutable::parse('2026-10-05')];
        }

        public function payments(string $accountId): array
        {
            return [
                (object) ['settlement_date' => '2026-07-01 10:00:00', 'type' => 'Initial payment', 'transaction_id' => 'T1', 'amount' => 59, 'method_payment' => 'Card(XXXX2787)', 'status_transaction_id' => 1, 'type_transaction_id' => 3, 'modality_transaction_id' => 2, 'order_query_sp' => 1],
                (object) ['settlement_date' => '2026-08-01 10:00:00', 'type' => 'Monthly Payment', 'transaction_id' => 'T2', 'amount' => 59, 'method_payment' => 'MA(XXXX2787)', 'status_transaction_id' => 1, 'type_transaction_id' => 2, 'modality_transaction_id' => 1, 'order_query_sp' => 3],
                (object) ['settlement_date' => '2026-08-20 10:00:00', 'type' => 'NCR(NEW CREDIT REPORT)', 'transaction_id' => 'T3', 'amount' => 18.99, 'method_payment' => 'Card(XXXX2787)', 'status_transaction_id' => 1, 'type_transaction_id' => 10, 'modality_transaction_id' => 4, 'order_query_sp' => 4],
                (object) ['settlement_date' => '2026-09-01 10:00:00', 'type' => 'Monthly Payment', 'transaction_id' => 'T4', 'amount' => 59, 'method_payment' => 'Card(XXXX2787)', 'status_transaction_id' => 1, 'type_transaction_id' => 2, 'modality_transaction_id' => 1, 'order_query_sp' => 3],
            ];
        }

        public function profile(int $leadId): ?stdClass
        {
            return (object) ['first_name' => 'LUIS', 'middle_name' => null, 'last_name' => 'ALVAREZ', 'mobile' => '6263467630', 'email' => 'Luis@Gmail.com',
                'street' => '123 Main St', 'street_address_2' => 'Apt 4', 'city' => 'Los Angeles', 'states_eeuu_slug' => 'CA', 'state_name' => 'California',
                'zipcode' => '90001', 'dob' => '1971-05-20', 'standard_time_zone' => '["EST"]'];
        }

        public function staff(string $accountId): ?stdClass
        {
            return (object) ['specialist_first' => 'Elvis', 'specialist_last' => 'Ramos', 'agent_first' => 'Carlos', 'agent_last' => 'Mendoza'];
        }
    };

    app()->instance(AccountData::class, $data);
}

function signedInCookie(int $status = 8): string
{
    $lead = SoftFixtures::lead('(626) 346-7630');
    $account = SoftFixtures::account($lead, 'CE1009757');
    $cookie = portalCookie(portalSignIn('6263467630'));
    DB::table('client_accounts')->where('id', $account)->update(['status' => $status]);

    return $cookie;
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00'));
    Http::fake(['soft.test/*/cards?*' => Http::response(['cards' => [], 'default_card_id' => null])]);
});

it('arma el inicio de una cuenta al día', function (): void {
    fakeAccountData();
    $cookie = signedInCookie();

    portalGet($cookie, 'home')->assertOk()
        ->assertJsonPath('plan', ['name' => 'LONG 12', 'status' => 'active', 'paid_payments' => 3, 'total_payments' => 12, 'monthly_amount' => 59])
        ->assertJsonPath('scores.date', '2026-10-10')
        ->assertJsonPath('scores.equifax', ['score' => 640, 'delta' => 40])
        ->assertJsonPath('scores.transunion', ['score' => null, 'delta' => null])
        ->assertJsonPath('payments', ['state' => 'current', 'next_date' => '2026-11-15', 'next_amount' => 59])
        ->assertJsonPath('appointment', ['date' => '2026-10-29', 'time' => '16:00', 'specialist' => 'Elvis R.'])
        ->assertJsonPath('dispute', ['round' => 2, 'sent_at' => '2026-10-05', 'respond_by' => '2026-11-19', 'window_closed' => false]);
});

it('muestra los pagos pendientes con la mensualidad guardada cuando la cuenta está atrasada', function (): void {
    fakeAccountData();
    $cookie = signedInCookie(status: 10);

    portalGet($cookie, 'home')->assertOk()
        ->assertJsonPath('payments', ['state' => 'late', 'pending_count' => 2, 'monthly_amount' => 59, 'amount_due' => 118]);
});

it('muestra Service completed para Loyal y avisa al Specialist del interés en otros servicios', function (): void {
    fakeAccountData();
    $cookie = signedInCookie(status: 12);

    portalGet($cookie, 'home')->assertJsonPath('plan.status', 'service_completed')->assertJsonPath('payments', ['state' => 'loyal', 'amount_due' => 531]);
    portalPost($cookie, 'home/services-interest')->assertNoContent();

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/notifications')
        && $r['recipient'] === 'specialist' && str_contains((string) $r['text'], 'Services interest'));
    expect(DB::table('client_portal_events')->where('event_type', 'services_interest')->count())->toBe(1);
});

it('marca la ventana de respuesta cerrada cuando pasaron 45 días sin ronda nueva', function (): void {
    fakeAccountData(['round' => ['number' => 1, 'sent_at' => CarbonImmutable::parse('2026-08-01')]]);

    portalGet(signedInCookie(), 'home')->assertJsonPath('dispute.window_closed', true)->assertJsonPath('dispute.respond_by', '2026-09-15');
});

it('lista los Credit Reports con la variación contra el anterior y marca el primero', function (): void {
    fakeAccountData();

    portalGet(signedInCookie(), 'credit-reports')->assertOk()
        ->assertJsonCount(3)
        ->assertJsonPath('0.experian', ['score' => 600, 'delta' => -10])
        ->assertJsonPath('0.has_pdf', true)
        ->assertJsonPath('1.has_pdf', false)
        ->assertJsonPath('2.first_report', true)
        ->assertJsonPath('2.equifax.delta', null);
});

it('no firma URL para un reporte sin PDF', function (): void {
    fakeAccountData();

    portalPost(signedInCookie(), 'credit-reports/2/download')->assertNotFound();
});

it('normaliza la key de S3 del PDF', function (): void {
    expect(CreditController::s3Key('/SM//138089\\SSN CR.pdf'))->toBe('SM/138089/SSN CR.pdf');
});

it('calcula los montos de Payments y el historial del más reciente al más antiguo', function (): void {
    fakeAccountData();

    portalGet(signedInCookie(), 'payments')->assertOk()
        ->assertJsonPath('plan_total', 708)
        ->assertJsonPath('paid', 177)
        ->assertJsonPath('remaining', 531)
        ->assertJsonPath('history.0', ['transaction_id' => 'T4', 'receipt_id' => 'T4', 'date' => '2026-09-01', 'concept' => 'monthly_payment', 'label' => 'Monthly Payment', 'amount' => 59, 'method' => 'card', 'card_last4' => '2787', 'status' => 'approved', 'plan_payment' => 3])
        ->assertJsonPath('history.1.status', 'voided')
        ->assertJsonPath('history.1.concept', 'charge')
        ->assertJsonPath('history.3.plan_payment', 1);
});

it('entrega el recibo solo de pagos aprobados y registra la descarga', function (): void {
    fakeAccountData();
    $cookie = signedInCookie();

    portalPost($cookie, 'payments/T3/receipt')->assertNotFound();
    portalPost($cookie, 'payments/T2/receipt')->assertOk()
        ->assertJsonPath('plan_payment', 2)
        ->assertJsonPath('plan', ['name' => 'LONG 12', 'total_payments' => 12])
        ->assertJsonPath('client', 'Luis Alvarez')
        ->assertJsonPath('account', 'CE1009757');

    expect(DB::table('client_portal_events')->where('event_type', 'receipt_downloaded')->value('entity_id'))->toBe('T2');
});

it('muestra el perfil con solo el año de nacimiento y el Specialist abreviado', function (): void {
    fakeAccountData();

    portalGet(signedInCookie(), 'profile')->assertOk()
        ->assertJsonPath('full_name', 'Luis Alvarez')
        ->assertJsonPath('mobile', '(626) 346-7630')
        ->assertJsonPath('email', 'luis@gmail.com')
        ->assertJsonPath('address', '123 Main St Apt 4, Los Angeles, CA 90001')
        ->assertJsonPath('birth_year', 1971)
        ->assertJsonPath('specialist', 'Elvis R.')
        ->assertJsonPath('agent', 'Carlos Mendoza')
        ->assertJsonMissingPath('dob');
});

it('envía la solicitud de cambio de datos al Agent', function (): void {
    fakeAccountData();

    portalPost(signedInCookie(), 'profile/change-request', ['field' => 'address', 'details' => 'New address: 1 Elm St'])->assertNoContent();

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/notifications')
        && $r['recipient'] === 'agent' && str_contains((string) $r['text'], 'Data change request') && str_contains((string) $r['text'], 'Address'));
    expect(DB::table('client_portal_events')->where('event_type', 'change_request')->value('metadata'))->toBe('{"category":"address"}');
});

it('lista los accesos marcando este dispositivo y cierra los demás', function (): void {
    fakeAccountData();
    $first = signedInCookie();
    $second = portalCookie(portalSignIn('6263467630'));

    portalGet($second, 'access/sessions')->assertOk()->assertJsonCount(2)->assertJsonPath('0.current', true)->assertJsonPath('1.current', false);
    portalPost($second, 'access/sign-out-others')->assertNoContent();

    portalGet($first, 'session')->assertUnauthorized();
    portalGet($second, 'session')->assertOk();
    expect(DB::table('client_portal_sessions')->whereNotNull('ended_at')->value('end_reason'))->toBe('signed_out_by_client');
});
