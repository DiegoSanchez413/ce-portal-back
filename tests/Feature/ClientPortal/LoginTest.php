<?php

declare(strict_types=1);

use App\Modules\ClientPortal\Mail\LoginCodeMail;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\SoftFixtures;

it('envía el código por SMS al celular vinculado y enmascara el destino', function (): void {
    $lead = SoftFixtures::lead('(626) 346-7630');
    SoftFixtures::account($lead, 'CE1009757');

    $this->postJson('/api/v1/auth/code', ['identifier' => '+1 626 346 7630', 'language' => 'en'])
        ->assertOk()
        ->assertJsonPath('channel', 'sms')
        ->assertJsonPath('destination', '***-***-7630')
        ->assertJsonPath('email', 'l*****@gmail.com');

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/sms')
        && $r['mobile'] === '(626) 346-7630'
        && preg_match('/@my\.creditexperts\.us #\d{6}$/', (string) $r['text']) === 1);
    expect(DB::table('client_portal_events')->where('event_type', 'otp_sent')->count())->toBe(1);
});

it('responde igual a un celular no vinculado sin enviar nada', function (): void {
    $this->postJson('/api/v1/auth/code', ['identifier' => '(555) 000-1111', 'language' => 'en'])
        ->assertOk()
        ->assertJsonPath('destination', '***-***-1111');

    Http::assertNothingSent();
    expect(DB::table('client_portal_events')->count())->toBe(0);
});

