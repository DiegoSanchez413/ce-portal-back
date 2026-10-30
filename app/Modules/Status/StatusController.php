<?php

declare(strict_types=1);

namespace App\Modules\Status;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use Throwable;

final class StatusController
{
    // Comprueba que la API responde y que llega a la base compartida con el Soft.
    #[OA\Get(
        path: '/status',
        summary: 'API and database health',
        tags: ['Status'],
        responses: [
            new OA\Response(response: 200, description: 'API and database available'),
            new OA\Response(response: 503, description: 'Database unreachable'),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            $database = 'ok';
        } catch (Throwable) {
            $database = 'unreachable';
        }

        return response()->json([
            'status' => $database === 'ok' ? 'ok' : 'degraded',
            'database' => $database,
        ], $database === 'ok' ? 200 : 503);
    }
}
