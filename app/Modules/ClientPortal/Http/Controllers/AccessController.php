<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Http\Controllers;

use App\Modules\ClientPortal\Application\AccessDevices;
use App\Modules\ClientPortal\Http\CurrentSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

// My access: dispositivos del cliente con sus últimas sesiones, cierre por dispositivo y de todos los demás.
final class AccessController
{
    public function __construct(private readonly AccessDevices $devices) {}

    #[OA\Get(path: '/access/devices', operationId: 'listAccessDevices', summary: 'Devices with their recent sessions', tags: ['Account'], responses: [
        new OA\Response(response: 200, description: 'Devices', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/AccessDevice'))),
    ])]
    public function index(Request $request): JsonResponse
    {
        $session = CurrentSession::of($request);

        return response()->json($this->devices->of($session->leadId, $session->id));
    }

    #[OA\Post(path: '/access/devices/{key}/sign-out', operationId: 'signOutDevice', summary: 'End the open sessions of one device', tags: ['Account'],
        parameters: [new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 204, description: 'Done'),
            new OA\Response(response: 404, description: 'Unknown device'),
            new OA\Response(response: 422, description: 'This device: use logout'),
        ])]
    public function signOutDevice(Request $request, string $key): Response|JsonResponse
    {
        $session = CurrentSession::of($request);
        $device = collect($this->devices->of($session->leadId, $session->id))->firstWhere('key', $key);

        if ($device === null) {
            return response()->json(['code' => 'not_found', 'message' => 'Device not found.'], 404);
        }
        if ($device['current'] === true) {
            return response()->json(['code' => 'this_device', 'message' => 'Use sign out to end this device.'], 422);
        }

        $this->devices->signOut($session->leadId, $key, $session->id);

        return response()->noContent();
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
