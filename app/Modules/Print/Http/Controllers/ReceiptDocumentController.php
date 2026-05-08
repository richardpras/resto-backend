<?php

namespace App\Modules\Print\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Print\Domain\ReceiptRenderHistory;
use App\Models\User;
use App\Modules\Print\Http\Requests\ListReceiptRenderHistoryRequest;
use App\Modules\Print\Http\Requests\RenderReceiptDocumentRequest;
use App\Modules\Print\Http\Requests\ReprintReceiptRenderRequest;
use App\Modules\Print\Http\Resources\ReceiptRenderHistoryResource;
use App\Modules\Print\Services\ReceiptDocumentService;
use App\Modules\Print\Support\ReceiptDocumentKind;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ReceiptDocumentController extends Controller
{
    public function __construct(
        private readonly ReceiptDocumentService $documents,
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    public function render(RenderReceiptDocumentRequest $request): JsonResponse
    {
        $user = $this->authenticate($request);

        $v = $request->validated();
        $history = $this->documents->render(
            $user,
            (int) $v['outletId'],
            ReceiptDocumentKind::from((string) $v['kind']),
            (string) $v['sourceType'],
            (int) $v['sourceId'],
            isset($v['orderSplitId']) ? (int) $v['orderSplitId'] : null,
            [
                'issueFiscal' => (bool) ($v['issueFiscal'] ?? false),
                'generatePdf' => (bool) ($v['generatePdf'] ?? false),
                'queuePrint' => (bool) ($v['queuePrint'] ?? false),
                'forceRegenerate' => (bool) ($v['forceRegenerate'] ?? false),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Receipt document rendered successfully.',
            'data' => new ReceiptRenderHistoryResource($history),
            'meta' => null,
        ]);
    }

    public function cashierSessionSummary(Request $request): JsonResponse
    {
        $user = $this->authenticate($request);
        /** @var array{outletId:int,posSessionId:int,generatePdf?:bool,queuePrint?:bool,issueFiscal?:bool} $v */
        $v = validator($request->all(), [
            'outletId' => ['required', 'integer', 'min:1', 'exists:outlets,id'],
            'posSessionId' => ['required', 'integer', 'min:1', 'exists:pos_sessions,id'],
            'generatePdf' => ['sometimes', 'boolean'],
            'queuePrint' => ['sometimes', 'boolean'],
            'issueFiscal' => ['sometimes', 'boolean'],
        ])->validate();

        $history = $this->documents->render(
            $user,
            (int) $v['outletId'],
            ReceiptDocumentKind::CashierCloseSummary,
            'pos_session',
            (int) $v['posSessionId'],
            null,
            [
                'issueFiscal' => (bool) ($v['issueFiscal'] ?? false),
                'generatePdf' => (bool) ($v['generatePdf'] ?? false),
                'queuePrint' => (bool) ($v['queuePrint'] ?? true),
                'forceRegenerate' => false,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Cashier close summary rendered successfully.',
            'data' => new ReceiptRenderHistoryResource($history),
            'meta' => null,
        ]);
    }

    public function history(ListReceiptRenderHistoryRequest $request): JsonResponse
    {
        $user = $this->authenticate($request);

        $v = $request->validated();
        $this->allowOutlet((int) $v['outletId'], $user);

        $q = ReceiptRenderHistory::query()->where('outlet_id', (int) $v['outletId'])->orderByDesc('id');
        if (($v['sourceType'] ?? null) !== null) {
            $q->where('source_type', (string) $v['sourceType']);
        }
        if (isset($v['sourceId'])) {
            $q->where('source_id', (int) $v['sourceId']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Receipt render history retrieved.',
            'data' => ReceiptRenderHistoryResource::collection($q->limit(100)->get()),
            'meta' => null,
        ]);
    }

    public function show(Request $request, int $history): JsonResponse
    {
        $user = $this->authenticate($request);
        /** @var ReceiptRenderHistory|null $record */
        $record = ReceiptRenderHistory::query()->find($history);
        if ($record === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $this->allowOutlet((int) $record->outlet_id, $user);

        return response()->json([
            'success' => true,
            'message' => 'Receipt render retrieved.',
            'data' => new ReceiptRenderHistoryResource($record),
            'meta' => null,
        ]);
    }

    public function pdf(Request $request, int $history): BinaryFileResponse|JsonResponse
    {
        $user = $this->authenticate($request);
        /** @var ReceiptRenderHistory|null $record */
        $record = ReceiptRenderHistory::query()->find($history);
        if ($record === null) {
            abort(Response::HTTP_NOT_FOUND);
        }
        $this->allowOutlet((int) $record->outlet_id, $user);

        $path = $this->documents->pdfStreamPath($record);
        if ($path === null) {
            return response()->json([
                'success' => false,
                'message' => 'PDF artifact not generated for this render.',
                'data' => null,
                'meta' => ['renderHistoryId' => (int) $record->id],
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->download($path, 'invoice-'.$record->id.'.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function reprint(ReprintReceiptRenderRequest $request, int $history): JsonResponse
    {
        $user = $this->authenticate($request);
        /** @var ReceiptRenderHistory|null $record */
        $record = ReceiptRenderHistory::query()->find($history);
        if ($record === null) {
            abort(Response::HTTP_NOT_FOUND);
        }
        $this->allowOutlet((int) $record->outlet_id, $user);

        $job = $this->documents->enqueueReprint($user, $record, $request->validated()['reason'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Reprint queued successfully.',
            'data' => [
                'printJobId' => (int) $job->id,
                'render' => new ReceiptRenderHistoryResource($record->fresh()),
            ],
            'meta' => null,
        ]);
    }

    public function markDeferred(Request $request, int $history): JsonResponse
    {
        $user = $this->authenticate($request);
        /** @var ReceiptRenderHistory|null $record */
        $record = ReceiptRenderHistory::query()->find($history);
        if ($record === null) {
            abort(Response::HTTP_NOT_FOUND);
        }
        $this->allowOutlet((int) $record->outlet_id, $user);

        $record->deferred_replay_pending = true;
        $record->recovery_meta = array_merge($record->recovery_meta ?? [], ['markedDeferredAt' => now()->toIso8601String()]);
        $record->save();

        return response()->json([
            'success' => true,
            'message' => 'Render marked for deferred/offline replay.',
            'data' => new ReceiptRenderHistoryResource($record->fresh()),
            'meta' => null,
        ]);
    }

    private function authenticate(Request $request): User
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        return $user;
    }

    private function allowOutlet(int $outletId, User $user): void
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages(['outletId' => ['The selected outlet is invalid.']]);
        }
    }
}
