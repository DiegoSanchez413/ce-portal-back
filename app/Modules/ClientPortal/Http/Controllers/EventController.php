<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Http\Controllers;

use App\Modules\ClientPortal\Infrastructure\EventLog;
use App\Modules\ClientPortal\Infrastructure\PortalSession;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

final class EventController
{
    public const SECTIONS = ['home', 'credit', 'payments', 'my_files', 'upload_letters', 'profile', 'my_access'];

    // Registra la visita a una sección; el front no espera la respuesta.
    #[OA\Post(
        path: '/events/section-view',
        operationId: 'sectionView',
        summary: 'Record a section visit',
        tags: ['Session'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['section'],
            properties: [new OA\Property(property: 'section', type: 'string', enum: self::SECTIONS)],
        )),
        responses: [new OA\Response(response: 204, description: 'Recorded')],
    )]
    public function sectionView(Request $request, EventLog $events): Response
    {
        $section = (string) $request->validate(['section' => ['required', 'in:'.implode(',', self::SECTIONS)]])['section'];
        $session = $request->attributes->get('portal_session');

        if ($session instanceof PortalSession) {
            $events->sectionView($session->id, $session->leadId, $session->accountId, $section);
        }

        return response()->noContent();
    }
}