it('rechaza un valor que no es celular ni cuenta', function (string $value): void {
    $this->postJson('/api/v1/auth/code', ['identifier' => $value, 'language' => 'en'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Enter a valid mobile number or account number.');
})->with(['12345', 'hello', 'CE']);

it('ingresa con el código correcto y deja la cookie de sesión', function (): void {
    $lead = SoftFixtures::lead('(626) 346-7630');
    SoftFixtures::account($lead, 'CE1009757');

    $response = portalSignIn('6263467630')->assertOk()->assertJsonPath('status', 'signed_in');
    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'cp_session');

    expect($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('strict')
        ->and(DB::table('client_portal_sessions')->value('login_method'))->toBe('phone')
        ->and(DB::table('client_portal_events')->where('event_type', 'login')->count())->toBe(1);

    $this->withCredentials()->withUnencryptedCookie('cp_session', portalCookie($response))
        ->getJson('/api/v1/session')
        ->assertOk()
        ->assertJsonPath('client.first_name', 'Luis')
        ->assertJsonPath('client.initials', 'LA')
        ->assertJsonPath('account.number', 'CE1009757')
        ->assertJsonPath('shared_phone', false);
});

it('descuenta intentos y bloquea 15 minutos al quinto código incorrecto', function (): void {
    $lead = SoftFixtures::lead('(626) 346-7630');
    SoftFixtures::account($lead, 'CE1009757');
    [$challenge] = portalRequestCode('6263467630');

    $this->postJson('/api/v1/auth/verify', ['challenge_id' => $challenge, 'code' => '000000', 'keep_signed_in' => true])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Incorrect code. 4 attempts left.');

    foreach (range(1, 3) as $ignored) {
        $this->postJson('/api/v1/auth/verify', ['challenge_id' => $challenge, 'code' => '000000', 'keep_signed_in' => true]);
    }

    $this->postJson('/api/v1/auth/verify', ['challenge_id' => $challenge, 'code' => '000000', 'keep_signed_in' => true])
        ->assertStatus(423)
        ->assertJsonPath('message', 'Too many attempts. Please try again in 15 minutes.')
        ->assertJsonStructure(['until']);

    $this->postJson('/api/v1/auth/code', ['identifier' => '6263467630', 'language' => 'en'])->assertStatus(423);
    expect(DB::table('client_portal_events')->where('event_type', 'otp_locked')->count())->toBe(1);
});

it('bloquea el celular con más de 5 envíos en una hora', function (): void {
    $lead = SoftFixtures::lead('(626) 346-7630');
    SoftFixtures::account($lead, 'CE1009757');

    foreach (range(1, 5) as $ignored) {
        $this->postJson('/api/v1/auth/code', ['identifier' => '6263467630', 'language' => 'en'])->assertOk();
    }

    $this->postJson('/api/v1/auth/code', ['identifier' => '6263467630', 'language' => 'en'])->assertStatus(423);
});

it('no reenvía antes de 60 segundos y permite pedir el código por correo', function (): void {
    Mail::fake();
    $lead = SoftFixtures::lead('(626) 346-7630');
    SoftFixtures::account($lead, 'CE1009757');
    [$challenge] = portalRequestCode('6263467630');

    $this->postJson('/api/v1/auth/resend', ['challenge_id' => $challenge, 'channel' => 'email'])->assertStatus(429);

    $this->travel(61)->seconds();
    $this->postJson('/api/v1/auth/resend', ['challenge_id' => $challenge, 'channel' => 'email'])
        ->assertOk()
        ->assertJsonPath('channel', 'email')
        ->assertJsonPath('destination', 'l*****@gmail.com');

    Mail::assertSent(LoginCodeMail::class, fn ($mail) => $mail->hasTo('luis@gmail.com'));
});

it('muestra el selector cuando el celular es de varios clientes', function (): void {
    $luis = SoftFixtures::lead('(626) 346-7630', 'LUIS', 'alvarez');
    $ana = SoftFixtures::lead('(626) 346-7630', 'Ana', 'Bravo', null);
    SoftFixtures::account($luis, 'CE1000001');
    SoftFixtures::account($ana, 'CE1000002');

    [$challenge, $code] = portalRequestCode('6263467630');

    $this->postJson('/api/v1/auth/verify', ['challenge_id' => $challenge, 'code' => $code, 'keep_signed_in' => false])
        ->assertOk()
        ->assertJsonPath('status', 'select_client')
        ->assertJsonPath('clients.0.label', 'Luis A. · Credit Experts')
        ->assertJsonPath('clients.1.label', 'Ana B. · Credit Experts');

    $response = $this->postJson('/api/v1/auth/select-client', ['challenge_id' => $challenge, 'lead_id' => $ana, 'keep_signed_in' => false])
        ->assertOk()
        ->assertJsonPath('status', 'signed_in');

    $this->withCredentials()->withUnencryptedCookie('cp_session', portalCookie($response))
        ->getJson('/api/v1/session')
        ->assertJsonPath('account.number', 'CE1000002')
        ->assertJsonPath('shared_phone', true);
});

it('con número de cuenta entra directo a esa cuenta', function (): void {
    $lead = SoftFixtures::lead('(626) 346-7630');
    SoftFixtures::account($lead, 'CE1000001', createdAt: '2025-01-01');
    SoftFixtures::account($lead, 'CE1000002', createdAt: '2026-01-01');

    $response = portalSignIn('ce1000001')->assertJsonPath('status', 'signed_in');

    $this->withCredentials()->withUnencryptedCookie('cp_session', portalCookie($response))
        ->getJson('/api/v1/session')
        ->assertJsonPath('account.number', 'CE1000001');
});

it('lleva a la pantalla sin acceso y avisa una sola vez cada 24 horas al pedir reactivación', function (): void {
    $lead = SoftFixtures::lead('(626) 346-7630');
    SoftFixtures::account($lead, 'CE1000001', status: 2);

    $token = portalSignIn('6263467630')
        ->assertOk()
        ->assertJsonPath('status', 'no_access')
        ->json('reactivation.token');

    $this->postJson('/api/v1/auth/reactivation', ['token' => $token])->assertOk()->assertJsonPath('sent', true);
    $this->postJson('/api/v1/auth/reactivation', ['token' => $token])->assertOk()->assertJsonPath('sent', false);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/notifications') && $r['recipient'] === 'specialist');
    expect(DB::table('client_portal_requests')->where('type', 'reactivation')->count())->toBe(1);
});

it('niega el acceso cuando el switch Portal access está apagado', function (): void {
    $lead = SoftFixtures::lead('(626) 346-7630');
    SoftFixtures::account($lead, 'CE1000001');
    DB::table('client_portal_settings')->insert(['lead_id' => $lead, 'portal_enabled' => false]);

    portalSignIn('6263467630')->assertJsonPath('status', 'no_access');
});
