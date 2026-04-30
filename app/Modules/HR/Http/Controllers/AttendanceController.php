<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\HR\Domain\Attendance;
use App\Modules\HR\Http\Requests\ManualAttendanceCorrectionRequest;
use App\Modules\HR\Http\Requests\SyncAttendanceRequest;
use App\Modules\HR\Http\Resources\AttendanceResource;
use App\Modules\HR\Services\AttendanceCorrectionService;
use App\Modules\HR\Services\AttendanceSyncService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceSyncService $syncService,
        private readonly AttendanceCorrectionService $correctionService,
    ) {}

    public function index(): JsonResponse
    {
        $attendances = Attendance::query()
            ->when(
                request()->filled('employeeId'),
                fn ($query) => $query->where('employee_id', (int) request()->query('employeeId'))
            )
            ->when(
                request()->filled('attendanceDate'),
                fn ($query) => $query->whereDate('attendance_date', request()->query('attendanceDate'))
            )
            ->latest('id')
            ->get();

        return response()->json([
            'data' => AttendanceResource::collection($attendances),
        ]);
    }

    public function sync(SyncAttendanceRequest $request): JsonResponse
    {
        $result = $this->syncService->sync($request->validated());
        $status = $result['duplicate'] ? Response::HTTP_OK : Response::HTTP_CREATED;
        $message = $result['duplicate'] ? 'Attendance sync already processed.' : 'Attendance synced successfully.';

        return response()->json([
            'message' => $message,
            'duplicate' => $result['duplicate'],
            'data' => $result['attendance'] ? new AttendanceResource($result['attendance']) : null,
        ], $status);
    }

    public function manualCorrection(ManualAttendanceCorrectionRequest $request, Attendance $attendance): JsonResponse
    {
        $updatedAttendance = $this->correctionService->correct(
            attendanceId: (int) $attendance->id,
            payload: $request->validated(),
            actorUserId: (int) $request->user()->id,
        );

        return response()->json([
            'message' => 'Attendance corrected successfully.',
            'data' => new AttendanceResource($updatedAttendance),
        ]);
    }
}
