<?php

declare(strict_types=1);

use App\Modules\ClientPortal\Mail\LoginCodeMail;
use Carbon\CarbonImmutable;
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

it('arma el SMS y el correo del código con las plantillas del CEO', function (): void {
    Mail::fake();
    DB::table('client_portal_message_templates')->insert([
        ['type' => 'ACCESS_CODE_SMS', 'channel' => 'sms', 'subject_es' => null, 'subject_en' => null,
            'body_es' => 'Código @code', 'body_en' => 'Use @code to sign in', 'sender_email' => null],
        ['type' => 'ACCESS_CODE_EMAIL', 'channel' => 'email', 'subject_es' => 'Código', 'subject_en' => 'Code @code',
            'body_es' => 'Código @code', 'body_en' => 'Your code: @code. Call @phone', 'sender_email' => 'portal@creditexperts.us'],
    ]);
    $lead = SoftFixtures::lead('(626) 346-7630');
    SoftFixtures::account($lead, 'CE1009757');
    [$challenge] = portalRequestCode('6263467630');

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/sms')
        && preg_match('/^Use (\d{6}) to sign in\n\n@my\.creditexperts\.us #\1$/', (string) $r['text']) === 1);

    $this->travel(61)->seconds();
    $this->postJson('/api/v1/auth/resend', ['challenge_id' => $challenge, 'channel' => 'email'])->assertOk();

    Mail::assertSent(LoginCodeMail::class, fn (LoginCodeMail $mail): bool => preg_match('/^Code \d{6}$/', $mail->subjectLine) === 1
        && preg_match('/^Your code: \d{6}\. Call \(855\) 517-0390$/', $mail->body) === 1
        && $mail->supportPhone === '(855) 517-0390'
        && $mail->hasFrom('portal@creditexperts.us'));
});

it('usa el texto por defecto cuando la plantilla no tiene @code', function (): void {
    DB::table('client_portal_message_templates')->insert(['type' => 'ACCESS_CODE_SMS', 'channel' => 'sms',
        'body_es' => 'Sin código', 'body_en' => 'No code here']);
    $lead = SoftFixtures::lead('(626) 346-7630');
    SoftFixtures::account($lead, 'CE1009757');
    portalRequestCode('6263467630');

    Http::assertSent(fn (Request $r): bool => str_starts_with((string) $r['text'], 'Your Credit Experts code is '));
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
        ->assertJsonPath('clients.1.label', 'Ana B. · Credit Experts')
        ->assertJsonPath('clients.1.account_hint', '0002')
        ->assertJsonPath('clients.1.current', false);

    $response = $this->postJson('/api/v1/auth/select-client', ['challenge_id' => $challenge, 'lead_id' => $ana, 'keep_signed_in' => false])
        ->assertOk()
        ->assertJsonPath('status', 'signed_in');

    $this->withCredentials()->withUnencryptedCookie('cp_session', portalCookie($response))
        ->getJson('/api/v1/session')
        ->assertJsonPath('account.number', 'CE1000002')
        ->assertJsonPath('shared_phone', true);

    $this->withCredentials()->withUnencryptedCookie('cp_session', portalCookie($response))
        ->getJson('/api/v1/session/clients')
        ->assertJsonPath('0.current', false)
        ->assertJsonPath('1.current', true)
        ->assertJsonPath('1.account_hint', '0002');
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

it('al pasar el límite por IP responde 429 con la hora en que puede volver a intentar', function (): void {
    foreach (range(10, 19) as $n) {
        $this->postJson('/api/v1/auth/code', ['identifier' => "(555) 000-11{$n}", 'language' => 'en'])->assertOk();
    }

    $response = $this->postJson('/api/v1/auth/code', ['identifier' => '(555) 000-1120', 'language' => 'en'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'too_many_requests');

    expect(CarbonImmutable::parse((string) $response->json('until'))->isFuture())->toBeTrue();
});

it('arma el correo del código con el diseño, el código resaltado y el logo', function (): void {
    $html = (new LoginCodeMail('Your code', "Your code is 483920.\n\nIt expires in 5 minutes.", '483920', 'en', '(855) 517-0390'))->render();

    expect($html)->toContain('letter-spacing:4px;">483920</span>')
        ->toContain('/images/ce-email-logo.png')
        ->toContain('It expires in 5 minutes.')
        ->toContain('Client portal')
        ->toContain('Credit Experts · (855) 517-0390 ·');
});
