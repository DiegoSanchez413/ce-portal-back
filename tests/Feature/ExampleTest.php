<?php

declare(strict_types=1);

it('responde el chequeo de salud del framework', function (): void {
    $this->get('/up')->assertOk();
});
