<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Http\Controllers;

use App\Modules\ClientPortal\Http\CurrentSession;
use App\Modules\ClientPortal\Infrastructure\ClientFiles;
use App\Modules\ClientPortal\Infrastructure\EventLog;
use App\Modules\ClientPortal\Infrastructure\TempUploads;
use App\Modules\Shared\Soft\SoftGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use OpenApi\Attributes as OA;

// Upload Letters: el cliente envía cartas recibidas, que el Soft guarda en Downloads y muestra en Client Uploads.
final class LettersController
{
    public const TYPES = ['bureau_response', 'court_document', 'creditor_letter', 'other'];

    #[OA\Get(path: '/letters', operationId: 'listLetters', summary: 'Letters sent by the client', tags: ['Files'], responses: [
        new OA\Response(response: 200, description: 'Letters', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Letter'))),
    ])]
    public function index(Request $request, ClientFiles $files): JsonResponse
    {
        return response()->json(array_map(fn (\stdClass $l): array => [
            'id' => (int) $l->id,
            'type' => (string) $l->type,
            'bureau' => $l->bureau,
            'files' => (int) $l->files,
            'status' => strtolower((string) $l->status),
            'sent_at' => CarbonImmutable::parse((string) $l->created_at)->toIso8601String(),
        ], $files->letters(CurrentSession::of($request)->leadId)));
    }

    #[OA\Post(path: '/letters', operationId: 'sendLetter', summary: 'Send a letter (1 to 10 files)', tags: ['Files'],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(
            required: ['type', 'files[]'],
            properties: [
                new OA\Property(property: 'type', type: 'string', enum: self::TYPES),
                new OA\Property(property: 'bureau', type: 'string', enum: ['equifax', 'experian', 'transunion'], nullable: true),
                new OA\Property(property: 'note', type: 'string', maxLength: 500, nullable: true),
                new OA\Property(property: 'files[]', type: 'array', items: new OA\Items(type: 'string', format: 'binary')),
            ],
        ))),
        responses: [
            new OA\Response(response: 201, description: 'Sent', content: new OA\JsonContent(ref: '#/components/schemas/Letter')),
            new OA\Response(response: 422, description: 'Invalid'),
        ])]
    public function store(Request $request, TempUploads $temp, SoftGateway $soft, EventLog $events): JsonResponse
    {
        $session = CurrentSession::of($request);
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', self::TYPES)],
            'bureau' => ['required_if:type,bureau_response', 'nullable', 'in:equifax,experian,transunion'],
            'note' => ['nullable', 'string', 'max:500'],
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['file', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:10240'],
        ]);
        $bureau = $data['type'] === 'bureau_response' ? $data['bureau'] : null;

        /** @var list<UploadedFile> $uploads */
        $uploads = array_values((array) $request->file('files'));
        $stored = $temp->put($uploads);

        $response = $soft->post('files/letter', [
            'client_account_id' => $session->accountId,
            'lead_id' => $session->leadId,
            'session_id' => $session->id,
            'type' => $data['type'],
            'bureau' => $bureau,
            'note' => $data['note'] ?? null,
            'temp_keys' => $stored['keys'],
            'original_names' => $stored['names'],
        ]);

        if ($response->failed()) {
            $temp->forget($stored['keys']);

            return response()->json(['code' => 'soft_error', 'message' => "We couldn't send your letter. Please try again."], 502);
        }

        $letterId = (int) $response->json('letter_id');
        $events->record('letter_sent', $session->id, $session->leadId, $session->accountId,
            ['category' => (string) $data['type'], 'bureau' => $bureau, 'files' => count($uploads)], 'letter', (string) $letterId);

        return response()->json([
            'id' => $letterId, 'type' => $data['type'], 'bureau' => $bureau, 'files' => count($uploads),
            'status' => 'received', 'sent_at' => now()->toIso8601String(),
        ], 201);
    }
}
