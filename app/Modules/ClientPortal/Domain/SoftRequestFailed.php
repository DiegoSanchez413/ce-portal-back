<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Domain;

use RuntimeException;

// Respuesta de error del Soft que se le muestra al cliente con su estado HTTP.
final class SoftRequestFailed extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
