<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Http\Controllers;

use App\Modules\ClientPortal\Domain\DocumentStatus;
use App\Modules\ClientPortal\Http\CurrentSession;
use App\Modules\ClientPortal\Infrastructure\ClientFiles;
use App\Modules\ClientPortal\Infrastructure\EventLog;
use App\Modules\ClientPortal\Infrastructure\TempUploads;
use App\Modules\Shared\Soft\SoftGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

/**
 * My Files: estado de ID, Utility bill y SSN, vista del archivo con URL de 5 minutos y
 * carga de una versión nueva (hasta 3 archivos) que entra a revisión con origen Client Portal.
 */
final class FilesController
{
    private const LABELS = ['id' => 'ID', 'utility_bill' => 'Utility bill', 'ssn' => 'SSN'];

    public function __construct(private readonly ClientFiles $files) {}

    #[OA\Get(path: '/files', operationId: 'listDocuments', summary: 'ID, utility bill and SSN status', tags: ['Files'], responses: [
        new OA\Response(response: 200, description: 'Documents', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Document'))),
    ])]
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->documents(CurrentSession::of($request)->leadId));
    }

    #[OA\Post(path: '/files/{type}/view', operationId: 'viewDocument', summary: 'Signed URL (5 min) of the current file', tags: ['Files'],
        parameters: [new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['id', 'utility_bill']))],
        responses: [
            new OA\Response(response: 200, description: 'URL', content: new OA\JsonContent(required: ['url'], properties: [new OA\Property(property: 'url', type: 'string')])),
            new OA\Response(response: 404, description: 'No file'),
        ])]
    public function view(Request $request, string $type): JsonResponse
    {
        $typeId = array_search($type, DocumentStatus::TYPES, true);
        if ($typeId === false || $typeId === DocumentStatus::SSN) {
            abort(404);
        }

        $file = $this->files->currentDocuments(CurrentSession::of($request)->leadId)[$typeId] ?? null;
        $key = $file === null ? '' : CreditController::s3Key(trim((string) $file->route, '/').'/'.(string) $file->file_name);
        if ($key === '') {
            abort(404);
        }

        return response()->json(['url' => Storage::disk('s3')->temporaryUrl($key, now()->addMinutes(5))]);
    }

    #[OA\Post(path: '/files/{type}', operationId: 'uploadDocument', summary: 'Send a new version (1 to 3 files)', tags: ['Files'],
        parameters: [new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['id', 'utility_bill', 'ssn']))],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(
            required: ['files[]'], properties: [new OA\Property(property: 'files[]', type: 'array', items: new OA\Items(type: 'string', format: 'binary'))],
        ))),
        responses: [
            new OA\Response(response: 201, description: 'In review', content: new OA\JsonContent(ref: '#/components/schemas/Document')),
            new OA\Response(response: 409, description: 'Already in review'),
            new OA\Response(response: 422, description: 'Invalid files'),
        ])]
    public function upload(Request $request, string $type, TempUploads $temp, SoftGateway $soft, EventLog $events): JsonResponse
    {
        $session = CurrentSession::of($request);
        $typeId = array_search($type, DocumentStatus::TYPES, true);
        if ($typeId === false) {
            abort(404);
        }

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:3'],
            'files.*' => ['file', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:10240'],
        ]);

        $current = DocumentStatus::of($typeId, $this->files->currentDocuments($session->leadId)[$typeId] ?? null);
        if ($current['status'] === 'in_review') {
            return response()->json(['code' => 'in_review', 'message' => 'This document is already in review.'], 409);
        }

        /** @var list<UploadedFile> $uploads */
        $uploads = array_values((array) $request->file('files'));
        $stored = $temp->put($uploads);

        $response = $soft->post('files/document', [
            'client_account_id' => $session->accountId,
            'document_type_id' => $typeId,
            'temp_keys' => $stored['keys'],
            'original_names' => $stored['names'],
        ]);

        if ($response->failed()) {
            $temp->forget($stored['keys']);

            return response()->json(['code' => 'soft_error', 'message' => "We couldn't send your file. Please try again."], 502);
        }

        $events->record('document_uploaded', $session->id, $session->leadId, $session->accountId,
            ['document_type' => $type, 'files' => count($uploads)], 'file', (string) $response->json('file_lead_id'));

        $soft->post('notifications', [
            'client_account_id' => $session->accountId,
            'recipient' => 'agent',
            'text' => '<b>Client Portal</b>: Client uploaded '.self::LABELS[$type].' from the portal',
        ]);

        return response()->json(DocumentStatus::of($typeId, $this->files->currentDocuments($session->leadId)[$typeId] ?? null), 201);
    }

    /** @return list<array<string, mixed>> */
    private function documents(int $leadId): array
    {
        $current = $this->files->currentDocuments($leadId);

        return array_map(fn (int $type): array => DocumentStatus::of($type, $current[$type] ?? null), array_keys(DocumentStatus::TYPES));
    }
}
