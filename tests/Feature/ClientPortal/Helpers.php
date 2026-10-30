<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

// Pide el código, lo lee del SMS enviado al Soft y devuelve [challenge_id, código].
function portalRequestCode(string $identifier): array
{
    $response = test()->postJson('/api/v1/auth/code', ['identifier' => $identifier, 'language' => 'en'])->assertOk();

    return [$response->json('challenge_id'), portalLastSmsCode()];
}

function portalLastSmsCode(): ?string
{
    $code = null;
    Http::recorded(function (Request $request) use (&$code): bool {
        if (str_ends_with($request->url(), '/sms') && preg_match('/#(\d{6})/', (string) $request['text'], $m) === 1) {
            $code = $m[1];
        }

        return true;
    });

    return $code;
}

function portalSignIn(string $identifier, bool $keep = true): TestResponse
{
    [$challenge, $code] = portalRequestCode($identifier);

    return test()->postJson('/api/v1/auth/verify', ['challenge_id' => $challenge, 'code' => $code, 'keep_signed_in' => $keep]);
}

function portalCookie(TestResponse $response): string
{
    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'cp_session');

    return (string) $cookie?->getValue();
}

function portalGet(string $cookie, string $path)
{
    return test()->withCredentials()->withUnencryptedCookie('cp_session', $cookie)->getJson('/api/v1/'.$path);
}

function portalPost(string $cookie, string $path, array $body = [])
{
    return test()->withCredentials()->withUnencryptedCookie('cp_session', $cookie)->postJson('/api/v1/'.$path, $body);
}

function fakePng(string $name = 'photo.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg=='));
}

function fakePdf(string $name = 'doc.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
}
