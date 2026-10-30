<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Application;

use App\Modules\ClientPortal\Infrastructure\PortalSession;

// Resultado de entrar con un cliente: sesión abierta (con su token) o pantalla sin acceso.
final class AccessDecision
{
    private function __construct(
        public readonly ?PortalSession $session,
        public readonly ?string $token,
        public readonly ?int $leadId,
        public readonly ?string $accountId,
    ) {}

    public static function granted(PortalSession $session, string $token): self
    {
        return new self($session, $token, $session->leadId, $session->accountId);
    }

    public static function denied(int $leadId, string $accountId): self
    {
        return new self(null, null, $leadId, $accountId);
    }

    public function isGranted(): bool
    {
        return $this->session !== null;
    }
}
