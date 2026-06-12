<?php

namespace App\Modules\ShiftClose\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftCloseAlreadyRunningException extends Exception
{
    public function __construct(
        public readonly int $outletId,
        public readonly string $shiftDate,
    ) {
        parent::__construct('Shift close is already running for this outlet and date.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'SHIFT_CLOSE_ALREADY_RUNNING',
            'outletId' => $this->outletId,
            'shiftDate' => $this->shiftDate,
        ], 409);
    }
}
