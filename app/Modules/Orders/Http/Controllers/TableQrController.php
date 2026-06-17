<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Orders\Http\Resources\RestaurantTableResource;
use App\Modules\Orders\Http\Resources\TableQrResolveResource;
use App\Modules\Orders\Services\QrGuestSessionService;
use App\Modules\Orders\Services\QrTableActiveOrderService;
use App\Modules\Orders\Services\TableQrManagementService;
use App\Modules\Orders\Services\TableQrPdfService;
use App\Modules\Orders\Services\TableQrService;
use App\Modules\Orders\Support\ResolvesQrGuestSessionHeader;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TableQrController extends Controller
{
    use ResolvesQrGuestSessionHeader;

    public function __construct(
        private readonly TableQrManagementService $tableQrManagementService,
        private readonly TableQrService $tableQrService,
        private readonly TableQrPdfService $tableQrPdfService,
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly QrTableActiveOrderService $activeOrderService,
        private readonly QrGuestSessionService $guestSessionService,
    ) {}

    public function generate(Request $request, RestaurantTable $table): JsonResponse
    {
        $updated = $this->tableQrManagementService->generate($request->user(), (int) $table->id);

        return response()->json([
            'message' => 'QR identity generated.',
            'data' => new RestaurantTableResource($updated),
        ], Response::HTTP_OK);
    }

    public function rotate(Request $request, RestaurantTable $table): JsonResponse
    {
        $updated = $this->tableQrManagementService->rotate($request->user(), (int) $table->id);

        return response()->json([
            'message' => 'QR identity rotated.',
            'data' => new RestaurantTableResource($updated),
        ], Response::HTTP_OK);
    }

    public function regenerate(Request $request, RestaurantTable $table): JsonResponse
    {
        return $this->rotate($request, $table);
    }

    public function enable(Request $request, RestaurantTable $table): JsonResponse
    {
        $updated = $this->tableQrManagementService->enable($request->user(), (int) $table->id);

        return response()->json([
            'message' => 'QR enabled.',
            'data' => new RestaurantTableResource($updated),
        ], Response::HTTP_OK);
    }

    public function disable(Request $request, RestaurantTable $table): JsonResponse
    {
        $updated = $this->tableQrManagementService->disable($request->user(), (int) $table->id);

        return response()->json([
            'message' => 'QR disabled.',
            'data' => new RestaurantTableResource($updated),
        ], Response::HTTP_OK);
    }

    public function show(Request $request, RestaurantTable $table): JsonResponse
    {
        $this->assertScopedTable($request, $table);

        return response()->json([
            'data' => $this->tableQrService->buildPayload($table),
        ]);
    }

    public function image(Request $request, RestaurantTable $table): Response
    {
        $this->assertScopedTable($request, $table);
        $qrUrl = $this->tableQrService->buildQrUrl($table);
        abort_if($qrUrl === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'QR URL is not available.');

        $binary = $this->tableQrService->renderPngBinary($qrUrl);

        return response($binary, Response::HTTP_OK, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="'.$this->tableQrService->pngFilename($table).'"',
        ]);
    }

    public function export(Request $request): Response
    {
        $validated = $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
            'tableIds' => ['sometimes', 'array'],
            'tableIds.*' => ['integer', 'min:1'],
        ]);

        $tableIds = array_map('intval', $validated['tableIds'] ?? []);
        $pdf = $this->tableQrPdfService->exportForOutlet(
            $request->user(),
            (int) $validated['outletId'],
            $tableIds,
        );

        return response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="table-qr-labels.pdf"',
        ]);
    }

    public function resolve(Request $request, string $qrPublicId): JsonResponse
    {
        $failure = $this->resolveFailure($qrPublicId);
        if ($failure !== null) {
            return response()->json($failure['body'], $failure['status']);
        }

        $existingToken = $this->guestSessionTokenFromRequest($request);
        $resolved = $this->guestSessionService->resolveOrCreate($qrPublicId, $existingToken);
        $table = $resolved['table'];
        $guestSession = $resolved['session'];

        $activeSession = $this->activeOrderService->resolveForTable($table, (int) $guestSession->id);

        $resource = new TableQrResolveResource($table, $this->tableQrManagementService);

        return response()->json([
            'data' => array_merge(
                $resource->toArray($request),
                [
                    'activeSession' => $activeSession,
                    'guestSession' => $this->guestSessionService->toPublicPayload($guestSession),
                ],
            ),
        ]);
    }

    public function activeSession(Request $request, string $qrPublicId): JsonResponse
    {
        $failure = $this->resolveFailure($qrPublicId);
        if ($failure !== null) {
            return response()->json($failure['body'], $failure['status']);
        }

        try {
            $guestSessionId = null;
            $token = $this->guestSessionTokenFromRequest($request);
            if ($token !== null) {
                $guestSession = $this->guestSessionService->findActiveByToken($token);
                $guestSessionId = $guestSession?->id !== null ? (int) $guestSession->id : null;
            }

            $session = $this->activeOrderService->resolveByPublicId($qrPublicId, $guestSessionId);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Table not found.',
                'code' => 'table_unavailable',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => $session,
        ]);
    }

    public function resolveLegacy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
            'tableId' => ['required', 'integer', 'min:1'],
        ]);

        $table = $this->tableQrManagementService->resolveLegacy((int) $validated['outletId'], (int) $validated['tableId']);
        if ($table === null) {
            return response()->json([
                'message' => 'Table not found or unavailable.',
                'code' => 'table_unavailable',
            ], Response::HTTP_NOT_FOUND);
        }

        $outletFailure = $this->outletFailure((int) $table->outlet_id);
        if ($outletFailure !== null) {
            return response()->json($outletFailure, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $table->qr_enabled) {
            return response()->json([
                'message' => 'QR code is no longer active for this table.',
                'code' => 'qr_expired',
            ], Response::HTTP_GONE);
        }

        $existingToken = $this->guestSessionTokenFromRequest($request);
        $resolved = $this->guestSessionService->resolveOrCreate((string) $table->qr_public_id, $existingToken);
        $guestSession = $resolved['session'];

        return response()->json([
            'data' => array_merge(
                (new TableQrResolveResource($table, $this->tableQrManagementService))->toArray($request),
                [
                    'guestSession' => $this->guestSessionService->toPublicPayload($guestSession),
                ],
            ),
            'meta' => ['compatibility' => 'legacy-query'],
        ]);
    }

    /** @return array{status:int,body:array<string,mixed>}|null */
    private function resolveFailure(string $qrPublicId): ?array
    {
        $table = RestaurantTable::query()->where('qr_public_id', $qrPublicId)->first();
        if ($table === null) {
            return [
                'status' => Response::HTTP_NOT_FOUND,
                'body' => [
                    'message' => 'QR code not found.',
                    'code' => 'qr_not_found',
                ],
            ];
        }

        if (! $table->qr_enabled) {
            return [
                'status' => Response::HTTP_GONE,
                'body' => [
                    'message' => 'QR code has expired or was disabled.',
                    'code' => 'qr_expired',
                ],
            ];
        }

        if (! $table->active || (string) $table->status !== 'active') {
            return [
                'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'body' => [
                    'message' => 'This table is currently unavailable.',
                    'code' => 'table_unavailable',
                ],
            ];
        }

        $outletFailure = $this->outletFailure((int) $table->outlet_id);
        if ($outletFailure !== null) {
            return [
                'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'body' => $outletFailure,
            ];
        }

        return null;
    }

    /** @return array{message:string,code:string}|null */
    private function outletFailure(int $outletId): ?array
    {
        $outlet = Outlet::query()->find($outletId);
        if ($outlet === null || (string) $outlet->status !== 'active') {
            return [
                'message' => 'This outlet is currently unavailable.',
                'code' => 'outlet_unavailable',
            ];
        }

        return null;
    }

    private function assertScopedTable(Request $request, RestaurantTable $table): void
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($request->user());
        if (! in_array((int) $table->outlet_id, $allowed, true)) {
            throw (new ModelNotFoundException())->setModel(RestaurantTable::class, [(int) $table->id]);
        }
    }
}
