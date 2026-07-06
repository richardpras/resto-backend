<?php

namespace App\Modules\System\Services;

use App\Models\Modules\System\Domain\BugReport;
use App\Models\Modules\System\Domain\BugReportAttachment;
use App\Models\Modules\System\Domain\BugReportComment;
use App\Models\User;
use App\Modules\Notifications\Services\Adapters\BugReportNotificationAdapter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BugReportService
{
    private const MAX_SCREENSHOT_BYTES = 5 * 1024 * 1024;

    /** @var list<string> */
    private const ALLOWED_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/webp',
    ];

    public function __construct(
        private readonly BugReportDiagnosticsSanitizer $sanitizer,
        private readonly BugReportAuditService $auditService,
        private readonly BugReportNotificationAdapter $notificationAdapter,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(?User $user, array $data, ?UploadedFile $screenshot = null): BugReport
    {
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $diagnostics = $this->parseDiagnostics($data['diagnosticsJson'] ?? null);

        return DB::transaction(function () use ($user, $data, $screenshot, $diagnostics): BugReport {
            $report = BugReport::query()->create([
                'outlet_id' => isset($data['outletId']) ? (int) $data['outletId'] : null,
                'reporter_user_id' => (int) $user->id,
                'title' => (string) $data['title'],
                'message' => (string) $data['message'],
                'severity' => (string) ($data['severity'] ?? BugReport::SEVERITY_MEDIUM),
                'status' => BugReport::STATUS_OPEN,
                'current_route' => $data['currentRoute'] ?? null,
                'browser' => $data['browser'] ?? null,
                'user_agent' => $data['userAgent'] ?? null,
                'viewport' => $data['viewport'] ?? null,
                'app_version' => $data['appVersion'] ?? null,
                'diagnostics_json' => $this->sanitizer->sanitize($diagnostics),
            ]);

            if ($screenshot !== null) {
                $this->storeScreenshot($report, $screenshot);
            }

            $report->load(['reporter', 'assignee', 'attachments', 'comments.user']);

            $this->auditService->logCreated($report, $user);
            $this->notificationAdapter->notifyCreated($report);

            return $report;
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = BugReport::query()
            ->with(['reporter', 'assignee'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (isset($filters['outletId']) && (int) $filters['outletId'] > 0) {
            $query->where('outlet_id', (int) $filters['outletId']);
        }

        if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['severity']) && is_string($filters['severity']) && $filters['severity'] !== '') {
            $query->where('severity', $filters['severity']);
        }

        if (isset($filters['search']) && is_string($filters['search']) && trim($filters['search']) !== '') {
            $term = '%'.trim($filters['search']).'%';
            $query->where(function ($q) use ($term): void {
                $q->where('title', 'like', $term)
                    ->orWhere('message', 'like', $term)
                    ->orWhere('current_route', 'like', $term);
            });
        }

        if (isset($filters['createdFrom']) && is_string($filters['createdFrom']) && $filters['createdFrom'] !== '') {
            $query->whereDate('created_at', '>=', $filters['createdFrom']);
        }

        if (isset($filters['createdTo']) && is_string($filters['createdTo']) && $filters['createdTo'] !== '') {
            $query->whereDate('created_at', '<=', $filters['createdTo']);
        }

        $perPage = min(100, max(1, (int) ($filters['limit'] ?? 25)));

        return $query->paginate($perPage, ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
    }

    public function find(int $id): BugReport
    {
        $report = BugReport::query()
            ->with(['reporter', 'assignee', 'attachments', 'comments.user'])
            ->find($id);

        abort_if($report === null, Response::HTTP_NOT_FOUND, 'Bug report not found.');

        return $report;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(?User $user, int $id, array $data): BugReport
    {
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $report = $this->find($id);
        $previousStatus = (string) $report->status;
        $previousAssigneeId = $report->assigned_to_user_id !== null ? (int) $report->assigned_to_user_id : null;

        if (array_key_exists('status', $data)) {
            $report->status = (string) $data['status'];
            if (in_array($report->status, [BugReport::STATUS_CLOSED, BugReport::STATUS_FIXED, BugReport::STATUS_WONT_FIX], true)) {
                $report->resolved_at = $report->resolved_at ?? now();
            }
        }

        if (array_key_exists('assignedToUserId', $data)) {
            $report->assigned_to_user_id = $data['assignedToUserId'] !== null
                ? (int) $data['assignedToUserId']
                : null;
        }

        if (array_key_exists('severity', $data)) {
            $report->severity = (string) $data['severity'];
        }

        $report->save();
        $report->load(['reporter', 'assignee', 'attachments', 'comments.user']);

        if (array_key_exists('assignedToUserId', $data) && $report->assigned_to_user_id !== $previousAssigneeId) {
            $this->auditService->logAssigned($report, $user, $previousAssigneeId);
        }

        if (array_key_exists('status', $data) && $report->status !== $previousStatus) {
            $this->auditService->logStatusChanged($report, $user, $previousStatus);
            $this->notificationAdapter->notifyStatusUpdated($report, $previousStatus);
        }

        return $report;
    }

    public function addComment(?User $user, int $reportId, string $comment): BugReportComment
    {
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $report = $this->find($reportId);

        $row = BugReportComment::query()->create([
            'bug_report_id' => $report->id,
            'user_id' => (int) $user->id,
            'comment' => $comment,
            'created_at' => now(),
        ]);

        $row->load('user');
        $this->auditService->logCommented($report, $user, (int) $row->id);

        return $row;
    }

    public function streamAttachment(int $reportId, int $attachmentId): StreamedResponse
    {
        $attachment = BugReportAttachment::query()
            ->where('bug_report_id', $reportId)
            ->find($attachmentId);

        abort_if($attachment === null, Response::HTTP_NOT_FOUND, 'Attachment not found.');
        abort_if(
            ! Storage::disk('local')->exists($attachment->file_path),
            Response::HTTP_NOT_FOUND,
            'Attachment file missing.',
        );

        return Storage::disk('local')->response(
            $attachment->file_path,
            'bug-report-'.$reportId.'.png',
            ['Content-Type' => $attachment->file_type ?? 'application/octet-stream'],
        );
    }

    private function storeScreenshot(BugReport $report, UploadedFile $file): BugReportAttachment
    {
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw ValidationException::withMessages([
                'screenshot' => ['Screenshot must be PNG, JPEG, or WebP.'],
            ]);
        }

        if ((int) $file->getSize() > self::MAX_SCREENSHOT_BYTES) {
            throw ValidationException::withMessages([
                'screenshot' => ['Screenshot must be 5MB or smaller.'],
            ]);
        }

        $path = $file->store(
            sprintf('bug-reports/%d/%d', (int) ($report->outlet_id ?? 0), (int) $report->id),
            'local',
        );

        return BugReportAttachment::query()->create([
            'bug_report_id' => $report->id,
            'file_path' => $path,
            'file_type' => $mime,
            'file_size' => (int) $file->getSize(),
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseDiagnostics(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
