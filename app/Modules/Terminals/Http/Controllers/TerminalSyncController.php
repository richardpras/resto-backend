<?php

namespace App\Modules\Terminals\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Terminals\Http\Requests\TerminalSyncBatchRequest;
use App\Modules\Terminals\Services\TerminalSyncBatchService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class TerminalSyncController extends Controller
{
    public function __construct(
        private readonly TerminalSyncBatchService $batchService,
    ) {}

    public function batch(TerminalSyncBatchRequest $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $payload = $this->batchService->processBatch($user, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Sync batch processed.',
            'data' => $payload,
            'meta' => null,
        ]);
    }
}
