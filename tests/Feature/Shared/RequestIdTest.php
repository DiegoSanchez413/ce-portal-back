<?php

declare(strict_types=1);

it('genera un request id cuando no viene uno', function (): void {
    $response = $this->getJson('/api/v1/status');

    expect($response->headers->get('X-Request-Id'))->toMatch('/^[0-9a-f-]{36}$/');
});

it('respeta el request id que manda el front', function (): void {
    $this->getJson('/api/v1/status', ['X-Request-Id' => 'front-1234-abcd'])
        ->assertHeader('X-Request-Id', 'front-1234-abcd');
});

it('descarta un request id con caracteres no permitidos', function (): void {
    $response = $this->getJson('/api/v1/status', ['X-Request-Id' => 'bad id; drop']);

    expect($response->headers->get('X-Request-Id'))->not->toBe('bad id; drop');
});
