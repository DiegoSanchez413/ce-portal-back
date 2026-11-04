<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\SoftFixtures;

function portalSession(bool $keep = true): array
{
    $lead = SoftFixtures::lead('(626) 346-7630');
    $account = SoftFixtures::account($lead, 'CE1009757');

    return [$lead, $account, portalCookie(portalSignIn('6263467630', $keep))];
}

it('cierra la sesión y muestra sin acceso cuando la cuenta deja de ser elegible', function (): void {
    [, $account, $cookie] = portalSession();
    DB::table('client_accounts')->where('id', $account)->update(['status' => 4]);

    $this->withCredentials()->withUnencryptedCookie('cp_session', $cookie)->getJson('/api/v1/session')
        ->assertForbidden()
        ->assertJsonPath('code', 'no_access')
        ->assertJsonStructure(['reactivation' => ['token', 'available_at']]);

    expect(DB::table('client_portal_sessions')->value('end_reason'))->toBe('status_change');
});

it('termina todas las sesiones cuando cambia el celular en el Soft', function (): void {
    [$lead, , $cookie] = portalSession();
    DB::table('leads')->where('id', $lead)->update(['mobile' => '(626) 000-0000']);

    $this->withCredentials()->withUnencryptedCookie('cp_session', $cookie)->getJson('/api/v1/session')->assertUnauthorized();

    expect(DB::table('client_portal_sessions')->value('end_reason'))->toBe('phone_change');
});

it('cierra la sesión cuando se apaga el switch Portal access', function (): void {
    [$lead, , $cookie] = portalSession();
    DB::table('client_portal_settings')->insert(['lead_id' => $lead, 'portal_enabled' => false]);

    $this->withCredentials()->withUnencryptedCookie('cp_session', $cookie)->getJson('/api/v1/session')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'portal_disabled');
});

it('vence a los 30 minutos sin actividad si no se marcó Keep me signed in', function (): void {
    [, , $cookie] = portalSession(keep: false);

    $this->travel(29)->minutes();
    $this->withCredentials()->withUnencryptedCookie('cp_session', $cookie)->getJson('/api/v1/session')->assertOk();

    $this->travel(31)->minutes();
    $this->withCredentials()->withUnencryptedCookie('cp_session', $cookie)->getJson('/api/v1/session')->assertUnauthorized();

    expect(DB::table('client_portal_sessions')->value('end_reason'))->toBe('inactivity');
});

it('con Keep me signed in dura 30 días renovables y como máximo 90', function (): void {
    [, , $cookie] = portalSession();

    foreach (range(1, 3) as $ignored) {
        $this->travel(29)->days();
        $this->withCredentials()->withUnencryptedCookie('cp_session', $cookie)->getJson('/api/v1/session')->assertOk();
    }

    $this->travel(5)->days();
    $this->withCredentials()->withUnencryptedCookie('cp_session', $cookie)->getJson('/api/v1/session')->assertUnauthorized();
    expect(DB::table('client_portal_sessions')->value('end_reason'))->toBe('expired');
});

it('Sign out termina la sesión actual', function (): void {
    [, , $cookie] = portalSession();

    $this->withCredentials()->withUnencryptedCookie('cp_session', $cookie)->postJson('/api/v1/session/logout')->assertNoContent();
    $this->withCredentials()->withUnencryptedCookie('cp_session', $cookie)->getJson('/api/v1/session')->assertUnauthorized();

    expect(DB::table('client_portal_sessions')->value('end_reason'))->toBe('logout')
        ->and(DB::table('client_portal_events')->where('event_type', 'logout')->count())->toBe(1);
});

it('guarda una fila por sección y suma las visitas', function (): void {
    [, , $cookie] = portalSession();

    $this->withCredentials()->withUnencryptedCookie('cp_session', $cookie)->postJson('/api/v1/events/section-view', ['section' => 'payments'])->assertNoContent();
    $this->withCredentials()->withUnencryptedCookie('cp_session', $cookie)->postJson('/api/v1/events/section-view', ['section' => 'payments'])->assertNoContent();
    $this->withCredentials()->withUnencryptedCookie('cp_session', $cookie)->postJson('/api/v1/events/section-view', ['section' => 'home'])->assertNoContent();

    expect(DB::table('client_portal_events')->where('event_type', 'section_view')->where('section', 'payments')->value('occurrences'))->toBe(2)
        ->and(DB::table('client_portal_events')->where('event_type', 'section_view')->count())->toBe(2);
});

it('lleva las visitas a una fila nueva cuando se cambia de cuenta en la misma sesión', function (): void {
    [$lead, , $cookie] = portalSession();
    $other = SoftFixtures::account($lead, 'CE1009758');
    $view = fn () => test()->withCredentials()->withUnencryptedCookie('cp_session', $cookie)->postJson('/api/v1/events/section-view', ['section' => 'home'])->assertNoContent();

    $view();
    DB::table('client_portal_sessions')->update(['client_account_id' => $other]);
    $view();
    $view();

    expect(DB::table('client_portal_events')->where('section', 'home')->where('client_account_id', $other)->value('occurrences'))->toBe(2)
        ->and(DB::table('client_portal_events')->where('section', 'home')->count())->toBe(2);
});

it('usa el idioma de leads.language para la sesión y el código', function (): void {
    [$lead, , $cookie] = portalSession();

    $this->withCredentials()->withUnencryptedCookie('cp_session', $cookie)->getJson('/api/v1/session')->assertJsonPath('language', 'en');

    DB::table('leads')->where('id', $lead)->update(['language' => 'ES']);
    $this->withCredentials()->withUnencryptedCookie('cp_session', $cookie)->getJson('/api/v1/session')->assertJsonPath('language', 'es');
    $challenge = $this->postJson('/api/v1/auth/code', ['identifier' => '6263467630', 'language' => 'en'])->assertOk()->json('challenge_id');
    expect(DB::table('client_portal_challenges')->where('id', $challenge)->value('language'))->toBe('es');

    DB::table('leads')->where('id', $lead)->update(['language' => '0']);
    $this->withCredentials()->withUnencryptedCookie('cp_session', $cookie)->getJson('/api/v1/session')->assertJsonPath('language', null);
});

it('ya no guarda el idioma elegido dentro del portal', function (): void {
    [, , $cookie] = portalSession();

    $this->withCredentials()->withUnencryptedCookie('cp_session', $cookie)->postJson('/api/v1/session/language', ['language' => 'es'])->assertNotFound();
});

it('rechaza la API sin cookie de sesión', function (): void {
    $this->getJson('/api/v1/session')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
});
