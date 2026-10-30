<?php

declare(strict_types=1);

namespace App\Modules\Status;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

final class StatusController
{
    // Comprueba que la API responde y que llega a la base compartida con el Soft.
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
