<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Http\Controllers;

use App\Modules\ClientPortal\Http\CurrentSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

// My access: últimas 20 sesiones del cliente y cierre de las demás.
final class AccessController
{
    #[OA\Get(path: '/access/sessions', operationId: 'listAccessSessions', summary: 'Last 20 sessions', tags: ['Account'], responses: [
        new OA\Response(response: 200, description: 'Sessions', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/AccessSession'))),
    ])]
    public function index(Request $request): JsonResponse
    {
        $session = CurrentSession::of($request);

        $rows = DB::table('client_portal_sessions')
            ->where('lead_id', $session->leadId)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'device', 'os', 'browser', 'city', 'region', 'started_at']);

        return response()->json($rows->map(fn (\stdClass $row): array => [
            'id' => (int) $row->id,
            'device' => $row->device === 'Computer' && $row->os !== 'Other' ? (string) $row->os : (string) $row->device,
            'browser' => (string) $row->browser,
            'is_phone' => in_array($row->device, ['iPhone', 'Android phone'], true),
            'city' => $row->city,
            'region' => $row->region,
            'started_at' => CarbonImmutable::parse((string) $row->started_at)->toIso8601String(),
            'current' => (int) $row->id === $session->id,
        ])->values());
    }

    #[OA\Post(path: '/access/sign-out-others', operationId: 'signOutOtherDevices', summary: 'End every other session', tags: ['Account'], responses: [
        new OA\Response(response: 204, description: 'Done'),
    ])]
    public function signOutOthers(Request $request): Response
    {
        $session = CurrentSession::of($request);

        DB::table('client_portal_sessions')
            ->where('lead_id', $session->leadId)
            ->where('id', '!=', $session->id)
            ->whereNull('ended_at')
            ->update(['ended_at' => now(), 'end_reason' => 'signed_out_by_client']);

        return response()->noContent();
    }
}
