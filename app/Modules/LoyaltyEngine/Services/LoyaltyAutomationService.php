<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Member;
use App\Models\MemberTransaction;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyAutomation;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyAutomationLog;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LoyaltyAutomationService
{
    /** @var list<string> */
    private const SCHEDULED_TRIGGERS = [
        LoyaltyAutomation::TRIGGER_MEMBER_BIRTHDAY,
        LoyaltyAutomation::TRIGGER_INACTIVE_MEMBER,
    ];

    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly LoyaltyAutomationExecutor $automationExecutor,
    ) {}

    /**
     * @return Collection<int, LoyaltyAutomation>
     */
    public function list(?User $user, int $outletId, ?bool $isActive = null): Collection
    {
        $this->assertOutletAllowed($user, $outletId);

        return LoyaltyAutomation::query()
            ->where('outlet_id', $outletId)
            ->when($isActive !== null, fn ($query) => $query->where('is_active', $isActive))
            ->orderBy('name')
            ->get();
    }

    public function findScoped(?User $user, int $automationId): ?LoyaltyAutomation
    {
        $automation = LoyaltyAutomation::query()->whereKey($automationId)->first();
        if ($automation === null) {
            return null;
        }

        $this->assertOutletAllowed($user, (int) $automation->outlet_id);

        return $automation;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(?User $user, array $payload): LoyaltyAutomation
    {
        $outletId = (int) ($payload['outletId'] ?? 0);
        if ($outletId < 1) {
            throw ValidationException::withMessages([
                'outletId' => ['Outlet is required.'],
            ]);
        }

        $this->assertOutletAllowed($user, $outletId);

        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        $name = trim((string) ($payload['name'] ?? ''));
        if ($code === '' || $name === '') {
            throw ValidationException::withMessages([
                'code' => ['Code and name are required.'],
            ]);
        }

        $this->assertCodeUnique($outletId, $code);
        $this->assertTriggerActionPair(
            (string) ($payload['triggerType'] ?? ''),
            (string) ($payload['actionType'] ?? ''),
        );

        return LoyaltyAutomation::query()->create([
            'outlet_id' => $outletId,
            'code' => $code,
            'name' => $name,
            'description' => $payload['description'] ?? null,
            'trigger_type' => (string) $payload['triggerType'],
            'condition_json' => is_array($payload['condition'] ?? null) ? $payload['condition'] : [],
            'action_type' => (string) $payload['actionType'],
            'action_config_json' => is_array($payload['actionConfig'] ?? null) ? $payload['actionConfig'] : [],
            'is_active' => $payload['isActive'] ?? true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(?User $user, LoyaltyAutomation $automation, array $payload): LoyaltyAutomation
    {
        $this->assertOutletAllowed($user, (int) $automation->outlet_id);

        $attributes = [];

        if (array_key_exists('name', $payload)) {
            $name = trim((string) $payload['name']);
            if ($name === '') {
                throw ValidationException::withMessages(['name' => ['Name is required.']]);
            }
            $attributes['name'] = $name;
        }

        if (array_key_exists('description', $payload)) {
            $attributes['description'] = $payload['description'];
        }

        if (array_key_exists('code', $payload)) {
            $code = strtoupper(trim((string) $payload['code']));
            if ($code === '') {
                throw ValidationException::withMessages(['code' => ['Code is required.']]);
            }
            $this->assertCodeUnique((int) $automation->outlet_id, $code, (int) $automation->id);
            $attributes['code'] = $code;
        }

        if (array_key_exists('triggerType', $payload) || array_key_exists('actionType', $payload)) {
            $triggerType = (string) ($payload['triggerType'] ?? $automation->trigger_type);
            $actionType = (string) ($payload['actionType'] ?? $automation->action_type);
            $this->assertTriggerActionPair($triggerType, $actionType);
            $attributes['trigger_type'] = $triggerType;
            $attributes['action_type'] = $actionType;
        }

        if (array_key_exists('condition', $payload)) {
            $attributes['condition_json'] = is_array($payload['condition']) ? $payload['condition'] : [];
        }

        if (array_key_exists('actionConfig', $payload)) {
            $attributes['action_config_json'] = is_array($payload['actionConfig']) ? $payload['actionConfig'] : [];
        }

        if ($attributes !== []) {
            $automation->update($attributes);
        }

        return $automation->fresh() ?? $automation;
    }

    public function setActive(?User $user, LoyaltyAutomation $automation, bool $isActive): LoyaltyAutomation
    {
        $this->assertOutletAllowed($user, (int) $automation->outlet_id);
        $automation->update(['is_active' => $isActive]);

        return $automation->fresh() ?? $automation;
    }

    /**
     * @return Collection<int, LoyaltyAutomationLog>
     */
    public function logs(?User $user, LoyaltyAutomation $automation, int $limit = 50): Collection
    {
        $this->assertOutletAllowed($user, (int) $automation->outlet_id);

        return LoyaltyAutomationLog::query()
            ->where('automation_id', $automation->id)
            ->orderByDesc('executed_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function safeProcessEvent(int $outletId, int $memberId, string $triggerType, array $context = []): void
    {
        try {
            $this->processEvent($outletId, $memberId, $triggerType, $context);
        } catch (\Throwable $exception) {
            Log::warning('loyalty.automation.event_failed', [
                'outletId' => $outletId,
                'memberId' => $memberId,
                'triggerType' => $triggerType,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function processEvent(int $outletId, int $memberId, string $triggerType, array $context = []): void
    {
        if ($outletId < 1 || $memberId < 1) {
            return;
        }

        $member = Member::query()
            ->whereKey($memberId)
            ->where('outlet_id', $outletId)
            ->first();

        if ($member === null || ! $member->is_active) {
            return;
        }

        $automations = LoyaltyAutomation::query()
            ->where('outlet_id', $outletId)
            ->where('trigger_type', $triggerType)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($automations as $automation) {
            $this->runAutomation($automation, $member, $context, false);
        }
    }

    public function processScheduledAutomations(?Carbon $asOf = null): int
    {
        $asOf ??= now();
        $processed = 0;

        $automations = LoyaltyAutomation::query()
            ->where('is_active', true)
            ->whereIn('trigger_type', self::SCHEDULED_TRIGGERS)
            ->orderBy('outlet_id')
            ->orderBy('id')
            ->get();

        foreach ($automations as $automation) {
            $members = Member::query()
                ->where('outlet_id', $automation->outlet_id)
                ->where('is_active', true)
                ->get();

            foreach ($members as $member) {
                if ($this->runAutomation($automation, $member, ['scheduledAt' => $asOf->toIso8601String()], true)) {
                    $processed++;
                }
            }
        }

        return $processed;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function runAutomation(
        LoyaltyAutomation $automation,
        Member $member,
        array $context,
        bool $scheduled,
    ): bool {
        if ($scheduled && $this->alreadyExecutedToday($automation, (int) $member->id)) {
            return false;
        }

        if (! $this->matchesCondition($automation, $member, $context, $scheduled)) {
            return false;
        }

        try {
            $result = $this->automationExecutor->execute($automation, $member, $context);
            $this->writeLog($automation, $member, LoyaltyAutomationLog::STATUS_SUCCESS, $result);

            return true;
        } catch (\Throwable $exception) {
            $this->writeLog($automation, $member, LoyaltyAutomationLog::STATUS_FAILED, [
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function matchesCondition(
        LoyaltyAutomation $automation,
        Member $member,
        array $context,
        bool $scheduled,
    ): bool {
        $condition = $automation->conditionConfig();

        return match ($automation->trigger_type) {
            LoyaltyAutomation::TRIGGER_MEMBER_CREATED,
            LoyaltyAutomation::TRIGGER_TIER_UPGRADED,
            LoyaltyAutomation::TRIGGER_VOUCHER_REDEEMED,
            LoyaltyAutomation::TRIGGER_REWARD_REDEEMED => true,
            LoyaltyAutomation::TRIGGER_MEMBER_BIRTHDAY => $this->matchesBirthday($member, $condition),
            LoyaltyAutomation::TRIGGER_VISIT_MILESTONE => (int) ($context['visitCount'] ?? -1) === (int) ($condition['visitCount'] ?? -1),
            LoyaltyAutomation::TRIGGER_POINTS_MILESTONE => $this->matchesPointsMilestone($condition, $context),
            LoyaltyAutomation::TRIGGER_INACTIVE_MEMBER => $this->matchesInactiveMember($member, $condition),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function matchesBirthday(Member $member, array $condition): bool
    {
        $birthday = $member->birth_date ?? $member->birthday;
        if ($birthday === null) {
            return false;
        }

        $daysBefore = (int) ($condition['daysBefore'] ?? 0);
        $target = now()->addDays($daysBefore);

        return (int) $birthday->month === (int) $target->month
            && (int) $birthday->day === (int) $target->day;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $context
     */
    private function matchesPointsMilestone(array $condition, array $context): bool
    {
        $threshold = (int) ($condition['points'] ?? 0);
        if ($threshold < 1) {
            return false;
        }

        $current = (int) ($context['currentBalance'] ?? 0);
        $previous = (int) ($context['previousBalance'] ?? $current);

        return $previous < $threshold && $current >= $threshold;
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function matchesInactiveMember(Member $member, array $condition): bool
    {
        $daysInactive = (int) ($condition['daysInactive'] ?? 30);
        if ($daysInactive < 1) {
            return false;
        }

        $lastVisit = MemberTransaction::query()
            ->where('member_id', $member->id)
            ->max('transaction_at');

        if ($lastVisit === null) {
            $reference = $member->created_at ?? now();

            return $reference->diffInDays(now()) >= $daysInactive;
        }

        return Carbon::parse($lastVisit)->diffInDays(now()) >= $daysInactive;
    }

    private function alreadyExecutedToday(LoyaltyAutomation $automation, int $memberId): bool
    {
        return LoyaltyAutomationLog::query()
            ->where('automation_id', $automation->id)
            ->where('member_id', $memberId)
            ->whereDate('executed_at', today())
            ->where('status', LoyaltyAutomationLog::STATUS_SUCCESS)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function writeLog(
        LoyaltyAutomation $automation,
        Member $member,
        string $status,
        array $result,
    ): void {
        LoyaltyAutomationLog::query()->create([
            'automation_id' => $automation->id,
            'member_id' => $member->id,
            'trigger_type' => (string) $automation->trigger_type,
            'action_type' => (string) $automation->action_type,
            'status' => $status,
            'result_json' => $result,
            'executed_at' => now(),
        ]);
    }

    private function assertTriggerActionPair(string $triggerType, string $actionType): void
    {
        if (! in_array($triggerType, LoyaltyAutomation::TRIGGER_TYPES, true)) {
            throw ValidationException::withMessages([
                'triggerType' => ['Invalid trigger type.'],
            ]);
        }

        if (! in_array($actionType, LoyaltyAutomation::ACTION_TYPES, true)) {
            throw ValidationException::withMessages([
                'actionType' => ['Invalid action type.'],
            ]);
        }
    }

    private function assertCodeUnique(int $outletId, string $code, ?int $ignoreId = null): void
    {
        $exists = LoyaltyAutomation::query()
            ->where('outlet_id', $outletId)
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => ['Automation code must be unique for this outlet.'],
            ]);
        }
    }

    private function assertOutletAllowed(?User $user, int $outletId): void
    {
        if ($user === null) {
            return;
        }

        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if ($allowed !== null && ! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outlet is not allowed for this user.'],
            ]);
        }
    }
}
