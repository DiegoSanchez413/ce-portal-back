<?php

declare(strict_types=1);

namespace App\Modules\Shared\Soft;

final class SoftRequestSigner
{
    public function __construct(private readonly string $key) {}

    // Firma timestamp, método, ruta y hash del cuerpo; el Soft recalcula lo mismo para validar.
    public function sign(int $timestamp, string $method, string $path, string $body): string
    {
        $payload = implode("\n", [$timestamp, strtoupper($method), '/'.ltrim($path, '/'), hash('sha256', $body)]);

        return hash_hmac('sha256', $payload, $this->key);
    }
}
