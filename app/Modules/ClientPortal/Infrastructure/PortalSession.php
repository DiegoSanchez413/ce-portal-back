<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Infrastructure;

use Carbon\CarbonImmutable;
use stdClass;

final class PortalSession
{
    /** @param list<int> $eligibleLeadIds */
    public function __construct(
        public readonly int $id,
        public readonly int $leadId,
        public readonly string $accountId,
        public readonly array $eligibleLeadIds,
        public readonly string $mobileAtLogin,
        public readonly bool $trustedDevice,
        public readonly CarbonImmutable $lastSeenAt,
        public readonly CarbonImmutable $lastVerifiedAt,
        public readonly CarbonImmutable $absoluteExpiresAt,
    ) {}

    public static function fromRow(stdClass $row): self
    {
        /** @var list<int> $leadIds */
        $leadIds = array_map('intval', (array) json_decode((string) $row->eligible_lead_ids, true));

        return new self(
            (int) $row->id,
            (int) $row->lead_id,
            (string) $row->client_account_id,
            $leadIds,
            (string) $row->mobile_at_login,
            (bool) $row->trusted_device,
            CarbonImmutable::parse((string) $row->last_seen_at),
            CarbonImmutable::parse((string) $row->last_verified_at),
            CarbonImmutable::parse((string) $row->absolute_expires_at),
        );
    }

    public function isSharedPhone(): bool
    {
        return count($this->eligibleLeadIds) > 1;
    }
}
