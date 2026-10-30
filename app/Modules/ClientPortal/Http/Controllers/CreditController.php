<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Http\Controllers;

use App\Modules\ClientPortal\Http\CurrentSession;
use App\Modules\ClientPortal\Infrastructure\AccountData;
use App\Modules\ClientPortal\Infrastructure\ClientDirectory;
use App\Modules\ClientPortal\Infrastructure\EventLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

// Credit Reports del cliente (de todas sus cuentas) y descarga del PDF con URL de 5 minutos.
final class CreditController
{
    private const BUREAUS = ['equifax', 'experian', 'transunion'];

    public function __construct(
        private readonly AccountData $data,
        private readonly ClientDirectory $directory,
    ) {}

    #[OA\Get(path: '/credit-reports', operationId: 'listCreditReports', summary: 'Credit reports, most recent first', tags: ['Account'], responses: [
        new OA\Response(response: 200, description: 'Reports', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/CreditReport'))),
    ])]
    public function index(Request $request): JsonResponse
    {
        $session = CurrentSession::of($request);
        $reports = $this->data->creditReports($session->leadId, $this->accountIds($session->leadId));
        $items = [];

        foreach ($reports as $i => $report) {
            $previous = $reports[$i + 1] ?? null;
            $item = [
                'id' => (int) $report->score_id,
                'date' => CarbonImmutable::parse((string) ($report->date ?? $report->created_at))->toDateString(),
                'first_report' => $previous === null,
                'has_pdf' => trim((string) $report->route_pdf) !== '',
            ];
            foreach (self::BUREAUS as $bureau) {
                $score = $report->{$bureau} !== null ? (int) $report->{$bureau} : null;
                $before = $previous?->{$bureau} !== null ? (int) $previous->{$bureau} : null;
                $item[$bureau] = ['score' => $score, 'delta' => $score !== null && $before !== null ? $score - $before : null];
            }
            $items[] = $item;
        }

        return response()->json($items);
    }

    #[OA\Post(path: '/credit-reports/{id}/download', operationId: 'downloadCreditReport', summary: 'Signed URL (5 min) of the report PDF', tags: ['Account'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'URL', content: new OA\JsonContent(required: ['url'], properties: [new OA\Property(property: 'url', type: 'string')])),
            new OA\Response(response: 404, description: 'Not found'),
        ])]
    public function download(Request $request, int $id, EventLog $events): JsonResponse
    {
        $session = CurrentSession::of($request);
        $report = collect($this->data->creditReports($session->leadId, $this->accountIds($session->leadId)))
            ->first(fn (\stdClass $r): bool => (int) $r->score_id === $id);
        $key = $report === null ? '' : self::s3Key((string) $report->route_pdf);

        if ($key === '') {
            abort(404);
        }

        $url = Storage::disk('s3')->temporaryUrl($key, now()->addMinutes(5));
        $events->record('cr_downloaded', $session->id, $session->leadId, $session->accountId, [], 'credit_report', (string) $id);

        return response()->json(['url' => $url]);
    }

    // Key de S3 normalizada: barras invertidas, dobles y la barra inicial que guarda el Soft.
    public static function s3Key(string $route): string
    {
        $key = str_replace('\\', '/', $route);
        $key = (string) preg_replace('#/{2,}#', '/', $key);

        return trim($key, '/');
    }

    /** @return list<string> */
    private function accountIds(int $leadId): array
    {
        return array_map(fn (\stdClass $a): string => (string) $a->id, $this->directory->accountsOf($leadId));
    }
}
