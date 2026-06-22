<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\HR\Domain\AttendanceImportBatch;
use App\Modules\HR\Http\Resources\AttendanceImportBatchResource;
use App\Modules\HR\Http\Resources\AttendanceRecordResource;
use App\Modules\HR\Http\Requests\StoreAttendanceRecordRequest;
use App\Modules\HR\Services\AttendanceImportService;
use App\Modules\HR\Services\AttendanceRecordCorrectionService;
use App\Modules\HR\Services\AttendanceRecordManualService;
use App\Modules\HR\Services\AttendanceRecordQueryService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AttendanceRecordController extends Controller
{
    public function __construct(
        private readonly AttendanceRecordQueryService $queryService,
        private readonly AttendanceImportService $importService,
        private readonly AttendanceRecordCorrectionService $correctionService,
        private readonly AttendanceRecordManualService $manualService,
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->queryService->list($this->resolveUser(), [
            'outletId' => request()->query('outletId'),
            'employeeId' => request()->query('employeeId'),
            'departmentId' => request()->query('departmentId'),
            'status' => request()->query('status'),
            'fromDate' => request()->query('fromDate'),
            'toDate' => request()->query('toDate'),
        ]);

        return response()->json([
            'data' => AttendanceRecordResource::collection($rows),
        ]);
    }

    public function show(int $attendance): JsonResponse
    {
        $row = $this->queryService->findAccessible($this->resolveUser(), $attendance);

        return response()->json([
            'data' => new AttendanceRecordResource($row),
        ]);
    }

    public function store(StoreAttendanceRecordRequest $request): JsonResponse
    {
        $row = $this->manualService->create($this->resolveUser(), $request->validated());

        return response()->json([
            'message' => 'Attendance record created.',
            'data' => new AttendanceRecordResource($row),
        ], Response::HTTP_CREATED);
    }

    public function import(): JsonResponse
    {
        $validated = request()->validate([
            'outletId' => ['required', 'integer', 'exists:outlets,id'],
            'csv' => ['required', 'string'],
            'filename' => ['nullable', 'string', 'max:255'],
            'employeeCodeColumn' => ['nullable', 'string', 'max:100'],
            'timestampColumn' => ['nullable', 'string', 'max:100'],
            'preview' => ['nullable', 'boolean'],
            'dryRun' => ['nullable', 'boolean'],
            'overwriteExisting' => ['nullable', 'boolean'],
        ]);

        $user = $this->resolveUser();
        $this->assertOutletAllowed($user, (int) $validated['outletId']);

        $result = $this->importService->importCsv($user, $validated);

        return response()->json([
            'message' => ($validated['preview'] ?? $validated['dryRun'] ?? false)
                ? 'Import preview generated.'
                : 'Attendance import completed.',
            'data' => [
                'preview' => $result['preview'],
                'created' => $result['created'],
                'skipped' => $result['skipped'],
                'batch' => $result['batch'] !== null
                    ? new AttendanceImportBatchResource($result['batch'])
                    : null,
            ],
        ], ($validated['preview'] ?? false) ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    public function update(int $attendance): JsonResponse
    {
        $validated = request()->validate([
            'clockIn' => ['nullable', 'string'],
            'clockOut' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $row = $this->correctionService->correct($this->resolveUser(), $attendance, $validated);

        return response()->json([
            'message' => 'Attendance updated successfully.',
            'data' => new AttendanceRecordResource($row),
        ]);
    }

    public function importBatches(): JsonResponse
    {
        $user = $this->resolveUser();
        $query = AttendanceImportBatch::query()->orderByDesc('imported_at');

        $outletId = request()->query('outletId');
        if ($outletId !== null) {
            $query->where('outlet_id', (int) $outletId);
        } elseif ($user !== null) {
            $allowed = $this->outletAccessResolver->allowedOutletIds($user);
            if ($allowed !== []) {
                $query->whereIn('outlet_id', $allowed);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return response()->json([
            'data' => AttendanceImportBatchResource::collection($query->limit(50)->get()),
        ]);
    }

    private function assertOutletAllowed(?\App\Models\User $user, int $outletId): void
    {
        if ($user === null) {
            return;
        }

        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowed, true)) {
            abort(Response::HTTP_FORBIDDEN, 'You cannot import attendance for this outlet.');
        }
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
