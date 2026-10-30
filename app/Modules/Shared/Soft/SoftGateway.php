<?php

declare(strict_types=1);

namespace App\Modules\Shared\Soft;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Único punto de llamada a los endpoints internos del Soft (pagos, tarjetas, SMS).
 *
 * Cada request va firmado, lleva el request_id del portal y, si se indica, la clave de
 * idempotencia; los fallos de conexión se reintentan y terminan en SoftUnavailableException.
 */
final class SoftGateway
{
    public function __construct(
        private readonly SoftRequestSigner $signer,
        private readonly string $baseUrl,
        private readonly int $timeout,
    ) {}

    /** @param array<string, mixed> $query */
    public function get(string $path, array $query = []): Response
    {
        $uri = $query === [] ? $path : $path.'?'.http_build_query($query);

        return $this->send('GET', $uri, '');
    }

    /** @param array<string, mixed> $payload */
    public function post(string $path, array $payload, ?string $idempotencyKey = null): Response
    {
        return $this->send('POST', $path, (string) json_encode($payload, JSON_THROW_ON_ERROR), $idempotencyKey);
    }

    private function send(string $method, string $uri, string $body, ?string $idempotencyKey = null): Response
    {
        $timestamp = time();
        $path = '/'.ltrim($uri, '/');
        $signedPath = rtrim((string) parse_url($this->baseUrl, PHP_URL_PATH), '/').$path;

        $headers = array_filter([
            'Accept' => 'application/json',
            'X-Portal-Timestamp' => (string) $timestamp,
            'X-Portal-Signature' => $this->signer->sign($timestamp, $method, $signedPath, $body),
            'X-Request-Id' => (string) Context::get('request_id', ''),
            'Idempotency-Key' => $idempotencyKey,
        ]);

        try {
            $request = Http::baseUrl($this->baseUrl)
                ->withHeaders($headers)
                ->timeout($this->timeout)
                ->retry(3, 300, fn (Throwable $e): bool => $e instanceof ConnectionException, throw: false);

            return $method === 'GET'
                ? $request->get(ltrim($path, '/'))
                : $request->withBody($body, 'application/json')->post(ltrim($path, '/'));
        } catch (ConnectionException $e) {
            throw new SoftUnavailableException('Soft unreachable: '.$e->getMessage(), previous: $e);
        }
    }
}
