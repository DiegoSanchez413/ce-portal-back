<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Domain;

final class Eligibility
{
    public const CREDIT_EXPERTS = 3;

    // Active (Current, One Month Late, Two Month Late) y Loyal (In Progress, Potential, Stand By).
    public const ELIGIBLE_STATUSES = [8, 9, 10, 11, 12, 13];

    public static function isEligibleStatus(int $status): bool
    {
        return in_array($status, self::ELIGIBLE_STATUSES, true);
    }
}
