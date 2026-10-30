<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;

it('registra el error que reporta el front', function (): void {
    Log::spy();

    $this->postJson('/api/v1/client-logs', ['level' => 'error', 'message' => 'Boom', 'url' => '/payments'])
        ->assertNoContent();

    Log::shouldHaveReceived('log')->once()->withArgs(
        fn (string $level, string $message, array $context): bool => $level === 'error'
            && $message === 'front: Boom'
            && $context['source'] === 'front'
            && $context['url'] === '/payments',
    );
});

it('rechaza un nivel no permitido', function (): void {
    $this->postJson('/api/v1/client-logs', ['level' => 'debug', 'message' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('level');
});

it('limita los reportes por IP', function (): void {
    for ($i = 0; $i < 30; $i++) {
        $this->postJson('/api/v1/client-logs', ['level' => 'warning', 'message' => 'x'])->assertNoContent();
    }

    $this->postJson('/api/v1/client-logs', ['level' => 'warning', 'message' => 'x'])->assertTooManyRequests();
});
