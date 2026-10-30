<?php

declare(strict_types=1);

namespace App\Modules\ClientLogs;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

final class ClientLogController
{
    // Registra en el log del back los errores que reporta el front del portal.
    #[OA\Post(
        path: '/client-logs',
        summary: 'Report a front-end error',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['level', 'message'],
            properties: [
                new OA\Property(property: 'level', type: 'string', enum: ['error', 'warning']),
                new OA\Property(property: 'message', type: 'string', maxLength: 1000),
                new OA\Property(property: 'stack', type: 'string', maxLength: 5000, nullable: true),
                new OA\Property(property: 'url', type: 'string', maxLength: 500, nullable: true),
            ],
        )),
        tags: ['Client logs'],
        responses: [
            new OA\Response(response: 204, description: 'Logged'),
            new OA\Response(response: 422, description: 'Invalid payload'),
            new OA\Response(response: 429, description: 'Too many reports'),
        ],
    )]
    public function __invoke(StoreClientLogRequest $request): Response
    {
        /** @var array{level: string, message: string, stack?: string|null, url?: string|null} $data */
        $data = $request->validated();

        Log::log($data['level'], 'front: '.$data['message'], [
            'source' => 'front',
            'url' => $data['url'] ?? null,
            'stack' => $data['stack'] ?? null,
            'user_agent' => $request->userAgent(),
        ]);

        return response()->noContent();
    }
}
