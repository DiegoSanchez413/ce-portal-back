<?php

declare(strict_types=1);

use App\Modules\ClientPortal\Infrastructure\AccountData;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\SoftFixtures;

// Cuenta con mensualidad de 59 y saldo pendiente de 531; tarjetas en el Soft simulado.
function payFixtures(int $status = 9, float $paid = 177, string $charge = 'approved'): string
{
    app()->instance(AccountData::class, new class($paid) extends AccountData
    {
        public function __construct(private float $paid) {}

        public function plan(string $accountId): ?stdClass
        {
            return (object) ['plan_name' => 'LONG 12', 'months' => 12, 'plan_monthly' => 59, 'rb_monthly' => 59, 'paid_months' => 3];
        }

        public function balance(string $accountId): ?stdClass
        {
            return (object) ['total_payment' => $this->paid, 'total_charged' => 708];
        }
    });

    $lead = SoftFixtures::lead('(626) 346-7630');
    $account = SoftFixtures::account($lead, 'CE1009757', $status);

    Http::fake([
        'soft.test/*/cards?*' => Http::response(['cards' => [
            ['id' => 10, 'last4' => '2787', 'brand' => 'VISA', 'exp_month' => 1, 'exp_year' => 2026, 'is_default' => true],
            ['id' => 11, 'last4' => '5521', 'brand' => 'MC', 'exp_month' => 11, 'exp_year' => 2026, 'is_default' => false],
            ['id' => 12, 'last4' => '4444', 'brand' => 'VISA', 'exp_month' => 5, 'exp_year' => 2029, 'is_default' => false],
        ], 'default_card_id' => 10]),
        'soft.test/*/payments/charge' => Http::response(['status' => $charge, 'transaction_id' => 'TX99', 'message' => ucfirst($charge), 'amount' => 118, 'card_last4' => '5521', 'paid_at' => '2026-10-27T21:14:00Z']),
        'soft.test/*/cards/default' => Http::response(['default_card_id' => 11]),
        'soft.test/*/cards' => Http::response(['card' => ['id' => 13, 'last4' => '1111', 'brand' => 'VISA', 'exp_month' => 4, 'exp_year' => 2030, 'is_default' => true]], 201),
    ]);

    return portalCookie(portalSignIn('6263467630'));
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00'));
});

it('fija el monto con los meses atrasados y preselecciona la siguiente tarjeta activa si la default venció', function (): void {
    $cookie = payFixtures(status: 10);

    portalGet($cookie, 'pay')->assertOk()
        ->assertJsonPath('payable', true)
        ->assertJsonPath('amount', 118)
        ->assertJsonPath('count', 2)
        ->assertJsonPath('monthly_amount', 59)
        ->assertJsonPath('default_card_expired', true)
        ->assertJsonPath('preselected_card_id', 11)
        ->assertJsonPath('cards.1.expires_soon', false)
        ->assertJsonPath('cards.0.expiration', '01/26');
});

it('cobra una sola vez aunque el cliente reintente con la misma clave', function (): void {
    $cookie = payFixtures(status: 10);
    $key = (string) Str::uuid();

    portalPost($cookie, 'pay', ['idempotency_key' => $key, 'card_id' => 11])->assertOk()
        ->assertJsonPath('status', 'approved')
        ->assertJsonPath('amount', 118)
        ->assertJsonPath('transaction_id', 'TX99')
        ->assertJsonPath('card.last4', '5521');
    portalPost($cookie, 'pay', ['idempotency_key' => $key, 'card_id' => 11])->assertOk()->assertJsonPath('transaction_id', 'TX99');

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/payments/charge')
        && $r['amount'] === 118 && $r['card_id'] === 11 && $r->header('Idempotency-Key')[0] === $key);
    expect(DB::table('client_portal_events')->where('event_type', 'payment_made')->value('entity_id'))->toBe('TX99');
});

