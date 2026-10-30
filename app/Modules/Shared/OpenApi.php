<?php

declare(strict_types=1);

namespace App\Modules\Shared;

use OpenApi\Attributes as OA;

#[OA\Info(version: '1.0.0', title: 'Client Portal API')]
#[OA\Server(url: '/api/v1')]
final class OpenApi {}
