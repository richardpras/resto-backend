<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\AutomationRule;
use App\Models\User;
use Illuminate\Support\Collection;

final class AlertRuleService
{
    /** @var array<string,array{rule_name:string,threshold:float,severity:string,channels:array<int,string>,escalation:bool}> */
    private const DEFAULT_RULES = [
        AutomationRule::TYPE_FOOD_COST => [
            'rule_name' => 'Food Cost Threshold',
            'threshold' => 40.0,
            'severity' => 'warning',
            'channels' => ['database'],
            'escalation' => false,
        ],
        AutomationRule::TYPE_MARGIN_EROSION => [
            'rule_name' => 'Margin Erosion',
            'threshold' => 5.0,
            'severity' => 'warning',
            'channels' => ['database'],
            'escalation' => false,
        ],
        AutomationRule::TYPE_STAR_TO_PLOWHORSE => [
            'rule_name' => 'Star to Plowhorse',
            'threshold' => 0,
            'severity' => 'high',
            'channels' => ['database'],
            'escalation' => true,
        ],
        AutomationRule::TYPE_STAR_TO_DOG => [
            'rule_name' => 'Star to Dog',
            'threshold' => 0,
            'severity' => 'critical',
            'channels' => ['database', 'email'],
            'escalation' => true,
        ],
        AutomationRule::TYPE_DEAD_STOCK => [
            'rule_name' => 'Dead Stock',
            'threshold' => 30.0,
            'severity' => 'warning',
            'channels' => ['database'],
            'escalation' => false,
        ],
        AutomationRule::TYPE_INVENTORY_VALUE_SPIKE => [
            'rule_name' => 'Inventory Value Spike',
            'threshold' => 15.0,
            'severity' => 'warning',
            'channels' => ['database'],
            'escalation' => false,
        ],
        AutomationRule::TYPE_YIELD_LOSS => [
            'rule_name' => 'Yield Loss',
            'threshold' => 5.0,
            'severity' => 'warning',
            'channels' => ['database'],
            'escalation' => false,
        ],
        AutomationRule::TYPE_MENU_REMOVAL => [
            'rule_name' => 'Menu Removal Recommendation',
            'threshold' => 30.0,
            'severity' => 'high',
            'channels' => ['database'],
            'escalation' => true,
        ],
    ];

    public function __construct(
        private readonly MenuAutomationAuditService $auditService,
    ) {}

    /** @return Collection<int, AutomationRule> */
    public function listRules(int $outletId): Collection
    {
        $this->ensureDefaultRules($outletId);

        return AutomationRule::query()
            ->where('outlet_id', $outletId)
            ->orderBy('rule_type')
            ->get();
    }

    public function findRule(int $ruleId, int $outletId): AutomationRule
    {
        return AutomationRule::query()
            ->where('id', $ruleId)
            ->where('outlet_id', $outletId)
            ->firstOrFail();
    }

    /** @param array<string,mixed> $data */
    public function createRule(int $outletId, array $data, ?User $actor = null): AutomationRule
    {
        $rule = AutomationRule::query()->create([
            'outlet_id' => $outletId,
            'rule_name' => (string) $data['ruleName'],
            'rule_type' => (string) $data['ruleType'],
            'threshold_value' => (float) ($data['thresholdValue'] ?? 0),
            'severity' => (string) ($data['severity'] ?? 'warning'),
            'notification_channels' => $data['notificationChannels'] ?? ['database'],
            'escalation_enabled' => (bool) ($data['escalationEnabled'] ?? false),
            'is_active' => (bool) ($data['isActive'] ?? true),
        ]);

        $this->auditService->log('automation_rule_created', (int) $rule->id, $outletId, $actor, [
            'ruleType' => $rule->rule_type,
        ], entityType: 'automation_rule');

        return $rule;
    }

    /** @param array<string,mixed> $data */
    public function updateRule(int $ruleId, int $outletId, array $data, ?User $actor = null): AutomationRule
    {
        $rule = $this->findRule($ruleId, $outletId);

        $rule->update([
            'rule_name' => $data['ruleName'] ?? $rule->rule_name,
            'rule_type' => $data['ruleType'] ?? $rule->rule_type,
            'threshold_value' => $data['thresholdValue'] ?? $rule->threshold_value,
            'severity' => $data['severity'] ?? $rule->severity,
            'notification_channels' => $data['notificationChannels'] ?? $rule->notification_channels,
            'escalation_enabled' => $data['escalationEnabled'] ?? $rule->escalation_enabled,
            'is_active' => $data['isActive'] ?? $rule->is_active,
        ]);

        $this->auditService->log('automation_rule_updated', (int) $rule->id, $outletId, $actor, [
            'ruleType' => $rule->rule_type,
        ], entityType: 'automation_rule');

        return $rule->fresh();
    }

    public function deleteRule(int $ruleId, int $outletId, ?User $actor = null): void
    {
        $rule = $this->findRule($ruleId, $outletId);
        $ruleIdValue = (int) $rule->id;
        $ruleType = $rule->rule_type;

        $rule->delete();

        $this->auditService->log('automation_rule_deleted', $ruleIdValue, $outletId, $actor, [
            'ruleType' => $ruleType,
        ], entityType: 'automation_rule');
    }

    public function getActiveRule(int $outletId, string $ruleType): ?AutomationRule
    {
        $this->ensureDefaultRules($outletId);

        return AutomationRule::query()
            ->where('outlet_id', $outletId)
            ->where('rule_type', $ruleType)
            ->where('is_active', true)
            ->first();
    }

    public function ensureDefaultRules(int $outletId): void
    {
        foreach (self::DEFAULT_RULES as $type => $defaults) {
            AutomationRule::query()->firstOrCreate(
                ['outlet_id' => $outletId, 'rule_type' => $type],
                [
                    'rule_name' => $defaults['rule_name'],
                    'threshold_value' => $defaults['threshold'],
                    'severity' => $defaults['severity'],
                    'notification_channels' => $defaults['channels'],
                    'escalation_enabled' => $defaults['escalation'],
                    'is_active' => true,
                ],
            );
        }
    }
}
