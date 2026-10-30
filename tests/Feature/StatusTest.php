<?php

declare(strict_types=1);

it('informa que la API y la base están disponibles', function (): void {
    $this->getJson('/api/v1/status')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'database' => 'ok']);
});
