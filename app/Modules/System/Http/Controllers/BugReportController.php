<?php

namespace App\Modules\System\Http\Controllers;

use App\Models\User;
use App\Modules\System\Http\Requests\ListBugReportsRequest;
use App\Modules\System\Http\Requests\StoreBugReportCommentRequest;
use App\Modules\System\Http\Requests\StoreBugReportRequest;
use App\Modules\System\Http\Requests\UpdateBugReportRequest;
use App\Modules\System\Http\Resources\BugReportCommentResource;
use App\Modules\System\Http\Resources\BugReportResource;
use App\Modules\System\Services\BugReportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BugReportController
{
    public function __construct(
        private readonly BugReportService $service,
    ) {}

    public function store(StoreBugReportRequest $request): JsonResponse
    {
        $report = $this->service->create(
            $this->resolveUser($request),
            $request->validated(),
            $request->file('screenshot'),
        );

        return response()->json([
            'message' => 'Bug report submitted.',
            'data' => new BugReportResource($report),
        ], Response::HTTP_CREATED);
    }

    public function index(ListBugReportsRequest $request): JsonResponse
    {
        $paginator = $this->service->list($request->validated());

        return response()->json([
            'data' => BugReportResource::collection($paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(int $bugReport): JsonResponse
    {
        $report = $this->service->find($bugReport);

        return response()->json([
            'data' => new BugReportResource($report),
        ]);
    }

    public function update(UpdateBugReportRequest $request, int $bugReport): JsonResponse
    {
        $report = $this->service->update(
            $this->resolveUser($request),
            $bugReport,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Bug report updated.',
            'data' => new BugReportResource($report),
        ]);
    }

    public function storeComment(StoreBugReportCommentRequest $request, int $bugReport): JsonResponse
    {
        $comment = $this->service->addComment(
            $this->resolveUser($request),
            $bugReport,
            (string) $request->validated('comment'),
        );

        return response()->json([
            'message' => 'Comment added.',
            'data' => new BugReportCommentResource($comment),
        ], Response::HTTP_CREATED);
    }

    public function downloadAttachment(int $bugReport, int $attachment): StreamedResponse
    {
        return $this->service->streamAttachment($bugReport, $attachment);
    }

    private function resolveUser(\Illuminate\Http\Request $request): ?User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof User ? $user : null;
    }
}
