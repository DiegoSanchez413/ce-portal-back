<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Infrastructure;

use Carbon\CarbonImmutable;
use stdClass;

// Intento de ingreso: a quién identifica el celular o la cuenta y por qué canal se verificó.
final class LoginChallenge
{
    /** @param list<int> $leadIds */
    public function __construct(
        public readonly string $id,
        public readonly string $loginMethod,
        public readonly string $mobile,
        public readonly ?string $email,
        public readonly array $leadIds,
        public readonly ?string $accountId,
        public readonly string $language,
        public readonly ?CarbonImmutable $verifiedAt,
        public readonly ?string $verifiedChannel,
        public readonly CarbonImmutable $expiresAt,
    ) {}

    public static function fromRow(stdClass $row): self
    {
        /** @var list<int> $leadIds */
        $leadIds = array_map('intval', (array) json_decode((string) $row->lead_ids, true));

        return new self(
            (string) $row->id,
            (string) $row->login_method,
            (string) $row->mobile,
            $row->email !== null ? (string) $row->email : null,
            $leadIds,
            $row->client_account_id !== null ? (string) $row->client_account_id : null,
            (string) $row->language,
            $row->verified_at !== null ? CarbonImmutable::parse((string) $row->verified_at) : null,
            $row->verified_channel !== null ? (string) $row->verified_channel : null,
            CarbonImmutable::parse((string) $row->expires_at),
        );
    }

    // Celular que no identifica a ningún cliente: se responde igual pero nunca se envía ni valida un código.
    public function isGhost(): bool
    {
        return $this->leadIds === [];
    }

    public function singleLeadId(): ?int
    {
        return count($this->leadIds) === 1 ? $this->leadIds[0] : null;
    }

    public function destinationHash(): string
    {
        return hash_hmac('sha256', $this->mobile, (string) config('app.key'));
    }
}
