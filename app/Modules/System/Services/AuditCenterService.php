<?php

namespace App\Modules\System\Services;

use App\Models\Modules\GiftCards\Domain\GiftCardEvent;
use App\Models\Modules\GiftCards\Domain\GiftCardLedger;
use App\Models\Modules\HR\Domain\AttendanceAuditLog;
use App\Models\Modules\HR\Domain\PayrollRunAudit;
use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Print\Domain\PrintReprintAudit;
use App\Models\Modules\UserManagement\Domain\UserManagementAuditLog;
use App\Models\User;
use App\Modules\System\DTO\UnifiedAuditRecord;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AuditCenterService
{
  private const MAX_FETCH_PER_SOURCE = 500;

  public function __construct(
    private readonly AuditRiskClassificationService $riskClassification,
  ) {}

  /**
   * @param  array<string, mixed>  $filters
   * @return array{data: list<UnifiedAuditRecord>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
   */
  public function listTimeline(array $filters = []): array
  {
    $page = max(1, (int) ($filters['page'] ?? 1));
    $perPage = min(100, max(1, (int) ($filters['limit'] ?? 20)));
    $fetchLimit = min(self::MAX_FETCH_PER_SOURCE, $page * $perPage);

    $records = $this->collectFromAllSources($filters, $fetchLimit);
    $sorted = $records->sortByDesc(fn (UnifiedAuditRecord $r): string => $r->timestamp)->values();

    $total = $this->countAllSources($filters);
    $offset = ($page - 1) * $perPage;
    $pageItems = $sorted->slice($offset, $perPage)->values()->all();

    $lastPage = max(1, (int) ceil($total / $perPage));

    return [
      'data' => $pageItems,
      'meta' => [
        'currentPage' => $page,
        'lastPage' => $lastPage,
        'perPage' => $perPage,
        'total' => $total,
      ],
    ];
  }

  /**
   * @return list<UnifiedAuditRecord>
   */
  public function getEntityHistory(string $entityType, int $entityId, ?int $outletId = null): array
  {
    $filters = [
      'entityType' => $entityType,
      'entityId' => $entityId,
    ];
    if ($outletId !== null && $outletId > 0) {
      $filters['outletId'] = $outletId;
    }

    $records = $this->collectFromAllSources($filters, self::MAX_FETCH_PER_SOURCE);

    return $records
      ->sortBy(fn (UnifiedAuditRecord $r): string => $r->timestamp)
      ->values()
      ->all();
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return array{data: list<UnifiedAuditRecord>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
   */
  public function search(string $query, array $filters = []): array
  {
    $filters['search'] = trim($query);
    if ($filters['search'] === '') {
      return [
        'data' => [],
        'meta' => ['currentPage' => 1, 'lastPage' => 1, 'perPage' => 20, 'total' => 0],
      ];
    }

    return $this->listTimeline($filters);
  }

  /**
   * @return array{
   *     todayEvents: int,
   *     activeUsers: int,
   *     financialChanges: int,
   *     approvals: int,
   *     criticalEvents: int,
   *     topActors: list<array{userId: int, userName: string, count: int}>,
   *     topModules: list<array{module: string, count: int}>,
   *     riskEvents: list<UnifiedAuditRecord>
   * }
   */
  public function dashboardSummary(?int $outletId = null): array
  {
    $todayStart = now()->startOfDay();
    $filters = [
      'startDate' => $todayStart->toIso8601String(),
      'endDate' => now()->toIso8601String(),
    ];
    if ($outletId !== null && $outletId > 0) {
      $filters['outletId'] = $outletId;
    }

    $records = $this->collectFromAllSources($filters, self::MAX_FETCH_PER_SOURCE);

    $financialModules = ['accounting', 'payments', 'gift_cards', 'purchase'];
    $approvalPatterns = ['approv', 'submitted', 'finalized'];

    $financialChanges = $records->filter(
      fn (UnifiedAuditRecord $r): bool => in_array($r->module, $financialModules, true)
        && ($r->metadata['riskLevel'] ?? '') !== AuditRiskClassificationService::RISK_INFO,
    )->count();

    $approvals = $records->filter(function (UnifiedAuditRecord $r) use ($approvalPatterns): bool {
      foreach ($approvalPatterns as $pattern) {
        if (str_contains(strtolower($r->action), $pattern)) {
          return true;
        }
      }

      return false;
    })->count();

    $criticalEvents = $records->filter(
      fn (UnifiedAuditRecord $r): bool => ($r->metadata['riskLevel'] ?? '') === AuditRiskClassificationService::RISK_CRITICAL,
    );

    $topActors = $records
      ->filter(fn (UnifiedAuditRecord $r): bool => $r->userId !== null)
      ->groupBy(fn (UnifiedAuditRecord $r): int => $r->userId)
      ->map(function (Collection $group, int $userId): array {
        $first = $group->first();

        return [
          'userId' => $userId,
          'userName' => $first?->userName ?? 'User #'.$userId,
          'count' => $group->count(),
        ];
      })
      ->sortByDesc('count')
      ->take(5)
      ->values()
      ->all();

    $topModules = $records
      ->groupBy(fn (UnifiedAuditRecord $r): string => $r->module)
      ->map(fn (Collection $group, string $module): array => [
        'module' => $module,
        'count' => $group->count(),
      ])
      ->sortByDesc('count')
      ->take(5)
      ->values()
      ->all();

    $activeUserIds = $records
      ->pluck('userId')
      ->filter()
      ->unique()
      ->count();

    return [
      'todayEvents' => $records->count(),
      'activeUsers' => $activeUserIds,
      'financialChanges' => $financialChanges,
      'approvals' => $approvals,
      'criticalEvents' => $criticalEvents->count(),
      'topActors' => $topActors,
      'topModules' => $topModules,
      'riskEvents' => $criticalEvents->take(10)->values()->all(),
    ];
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return Collection<int, UnifiedAuditRecord>
   */
  private function collectFromAllSources(array $filters, int $limit): Collection
  {
    $records = collect();

    if ($this->shouldIncludeSource($filters, 'pos_event_logs')) {
      $records = $records->merge($this->collectPosEventLogs($filters, $limit));
    }

    if ($this->shouldIncludeDedicatedSource($filters, 'payroll_run')) {
      $records = $records->merge($this->collectPayrollAudits($filters, $limit));
    }

    if ($this->shouldIncludeDedicatedSource($filters, 'attendance')) {
      $records = $records->merge($this->collectAttendanceAudits($filters, $limit));
    }

    if ($this->shouldIncludeDedicatedSource($filters, 'gift_card_issuance')) {
      $records = $records->merge($this->collectGiftCardEvents($filters, $limit));
      $records = $records->merge($this->collectGiftCardLedgers($filters, $limit));
    }

    if ($this->shouldIncludeDedicatedSource($filters, 'print_reprint')) {
      $records = $records->merge($this->collectPrintReprints($filters, $limit));
    }

    if ($this->shouldIncludeUserManagementSource($filters)) {
      $records = $records->merge($this->collectUserManagementAudits($filters, $limit));
    }

    return $records;
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  private function countAllSources(array $filters): int
  {
    $count = 0;

    if ($this->shouldIncludeSource($filters, 'pos_event_logs')) {
      $count += $this->applyPosEventFilters(PosEventLog::query(), $filters)->count();
    }

    if ($this->shouldIncludeDedicatedSource($filters, 'payroll_run')) {
      $count += $this->applyPayrollFilters(PayrollRunAudit::query(), $filters)->count();
    }

    if ($this->shouldIncludeDedicatedSource($filters, 'attendance')) {
      $count += $this->applyAttendanceFilters(AttendanceAuditLog::query(), $filters)->count();
    }

    if ($this->shouldIncludeDedicatedSource($filters, 'gift_card_issuance')) {
      $count += $this->applyGiftCardEventFilters(GiftCardEvent::query(), $filters)->count();
      $count += $this->applyGiftCardLedgerFilters(GiftCardLedger::query(), $filters)->count();
    }

    if ($this->shouldIncludeDedicatedSource($filters, 'print_reprint')) {
      $count += $this->applyPrintFilters(PrintReprintAudit::query(), $filters)->count();
    }

    if ($this->shouldIncludeUserManagementSource($filters)) {
      $count += $this->applyUserManagementFilters(UserManagementAuditLog::query(), $filters)->count();
    }

    return $count;
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return Collection<int, UnifiedAuditRecord>
   */
  private function collectPosEventLogs(array $filters, int $limit): Collection
  {
    $rows = $this->applyPosEventFilters(PosEventLog::query(), $filters)
      ->orderByDesc('occurred_at')
      ->orderByDesc('id')
      ->limit($limit)
      ->get();

    $userNames = $this->resolveUserNames($rows->pluck('actor_user_id')->filter()->unique()->all());

    return $rows->map(function (PosEventLog $row) use ($userNames): UnifiedAuditRecord {
      $module = $this->resolveModule((string) $row->entity_type, (string) $row->event_type);
      $action = (string) $row->event_type;
      $payload = is_array($row->payload) ? $row->payload : [];
      [$before, $after] = $this->extractBeforeAfter($payload);

      return $this->buildRecord(
        'pos:'.$row->id,
        $module,
        (string) $row->entity_type,
        (int) $row->entity_id,
        $action,
        $row->actor_user_id !== null ? (int) $row->actor_user_id : null,
        $row->actor_user_id !== null ? ($userNames[(int) $row->actor_user_id] ?? null) : null,
        $row->outlet_id !== null ? (int) $row->outlet_id : null,
        $row->occurred_at?->toIso8601String() ?? $row->created_at?->toIso8601String() ?? now()->toIso8601String(),
        $before,
        $after,
        array_merge($payload, ['source' => 'pos_event_logs']),
      );
    });
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return Collection<int, UnifiedAuditRecord>
   */
  private function collectPayrollAudits(array $filters, int $limit): Collection
  {
    $rows = $this->applyPayrollFilters(PayrollRunAudit::query()->with('performedByUser'), $filters)
      ->orderByDesc('created_at')
      ->orderByDesc('id')
      ->limit($limit)
      ->get();

    return $rows->map(function (PayrollRunAudit $row): UnifiedAuditRecord {
      $user = $row->performedByUser;

      return $this->buildRecord(
        'payroll:'.$row->id,
        'payroll',
        'payroll_run',
        (int) $row->payroll_run_id,
        (string) $row->action,
        $row->performed_by !== null ? (int) $row->performed_by : null,
        $user?->name,
        null,
        $row->created_at?->toIso8601String() ?? now()->toIso8601String(),
        [],
        [],
        [
          'source' => 'payroll_run_audits',
          'notes' => $row->notes,
        ],
      );
    });
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return Collection<int, UnifiedAuditRecord>
   */
  private function collectAttendanceAudits(array $filters, int $limit): Collection
  {
    $rows = $this->applyAttendanceFilters(AttendanceAuditLog::query()->with('actor'), $filters)
      ->orderByDesc('created_at')
      ->orderByDesc('id')
      ->limit($limit)
      ->get();

    return $rows->map(function (AttendanceAuditLog $row): UnifiedAuditRecord {
      return $this->buildRecord(
        'attendance:'.$row->id,
        'hr',
        'attendance',
        (int) $row->attendance_id,
        (string) $row->action,
        $row->actor_user_id !== null ? (int) $row->actor_user_id : null,
        $row->actor?->name,
        null,
        $row->created_at?->toIso8601String() ?? now()->toIso8601String(),
        is_array($row->before_json) ? $row->before_json : [],
        is_array($row->after_json) ? $row->after_json : [],
        [
          'source' => 'attendance_audit_logs',
          'reason' => $row->reason,
          'sourceType' => $row->source_type,
        ],
      );
    });
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return Collection<int, UnifiedAuditRecord>
   */
  private function collectGiftCardEvents(array $filters, int $limit): Collection
  {
    $rows = $this->applyGiftCardEventFilters(GiftCardEvent::query(), $filters)
      ->orderByDesc('occurred_at')
      ->orderByDesc('id')
      ->limit($limit)
      ->get();

    return $rows->map(function (GiftCardEvent $row): UnifiedAuditRecord {
      $payload = is_array($row->payload) ? $row->payload : [];
      [$before, $after] = $this->extractBeforeAfter($payload);

      return $this->buildRecord(
        'gift_event:'.$row->id,
        'gift_cards',
        'gift_card_issuance',
        (int) $row->issuance_id,
        (string) $row->event_type,
        null,
        null,
        $row->outlet_id !== null ? (int) $row->outlet_id : null,
        $row->occurred_at?->toIso8601String() ?? $row->created_at?->toIso8601String() ?? now()->toIso8601String(),
        $before,
        $after,
        array_merge($payload, ['source' => 'gift_card_events']),
      );
    });
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return Collection<int, UnifiedAuditRecord>
   */
  private function collectGiftCardLedgers(array $filters, int $limit): Collection
  {
    $rows = $this->applyGiftCardLedgerFilters(GiftCardLedger::query()->with('actor'), $filters)
      ->orderByDesc('occurred_at')
      ->orderByDesc('id')
      ->limit($limit)
      ->get();

    return $rows->map(function (GiftCardLedger $row): UnifiedAuditRecord {
      return $this->buildRecord(
        'gift_ledger:'.$row->id,
        'gift_cards',
        'gift_card_issuance',
        (int) $row->issuance_id,
        (string) $row->transaction_type,
        $row->created_by_user_id !== null ? (int) $row->created_by_user_id : null,
        $row->actor?->name,
        $row->outlet_id !== null ? (int) $row->outlet_id : null,
        $row->occurred_at?->toIso8601String() ?? $row->created_at?->toIso8601String() ?? now()->toIso8601String(),
        ['balance' => $row->balance_before],
        ['balance' => $row->balance_after],
        [
          'source' => 'gift_card_ledgers',
          'amountDelta' => $row->amount_delta,
          'referenceType' => $row->reference_type,
          'referenceId' => $row->reference_id,
        ],
      );
    });
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return Collection<int, UnifiedAuditRecord>
   */
  private function collectPrintReprints(array $filters, int $limit): Collection
  {
    $rows = $this->applyPrintFilters(PrintReprintAudit::query()->with('user'), $filters)
      ->orderByDesc('created_at')
      ->orderByDesc('id')
      ->limit($limit)
      ->get();

    return $rows->map(function (PrintReprintAudit $row): UnifiedAuditRecord {
      $meta = is_array($row->meta) ? $row->meta : [];

      return $this->buildRecord(
        'print:'.$row->id,
        'pos',
        'print_reprint',
        (int) $row->receipt_render_history_id,
        (string) $row->action,
        $row->user_id !== null ? (int) $row->user_id : null,
        $row->user?->name ?? null,
        (int) $row->outlet_id,
        $row->created_at?->toIso8601String() ?? now()->toIso8601String(),
        [],
        [],
        array_merge($meta, [
          'source' => 'print_reprint_audits',
          'reason' => $row->reason,
          'printJobId' => $row->print_job_id,
        ]),
      );
    });
  }

  /**
   * @param  array<string, mixed>  $metadata
   */
  private function buildRecord(
    string $id,
    string $module,
    string $entityType,
    int $entityId,
    string $action,
    ?int $userId,
    ?string $userName,
    ?int $outletId,
    string $timestamp,
    array $before,
    array $after,
    array $metadata,
  ): UnifiedAuditRecord {
    $riskLevel = $this->riskClassification->classify($module, $entityType, $action);
    $metadata['riskLevel'] = $riskLevel;

    return new UnifiedAuditRecord(
      $id,
      $module,
      $entityType,
      $entityId,
      $action,
      $userId,
      $userName,
      $outletId,
      $timestamp,
      $before,
      $after,
      $metadata,
    );
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  private function shouldIncludeSource(array $filters, string $sourceKey): bool
  {
    $entityType = isset($filters['entityType']) ? (string) $filters['entityType'] : null;
    if ($entityType === null) {
      return true;
    }

    $dedicatedTypes = [
      'payroll_run' => 'payroll_run_audits',
      'attendance' => 'attendance_audit_logs',
      'gift_card_issuance' => 'gift_card',
      'print_reprint' => 'print_reprint_audits',
      'user' => 'user_management_audit_logs',
      'role' => 'user_management_audit_logs',
      'permission' => 'user_management_audit_logs',
    ];

    if (isset($dedicatedTypes[$entityType])) {
      return false;
    }

    return $sourceKey === 'pos_event_logs';
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  private function shouldIncludeDedicatedSource(array $filters, string $entityType): bool
  {
    $filterEntityType = isset($filters['entityType']) ? (string) $filters['entityType'] : null;
    if ($filterEntityType === null) {
      return true;
    }

    return $filterEntityType === $entityType;
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  private function shouldIncludeUserManagementSource(array $filters): bool
  {
    $filterEntityType = isset($filters['entityType']) ? (string) $filters['entityType'] : null;
    if ($filterEntityType === null) {
      return true;
    }

    return in_array($filterEntityType, ['user', 'role', 'permission'], true);
  }

  /**
   * @param  array<string, mixed>  $filters
   * @return Collection<int, UnifiedAuditRecord>
   */
  private function collectUserManagementAudits(array $filters, int $limit): Collection
  {
    $rows = $this->applyUserManagementFilters(UserManagementAuditLog::query()->with(['actor', 'targetUser']), $filters)
      ->orderByDesc('created_at')
      ->orderByDesc('id')
      ->limit($limit)
      ->get();

    return $rows->map(function (UserManagementAuditLog $row): UnifiedAuditRecord {
      $before = is_array($row->before_json) ? $row->before_json : [];
      $after = is_array($row->after_json) ? $row->after_json : [];
      $metadata = is_array($row->metadata) ? $row->metadata : [];

      return $this->buildRecord(
        'user_mgmt:'.$row->id,
        'user_management',
        (string) $row->entity_type,
        (int) $row->entity_id,
        (string) $row->action,
        $row->actor_user_id !== null ? (int) $row->actor_user_id : null,
        $row->actor?->name,
        null,
        $row->created_at?->toIso8601String() ?? now()->toIso8601String(),
        $before,
        $after,
        array_merge($metadata, [
          'source' => 'user_management_audit_logs',
          'targetUserId' => $row->target_user_id,
          'targetUserName' => $row->targetUser?->name,
        ]),
      );
    });
  }

  /**
   * @param  Builder<UserManagementAuditLog>  $query
   * @param  array<string, mixed>  $filters
   * @return Builder<UserManagementAuditLog>
   */
  private function applyUserManagementFilters(Builder $query, array $filters): Builder
  {
    if (isset($filters['entityType']) && is_string($filters['entityType']) && $filters['entityType'] !== '') {
      $query->where('entity_type', (string) $filters['entityType']);
    }

    if (isset($filters['entityId']) && (int) $filters['entityId'] > 0) {
      $query->where('entity_id', (int) $filters['entityId']);
    }

    if (isset($filters['userId']) && (int) $filters['userId'] > 0) {
      $query->where('actor_user_id', (int) $filters['userId']);
    }

    if (isset($filters['action']) && is_string($filters['action']) && $filters['action'] !== '') {
      $query->where('action', 'like', '%'.$filters['action'].'%');
    }

    if (isset($filters['module']) && (string) $filters['module'] !== '' && (string) $filters['module'] !== 'user_management') {
      $query->whereRaw('1 = 0');
    }

    $this->applyDateRange($query, 'created_at', $filters);

    if (isset($filters['search']) && is_string($filters['search']) && $filters['search'] !== '') {
      $term = '%'.$filters['search'].'%';
      $query->where(function (Builder $q) use ($term, $filters): void {
        $q->where('action', 'like', $term)
          ->orWhere('entity_type', 'like', $term)
          ->orWhere('before_json', 'like', $term)
          ->orWhere('after_json', 'like', $term)
          ->orWhere('metadata', 'like', $term);

        if (is_numeric($filters['search'])) {
          $q->orWhere('entity_id', (int) $filters['search'])
            ->orWhere('target_user_id', (int) $filters['search'])
            ->orWhere('actor_user_id', (int) $filters['search']);
        }
      });
    }

    return $query;
  }

  /**
   * @param  Builder<PosEventLog>  $query
   * @param  array<string, mixed>  $filters
   * @return Builder<PosEventLog>
   */
  private function applyPosEventFilters(Builder $query, array $filters): Builder
  {
    if (isset($filters['outletId']) && (int) $filters['outletId'] > 0) {
      $query->where('outlet_id', (int) $filters['outletId']);
    }

    if (isset($filters['userId']) && (int) $filters['userId'] > 0) {
      $query->where('actor_user_id', (int) $filters['userId']);
    }

    if (isset($filters['entityType']) && is_string($filters['entityType']) && $filters['entityType'] !== '') {
      $entityType = (string) $filters['entityType'];
      if (! in_array($entityType, ['payroll_run', 'attendance', 'gift_card_issuance', 'print_reprint', 'user', 'role', 'permission'], true)) {
        $query->where('entity_type', $entityType);
      }
    }

    if (isset($filters['entityId']) && (int) $filters['entityId'] > 0) {
      $query->where('entity_id', (int) $filters['entityId']);
    }

    if (isset($filters['action']) && is_string($filters['action']) && $filters['action'] !== '') {
      $query->where('event_type', 'like', '%'.$filters['action'].'%');
    }

    if (isset($filters['module']) && is_string($filters['module']) && $filters['module'] !== '') {
      $this->applyModuleFilterToPosEvents($query, (string) $filters['module']);
    }

    $this->applyDateRange($query, 'occurred_at', $filters);

    if (isset($filters['search']) && is_string($filters['search']) && $filters['search'] !== '') {
      $term = '%'.$filters['search'].'%';
      $query->where(function (Builder $q) use ($term, $filters): void {
        $q->where('event_type', 'like', $term)
          ->orWhere('entity_type', 'like', $term)
          ->orWhere('payload', 'like', $term);

        if (is_numeric($filters['search'])) {
          $q->orWhere('entity_id', (int) $filters['search']);
        }
      });
    }

    return $query;
  }

  /**
   * @param  Builder<PayrollRunAudit>  $query
   * @param  array<string, mixed>  $filters
   * @return Builder<PayrollRunAudit>
   */
  private function applyPayrollFilters(Builder $query, array $filters): Builder
  {
    if (isset($filters['entityId']) && (int) $filters['entityId'] > 0) {
      $query->where('payroll_run_id', (int) $filters['entityId']);
    }

    if (isset($filters['userId']) && (int) $filters['userId'] > 0) {
      $query->where('performed_by', (int) $filters['userId']);
    }

    if (isset($filters['action']) && is_string($filters['action']) && $filters['action'] !== '') {
      $query->where('action', 'like', '%'.$filters['action'].'%');
    }

    if (isset($filters['module']) && (string) $filters['module'] !== '' && (string) $filters['module'] !== 'payroll') {
      $query->whereRaw('1 = 0');
    }

    $this->applyDateRange($query, 'created_at', $filters);

    if (isset($filters['search']) && is_string($filters['search']) && $filters['search'] !== '') {
      $term = '%'.$filters['search'].'%';
      $query->where(function (Builder $q) use ($term, $filters): void {
        $q->where('action', 'like', $term)
          ->orWhere('notes', 'like', $term);
        if (is_numeric($filters['search'])) {
          $q->orWhere('payroll_run_id', (int) $filters['search']);
        }
      });
    }

    return $query;
  }

  /**
   * @param  Builder<AttendanceAuditLog>  $query
   * @param  array<string, mixed>  $filters
   * @return Builder<AttendanceAuditLog>
   */
  private function applyAttendanceFilters(Builder $query, array $filters): Builder
  {
    if (isset($filters['entityId']) && (int) $filters['entityId'] > 0) {
      $query->where('attendance_id', (int) $filters['entityId']);
    }

    if (isset($filters['userId']) && (int) $filters['userId'] > 0) {
      $query->where('actor_user_id', (int) $filters['userId']);
    }

    if (isset($filters['action']) && is_string($filters['action']) && $filters['action'] !== '') {
      $query->where('action', 'like', '%'.$filters['action'].'%');
    }

    if (isset($filters['module']) && (string) $filters['module'] !== '' && (string) $filters['module'] !== 'hr') {
      $query->whereRaw('1 = 0');
    }

    $this->applyDateRange($query, 'created_at', $filters);

    if (isset($filters['search']) && is_string($filters['search']) && $filters['search'] !== '') {
      $term = '%'.$filters['search'].'%';
      $query->where(function (Builder $q) use ($term): void {
        $q->where('action', 'like', $term)
          ->orWhere('reason', 'like', $term)
          ->orWhere('before_json', 'like', $term)
          ->orWhere('after_json', 'like', $term);
      });
    }

    return $query;
  }

  /**
   * @param  Builder<GiftCardEvent>  $query
   * @param  array<string, mixed>  $filters
   * @return Builder<GiftCardEvent>
   */
  private function applyGiftCardEventFilters(Builder $query, array $filters): Builder
  {
    if (isset($filters['outletId']) && (int) $filters['outletId'] > 0) {
      $query->where('outlet_id', (int) $filters['outletId']);
    }

    if (isset($filters['entityId']) && (int) $filters['entityId'] > 0) {
      $query->where('issuance_id', (int) $filters['entityId']);
    }

    if (isset($filters['action']) && is_string($filters['action']) && $filters['action'] !== '') {
      $query->where('event_type', 'like', '%'.$filters['action'].'%');
    }

    if (isset($filters['module']) && (string) $filters['module'] !== '' && (string) $filters['module'] !== 'gift_cards') {
      $query->whereRaw('1 = 0');
    }

    $this->applyDateRange($query, 'occurred_at', $filters);

    if (isset($filters['search']) && is_string($filters['search']) && $filters['search'] !== '') {
      $term = '%'.$filters['search'].'%';
      $query->where(function (Builder $q) use ($term, $filters): void {
        $q->where('event_type', 'like', $term)
          ->orWhere('payload', 'like', $term);
        if (is_numeric($filters['search'])) {
          $q->orWhere('issuance_id', (int) $filters['search']);
        }
      });
    }

    return $query;
  }

  /**
   * @param  Builder<GiftCardLedger>  $query
   * @param  array<string, mixed>  $filters
   * @return Builder<GiftCardLedger>
   */
  private function applyGiftCardLedgerFilters(Builder $query, array $filters): Builder
  {
    if (isset($filters['outletId']) && (int) $filters['outletId'] > 0) {
      $query->where('outlet_id', (int) $filters['outletId']);
    }

    if (isset($filters['entityId']) && (int) $filters['entityId'] > 0) {
      $query->where('issuance_id', (int) $filters['entityId']);
    }

    if (isset($filters['userId']) && (int) $filters['userId'] > 0) {
      $query->where('created_by_user_id', (int) $filters['userId']);
    }

    if (isset($filters['action']) && is_string($filters['action']) && $filters['action'] !== '') {
      $query->where('transaction_type', 'like', '%'.$filters['action'].'%');
    }

    if (isset($filters['module']) && (string) $filters['module'] !== '' && (string) $filters['module'] !== 'gift_cards') {
      $query->whereRaw('1 = 0');
    }

    $this->applyDateRange($query, 'occurred_at', $filters);

    if (isset($filters['search']) && is_string($filters['search']) && $filters['search'] !== '') {
      $term = '%'.$filters['search'].'%';
      $query->where(function (Builder $q) use ($term, $filters): void {
        $q->where('transaction_type', 'like', $term)
          ->orWhere('reference_type', 'like', $term)
          ->orWhere('meta', 'like', $term);
        if (is_numeric($filters['search'])) {
          $q->orWhere('issuance_id', (int) $filters['search']);
        }
      });
    }

    return $query;
  }

  /**
   * @param  Builder<PrintReprintAudit>  $query
   * @param  array<string, mixed>  $filters
   * @return Builder<PrintReprintAudit>
   */
  private function applyPrintFilters(Builder $query, array $filters): Builder
  {
    if (isset($filters['outletId']) && (int) $filters['outletId'] > 0) {
      $query->where('outlet_id', (int) $filters['outletId']);
    }

    if (isset($filters['userId']) && (int) $filters['userId'] > 0) {
      $query->where('user_id', (int) $filters['userId']);
    }

    if (isset($filters['action']) && is_string($filters['action']) && $filters['action'] !== '') {
      $query->where('action', 'like', '%'.$filters['action'].'%');
    }

    if (isset($filters['module']) && (string) $filters['module'] !== '' && (string) $filters['module'] !== 'pos') {
      $query->whereRaw('1 = 0');
    }

    $this->applyDateRange($query, 'created_at', $filters);

    return $query;
  }

  /**
   * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
   * @param  array<string, mixed>  $filters
   */
  private function applyDateRange(Builder $query, string $column, array $filters): void
  {
    if (isset($filters['startDate']) && is_string($filters['startDate']) && $filters['startDate'] !== '') {
      $query->where($column, '>=', Carbon::parse($filters['startDate']));
    }

    if (isset($filters['endDate']) && is_string($filters['endDate']) && $filters['endDate'] !== '') {
      $query->where($column, '<=', Carbon::parse($filters['endDate']));
    }
  }

  /**
   * @param  Builder<PosEventLog>  $query
   */
  private function applyModuleFilterToPosEvents(Builder $query, string $module): void
  {
    $module = strtolower($module);

    $entityMap = [
      'purchase' => ['purchase_request', 'purchase_order', 'goods_receiving_note', 'purchase_invoice', 'supplier_payment', 'procurement_posting', 'procurement_analytics'],
      'accounting' => ['journal', 'accounting_health', 'accounting_settings', 'accounting_scope'],
      'inventory' => ['ingredient', 'stock_movement', 'inventory_valuation'],
      'payments' => ['order_payment', 'payment_transaction'],
      'pos' => ['order', 'session', 'split', 'kitchen_ticket', 'qr_request', 'print_reprint'],
      'menu' => ['menu_item', 'dashboard_snapshot', 'forecast_snapshot', 'automation_alert', 'menu_intelligence', 'outlet'],
      'gift_cards' => ['gift_card_issuance', 'gift_card'],
      'notifications' => ['automation_alert'],
      'user_management' => ['user', 'role', 'permission'],
    ];

    if (isset($entityMap[$module])) {
      $query->where(function (Builder $q) use ($module, $entityMap): void {
        $q->whereIn('entity_type', $entityMap[$module]);

        if ($module === 'purchase') {
          $q->orWhere('event_type', 'like', 'purchase_%')
            ->orWhere('event_type', 'like', 'goods_receipt%')
            ->orWhere('event_type', 'like', 'procurement_%');
        }

        if ($module === 'accounting') {
          $q->orWhere('event_type', 'like', '%journal%')
            ->orWhere('event_type', 'like', '%reversal%')
            ->orWhere('event_type', 'like', '%posting%')
            ->orWhere('event_type', 'like', 'accounting.%');
        }

        if ($module === 'inventory') {
          $q->orWhere('event_type', 'like', 'inventory%');
        }

        if ($module === 'payments') {
          $q->orWhere('event_type', 'like', '%payment%');
        }

        if ($module === 'gift_cards') {
          $q->orWhere('event_type', 'like', '%gift_card%');
        }

        if ($module === 'menu') {
          $q->orWhere('event_type', 'like', '%forecast%')
            ->orWhere('event_type', 'like', '%dashboard%')
            ->orWhere('event_type', 'like', '%automation%')
            ->orWhere('event_type', 'like', '%menu_engineering%')
            ->orWhere('event_type', 'like', '%optimization%')
            ->orWhere('event_type', 'like', '%analytics%');
        }
      });

      return;
    }

    if ($module === 'payroll' || $module === 'hr') {
      $query->whereRaw('1 = 0');
    }
  }

  private function resolveModule(string $entityType, string $eventType): string
  {
    $purchaseEntities = ['purchase_request', 'purchase_order', 'goods_receiving_note', 'purchase_invoice', 'supplier_payment', 'procurement_posting', 'procurement_analytics'];
    if (in_array($entityType, $purchaseEntities, true)
      || str_starts_with($eventType, 'purchase_')
      || str_starts_with($eventType, 'goods_receipt')
      || str_starts_with($eventType, 'procurement_')) {
      return 'purchase';
    }

    if (in_array($entityType, ['journal', 'accounting_health', 'accounting_settings', 'accounting_scope'], true)
      || str_contains($eventType, 'journal')
      || str_contains($eventType, 'reversal')
      || str_contains($eventType, 'posting')
      || str_starts_with($eventType, 'accounting.')) {
      return 'accounting';
    }

    if (str_starts_with($eventType, 'inventory') || in_array($entityType, ['ingredient', 'stock_movement', 'inventory_valuation'], true)) {
      return 'inventory';
    }

    if (in_array($entityType, ['order_payment', 'payment_transaction'], true) || str_contains($eventType, 'payment')) {
      return 'payments';
    }

    if (str_contains($eventType, 'gift_card')) {
      return 'gift_cards';
    }

    if (in_array($entityType, ['menu_item', 'dashboard_snapshot', 'forecast_snapshot', 'automation_alert', 'menu_intelligence'], true)
      || str_contains($eventType, 'forecast')
      || str_contains($eventType, 'dashboard')
      || str_contains($eventType, 'automation')
      || str_contains($eventType, 'menu_engineering')
      || str_contains($eventType, 'optimization')
      || str_contains($eventType, 'analytics')) {
      return 'menu';
    }

    if (str_contains($eventType, 'notification')) {
      return 'notifications';
    }

    if (in_array($entityType, ['user', 'role', 'permission'], true)
      || str_starts_with($eventType, 'user.')
      || str_starts_with($eventType, 'role.')
      || str_starts_with($eventType, 'permission.')) {
      return 'user_management';
    }

    if (in_array($entityType, ['order', 'session', 'split', 'kitchen_ticket', 'qr_request', 'print_reprint'], true)) {
      return 'pos';
    }

    return 'system';
  }

  /**
   * @param  array<string, mixed>  $payload
   * @return array{0: array<string, mixed>, 1: array<string, mixed>}
   */
  private function extractBeforeAfter(array $payload): array
  {
    $before = [];
    $after = [];

    if (isset($payload['before']) && is_array($payload['before'])) {
      $before = $payload['before'];
    } elseif (isset($payload['previous']) && is_array($payload['previous'])) {
      $before = $payload['previous'];
    } elseif (isset($payload['before_json']) && is_array($payload['before_json'])) {
      $before = $payload['before_json'];
    }

    if (isset($payload['after']) && is_array($payload['after'])) {
      $after = $payload['after'];
    } elseif (isset($payload['after_json']) && is_array($payload['after_json'])) {
      $after = $payload['after_json'];
    }

    return [$before, $after];
  }

  /**
   * @param  list<int>  $userIds
   * @return array<int, string>
   */
  private function resolveUserNames(array $userIds): array
  {
    if ($userIds === []) {
      return [];
    }

    return User::query()
      ->whereIn('id', $userIds)
      ->pluck('name', 'id')
      ->mapWithKeys(fn ($name, $id): array => [(int) $id => (string) $name])
      ->all();
  }
}