it('no cobra con una tarjeta vencida ni cuando no hay nada que pagar', function (): void {
    $cookie = payFixtures(status: 9);

    portalPost($cookie, 'pay', ['idempotency_key' => (string) Str::uuid(), 'card_id' => 10])->assertUnprocessable();
    Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/payments/charge'));
});

it('no permite pagar a un Loyal sin saldo pendiente', function (): void {
    $cookie = payFixtures(status: 12, paid: 708);

    portalGet($cookie, 'pay')->assertJsonPath('payable', false);
    portalPost($cookie, 'pay', ['idempotency_key' => (string) Str::uuid(), 'card_id' => 11])->assertStatus(409);
});

it('registra el pago declinado como payment_failed', function (): void {
    $cookie = payFixtures(status: 8, charge: 'declined');

    portalPost($cookie, 'pay', ['idempotency_key' => (string) Str::uuid(), 'card_id' => 11])->assertOk()->assertJsonPath('status', 'declined');

    expect(DB::table('client_portal_events')->where('event_type', 'payment_failed')->count())->toBe(1);
});

it('pide el security check para cambiar la tarjeta cuando pasaron más de 15 minutos', function (): void {
    $cookie = payFixtures();
    $this->travel(16)->minutes();

    portalPost($cookie, 'cards/11/default')->assertForbidden()->assertJsonPath('code', 'step_up_required');

    $challenge = portalPost($cookie, 'security/code')->assertOk()->assertJsonPath('destination', '***-***-7630')->json('challenge_id');
    portalPost($cookie, 'security/verify', ['challenge_id' => $challenge, 'code' => portalLastSmsCode()])->assertNoContent();

    portalPost($cookie, 'cards/11/default')->assertNoContent();

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/cards/default') && $r['card_id'] === 11);
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/notifications') && $r['recipient'] === 'agent'
        && str_contains((string) $r['text'], '•••• 5521'));
    expect(DB::table('client_portal_events')->where('event_type', 'card_set_default')->count())->toBe(1);
});

it('agrega una tarjeta sin mandar el CVC a ningún lado salvo al Soft', function (): void {
    $cookie = payFixtures();

    portalPost($cookie, 'cards', [
        'cardholdername' => 'Luis Alvarez', 'cardnumber' => '4111 1111 1111 1111', 'cvc' => '123',
        'exp_month' => 4, 'exp_year' => 30, 'zipcode' => '90001', 'set_default' => true,
    ])->assertCreated()->assertJsonPath('last4', '1111')->assertJsonPath('expiration', '04/30');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'http://soft.test/api/internal/client-portal/cards'
        && $r['cardnumber'] === '4111111111111111' && $r['card_expi_year'] === '30' && $r['set_default'] === true);
    expect(json_encode(DB::table('client_portal_events')->pluck('metadata')))->not->toContain('123')
        ->and(DB::table('client_portal_events')->where('event_type', 'card_added')->count())->toBe(1);
});

it('avisa en Inicio cuando la tarjeta del cobro automático venció', function (): void {
    $cookie = payFixtures(status: 8);
    app()->instance(AccountData::class, new class extends AccountData
    {
        public function plan(string $accountId): ?stdClass
        {
            return (object) ['plan_name' => 'LONG 12', 'months' => 12, 'plan_monthly' => 59, 'rb_monthly' => 59, 'paid_months' => 3];
        }

        public function nextDebit(string $accountId): ?array
        {
            return null;
        }

        public function creditReports(int $leadId, array $accountIds): array
        {
            return [];
        }

        public function nextAppointment(array $accountIds): ?stdClass
        {
            return null;
        }

        public function lastRound(array $accountIds): ?array
        {
            return null;
        }

        public function profile(int $leadId): ?stdClass
        {
            return (object) ['standard_time_zone' => 'PST'];
        }
    });

    portalGet($cookie, 'home')->assertOk()->assertJsonPath('card_alert', ['state' => 'expired', 'expiration' => '01/26']);
});
