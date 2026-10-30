<?php

declare(strict_types=1);

use App\Modules\ClientPortal\Domain\CreditScore;

it('lee los scores numéricos del Soft', function (): void {
    expect(CreditScore::parse('577'))->toBe(577)
        ->and(CreditScore::parse(624))->toBe(624);
});

it('deja en null los scores no reportados', function (mixed $value): void {
    expect(CreditScore::parse($value))->toBeNull();
})->with(['Not Reported - Code: 4', 'L1', 'Score Not Reported', '-', '--', '', null, '0', '999']);
