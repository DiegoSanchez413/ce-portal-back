<?php

declare(strict_types=1);

use App\Modules\ClientPortal\Infrastructure\AccountData;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SoftFixtures;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00'));
    Storage::fake('s3');
    Http::fake([
        'soft.test/*/files/document' => Http::response(['file_lead_id' => 900, 'status' => 'PENDING_APPROVE'], 201),
        'soft.test/*/files/letter' => Http::response(['letter_id' => 55, 'files' => []], 201),
        'soft.test/*/cards?*' => Http::response(['cards' => [], 'default_card_id' => null]),
    ]);
});

// ID aprobado que vence en 20 días y Utility bill rechazado con motivo; sin SSN.
function seedDocuments(int $lead): void
{
    DB::table('files_leads')->insert([
        ['lead_id' => $lead, 'document_type_id' => 1, 'status' => 'APPROVED', 'expiration' => '2026-11-09', 'route' => '/ID//'.$lead, 'file_name' => 'ID 1.png', 'description' => null, 'created_at' => '2026-01-10 10:00:00', 'deleted_at' => null],
        ['lead_id' => $lead, 'document_type_id' => 2, 'status' => 'APPROVED', 'expiration' => '2026-12-01', 'route' => 'UB/'.$lead, 'file_name' => 'UB old.png', 'description' => null, 'created_at' => '2026-01-10 10:00:00', 'deleted_at' => null],
        ['lead_id' => $lead, 'document_type_id' => 2, 'status' => 'REJECTED', 'expiration' => null, 'route' => 'UB/'.$lead, 'file_name' => 'UB 2.png', 'description' => null, 'created_at' => '2026-10-01 10:00:00', 'deleted_at' => null],
    ]);
    DB::table('file_leads_tracking')->insert(['file_lead_id' => 3, 'status' => 'REJECTED', 'motive' => 'The bill is older than 60 days.']);
}

function filesSession(): array
{
    $lead = SoftFixtures::lead('(626) 346-7630');
    SoftFixtures::account($lead, 'CE1009757');

    return [$lead, portalCookie(portalSignIn('6263467630'))];
}

it('muestra el estado vigente de cada documento con el motivo del rechazo', function (): void {
    [$lead, $cookie] = filesSession();
    seedDocuments($lead);

    portalGet($cookie, 'files')->assertOk()
        ->assertJsonPath('0', ['type' => 'id', 'status' => 'approved', 'uploaded_at' => '2026-01-10', 'expiration' => '2026-11-09', 'expires_in_days' => 20, 'reject_reason' => null])
        ->assertJsonPath('1.status', 'rejected')
        ->assertJsonPath('1.reject_reason', 'The bill is older than 60 days.')
        ->assertJsonPath('2.status', 'missing');
});

it('lleva a Inicio los documentos que piden atención salvo el SSN faltante', function (): void {
    [$lead, $cookie] = filesSession();
    seedDocuments($lead);
    app()->instance(AccountData::class, new class extends AccountData
    {
        public function plan(string $accountId): ?stdClass
        {
            return null;
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
            return (object) ['standard_time_zone' => null];
        }
    });

    portalGet($cookie, 'home')->assertOk()->assertJsonCount(2, 'files')
        ->assertJsonPath('files.0.type', 'id')
        ->assertJsonPath('files.1.type', 'utility_bill');
});

it('firma 5 minutos la URL del ID con la key normalizada y no permite ver el SSN', function (): void {
    [$lead, $cookie] = filesSession();
    seedDocuments($lead);

    expect(portalPost($cookie, 'files/id/view')->assertOk()->json('url'))->toContain('ID/'.$lead.'/ID 1.png');
    portalPost($cookie, 'files/ssn/view')->assertNotFound();
});

it('sube hasta 3 archivos a revisión y avisa al Agent', function (): void {
    [$lead, $cookie] = filesSession();
    seedDocuments($lead);

    test()->withCredentials()->withUnencryptedCookie('cp_session', $cookie)->post('/api/v1/files/utility_bill', [
        'files' => [fakePng('front.jpg'), fakePdf('bill.pdf')],
    ], ['Accept' => 'application/json'])->assertCreated();

    Http::assertSent(function (Request $r): bool {
        if (! str_ends_with($r->url(), '/files/document')) {
            return false;
        }
        foreach ($r['temp_keys'] as $key) {
            Storage::disk('s3')->assertExists($key);
        }

        return $r['document_type_id'] === 2 && count($r['temp_keys']) === 2 && str_starts_with($r['temp_keys'][0], 'client-portal/tmp/');
    });
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/notifications') && $r['recipient'] === 'agent'
        && str_contains((string) $r['text'], 'Utility bill'));
    expect(DB::table('client_portal_events')->where('event_type', 'document_uploaded')->value('entity_id'))->toBe('900');
});

it('rechaza más de 3 archivos, tipos no permitidos y documentos en revisión', function (): void {
    [$lead, $cookie] = filesSession();
    $post = fn (string $type, array $files) => test()->withCredentials()->withUnencryptedCookie('cp_session', $cookie)
        ->post('/api/v1/files/'.$type, ['files' => $files], ['Accept' => 'application/json']);

    $post('id', array_fill(0, 4, fakePng('a.jpg')))->assertUnprocessable();
    $post('id', [UploadedFile::fake()->createWithContent('a.webp', 'RIFF0000WEBPVP8 ')])->assertUnprocessable();

    DB::table('files_leads')->insert(['lead_id' => $lead, 'document_type_id' => 3, 'status' => 'PENDING_APPROVE', 'created_at' => '2026-10-19 10:00:00']);
    $post('ssn', [fakePng('ssn.png')])->assertStatus(409);
    Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/files/document'));
});

it('envía una carta de buró y la lista en Sent letters', function (): void {
    [$lead, $cookie] = filesSession();
    $post = fn (array $body) => test()->withCredentials()->withUnencryptedCookie('cp_session', $cookie)
        ->post('/api/v1/letters', $body, ['Accept' => 'application/json']);

    $post(['type' => 'bureau_response', 'files' => [fakePng('p1.jpg')]])->assertUnprocessable()->assertJsonValidationErrors('bureau');
    $post(['type' => 'bureau_response', 'bureau' => 'experian', 'note' => 'Got this today', 'files' => [fakePng('p1.jpg'), fakePng('p2.jpg')]])
        ->assertCreated()->assertJsonPath('id', 55)->assertJsonPath('files', 2);

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/files/letter') && $r['bureau'] === 'experian' && $r['lead_id'] === $lead);
    expect(DB::table('client_portal_events')->where('event_type', 'letter_sent')->value('metadata'))->toContain('experian');

    DB::table('client_portal_letters')->insert(['lead_id' => $lead, 'client_account_id' => 'x', 'type' => 'other', 'status' => 'REVIEWED', 'created_at' => '2026-10-19 10:00:00']);
    DB::table('client_portal_letter_files')->insert([['letter_id' => 1], ['letter_id' => 1]]);
    portalGet($cookie, 'letters')->assertOk()->assertJsonPath('0', ['id' => 1, 'type' => 'other', 'bureau' => null, 'files' => 2, 'status' => 'reviewed', 'sent_at' => '2026-10-19T10:00:00+00:00']);
});
