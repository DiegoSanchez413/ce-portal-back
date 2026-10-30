<?php

declare(strict_types=1);

use App\Modules\Shared\Soft\SoftGateway;
use App\Modules\Shared\Soft\SoftRequestSigner;
use App\Modules\Shared\Soft\SoftUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;

it('firma el request con timestamp, método, ruta y cuerpo', function (): void {
    Http::fake(['soft.test/*' => Http::response(['ok' => true])]);
    Context::add('request_id', 'req-12345678');

    app(SoftGateway::class)->post('payments/charge', ['amount' => 59], 'key-1');

    Http::assertSent(function (Request $request): bool {
        $timestamp = (int) $request->header('X-Portal-Timestamp')[0];
        $expected = (new SoftRequestSigner('test-key'))
            ->sign($timestamp, 'POST', '/api/internal/client-portal/payments/charge', '{"amount":59}');

        return $request->url() === 'http://soft.test/api/internal/client-portal/payments/charge'
            && $request->body() === '{"amount":59}'
            && $request->header('X-Portal-Signature')[0] === $expected
            && $request->header('Idempotency-Key')[0] === 'key-1'
            && $request->header('X-Request-Id')[0] === 'req-12345678';
    });
});

it('incluye la query en la ruta firmada de un GET', function (): void {
    Http::fake(['soft.test/*' => Http::response([])]);

    app(SoftGateway::class)->get('cards', ['lead_id' => 7]);

    Http::assertSent(function (Request $request): bool {
        $timestamp = (int) $request->header('X-Portal-Timestamp')[0];

        return $request->header('X-Portal-Signature')[0]
            === (new SoftRequestSigner('test-key'))->sign($timestamp, 'GET', '/api/internal/client-portal/cards?lead_id=7', '');
    });
});

it('lanza SoftUnavailableException si el Soft no responde', function (): void {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    app(SoftGateway::class)->get('cards');
})->throws(SoftUnavailableException::class);

it('devuelve la respuesta de error del Soft sin lanzar', function (): void {
    Http::fake(['soft.test/*' => Http::response(['message' => 'declined'], 402)]);

    expect(app(SoftGateway::class)->post('payments/charge', [])->status())->toBe(402);
});
