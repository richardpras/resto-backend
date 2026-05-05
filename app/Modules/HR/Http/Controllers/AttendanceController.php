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
            ->when(
                request()->filled('date'),
                fn ($query) => $query->whereDate('attendance_date', request()->query('date'))
            )
            ->latest('id')
            ->get();

        return response()->json([
            'data' => AttendanceResource::collection($attendances),
        ]);
    }

    public function store(): JsonResponse
    {
        $validated = request()->validate([
            'employeeId' => ['required', 'integer', 'exists:employees,id'],
            'shiftId' => ['nullable', 'integer', 'exists:shifts,id'],
            'date' => ['required', 'date'],
            'checkIn' => ['nullable', 'string', 'max:19'],
            'checkOut' => ['nullable', 'string', 'max:19'],
            'status' => ['required', 'in:present,late,absent'],
            'notes' => ['nullable', 'string'],
        ]);

        $attendance = Attendance::query()->create([
            'employee_id' => $validated['employeeId'],
            'shift_id' => $validated['shiftId'] ?? null,
            'attendance_date' => $validated['date'],
            'check_in' => $this->normalizeDateTime($validated['date'], $validated['checkIn'] ?? null),
            'check_out' => $this->normalizeDateTime($validated['date'], $validated['checkOut'] ?? null),
            'source' => 'manual',
            'status' => $validated['status'],
            'sync_key' => 'manual-'.uniqid(),
            'notes' => $validated['notes'] ?? null,
            'created_by' => request()->user()?->id,
            'updated_by' => request()->user()?->id,
        ]);

        return response()->json([
            'message' => 'Attendance created successfully.',
            'data' => new AttendanceResource($attendance),
        ], Response::HTTP_CREATED);
    }

    public function update(int $attendance): JsonResponse
    {
        $validated = request()->validate([
            'employeeId' => ['sometimes', 'integer', 'exists:employees,id'],
            'shiftId' => ['nullable', 'integer', 'exists:shifts,id'],
            'date' => ['sometimes', 'date'],
            'checkIn' => ['nullable', 'string', 'max:19'],
            'checkOut' => ['nullable', 'string', 'max:19'],
            'status' => ['sometimes', 'in:present,late,absent'],
            'notes' => ['nullable', 'string'],
        ]);

        $row = Attendance::query()->findOrFail($attendance);
        $targetDate = $validated['date'] ?? $row->attendance_date?->toDateString();
        $row->fill([
            'employee_id' => $validated['employeeId'] ?? $row->employee_id,
            'shift_id' => array_key_exists('shiftId', $validated) ? $validated['shiftId'] : $row->shift_id,
            'attendance_date' => $validated['date'] ?? $row->attendance_date,
            'check_in' => array_key_exists('checkIn', $validated) ? $this->normalizeDateTime($targetDate, $validated['checkIn']) : $row->check_in,
            'check_out' => array_key_exists('checkOut', $validated) ? $this->normalizeDateTime($targetDate, $validated['checkOut']) : $row->check_out,
            'status' => $validated['status'] ?? $row->status,
            'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $row->notes,
            'updated_by' => request()->user()?->id,
        ])->save();

        return response()->json([
            'message' => 'Attendance updated successfully.',
            'data' => new AttendanceResource($row->refresh()),
        ]);
    }

    public function destroy(int $attendance): JsonResponse
    {
        $row = Attendance::query()->findOrFail($attendance);
        $row->delete();

        return response()->json([
            'message' => 'Attendance deleted successfully.',
        ]);
    }

    private function normalizeDateTime(string $date, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
            return $date.' '.$value.':00';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return $date.' '.$value;
        }

        return $value;
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
