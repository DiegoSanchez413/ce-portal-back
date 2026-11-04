<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Infrastructure;

use App\Modules\ClientPortal\Domain\SoftRequestFailed;
use App\Modules\Shared\Soft\SoftGateway;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Tarjetas y cobros del cliente, que siguen ejecutándose en el Soft por sus endpoints internos.
 */
class SoftCards
{
    public function __construct(private readonly SoftGateway $soft) {}

    /** @return array{cards: list<array{id: int, last4: string, brand: string|null, exp_month: int, exp_year: int, is_default: bool}>, default_card_id: int|null} */
    public function list(string $accountId): array
    {
        /** @var array{cards: list<array{id: int, last4: string, brand: string|null, exp_month: int, exp_year: int, is_default: bool}>, default_card_id: int|null} */
        return $this->json($this->soft->get('cards', ['client_account_id' => $accountId]));
    }

    // Lista para el aviso de Inicio: se guarda 10 min y se borra al agregar o cambiar la predeterminada.
    /** @return array{cards: list<array{id: int, last4: string, brand: string|null, exp_month: int, exp_year: int, is_default: bool}>, default_card_id: int|null} */
    public function cachedList(string $accountId): array
    {
        /** @var array{cards: list<array{id: int, last4: string, brand: string|null, exp_month: int, exp_year: int, is_default: bool}>, default_card_id: int|null} */
        return Cache::remember(self::cacheKey($accountId), now()->addMinutes(10), fn (): array => $this->list($accountId));
    }

    /**
     * @param  array{cardholdername: string, cardnumber: string, cardsecuritycode: string, card_expi_month: string, card_expi_year: string, zipcode: string}  $card
     * @return array{id: int, last4: string, brand: string|null, exp_month: int, exp_year: int, is_default: bool}
     */
    public function add(string $accountId, array $card, bool $setDefault): array
    {
        /** @var array{card: array{id: int, last4: string, brand: string|null, exp_month: int, exp_year: int, is_default: bool}} $data */
        $data = $this->json($this->soft->post('cards', $card + ['client_account_id' => $accountId, 'set_default' => $setDefault]));
        Cache::forget(self::cacheKey($accountId));

        return $data['card'];
    }

    public function setDefault(string $accountId, int $cardId): void
    {
        $this->json($this->soft->post('cards/default', ['client_account_id' => $accountId, 'card_id' => $cardId]));
        Cache::forget(self::cacheKey($accountId));
    }

    /** @return array{status: string, transaction_id: string|null, message: string, amount: float, card_last4: string, paid_at: string|null} */
    public function charge(string $accountId, int $cardId, float $amount, string $idempotencyKey): array
    {
        /** @var array{status: string, transaction_id: string|null, message: string, amount: float, card_last4: string, paid_at: string|null} */
        return $this->json($this->soft->post('payments/charge', [
            'client_account_id' => $accountId,
            'card_id' => $cardId,
            'amount' => $amount,
        ], $idempotencyKey));
    }

    /** @return array{cardholdername: string|null, cardnumber: string|null, exp_month: int|null, exp_year: int|null} */
    public function scan(string $image, string $mime): array
    {
        /** @var array{cardholdername: string|null, cardnumber: string|null, exp_month: int|null, exp_year: int|null} */
        return $this->json($this->soft->post('cards/scan', ['image' => $image, 'mime' => $mime]));
    }

    private static function cacheKey(string $accountId): string
    {
        return 'client_portal:cards:'.$accountId;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        if ($response->failed()) {
            $message = $response->status() < 500 ? (string) $response->json('message', 'Please check the information and try again.') : 'Something went wrong. Please try again.';

            throw new SoftRequestFailed($message, $response->status() < 500 ? $response->status() : 502);
        }

        /** @var array<string, mixed> */
        return (array) $response->json();
    }
}
