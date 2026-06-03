<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgramRule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class LoyaltySimulatorService
{
    public function __construct(
        private readonly LoyaltyProgramManagementService $programManagementService,
        private readonly LoyaltyProgramService $loyaltyProgramService,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function simulate(?User $user, array $input): array
    {
        $programId = (int) ($input['programId'] ?? 0);
        $outletId = (int) ($input['outletId'] ?? 0);
        $spendingAmount = (float) ($input['spendingAmount'] ?? 0);
        $visitCount = (int) ($input['visitCount'] ?? 0);
        $simulationDate = isset($input['simulationDate'])
            ? Carbon::parse((string) $input['simulationDate'])
            : now();

        if ($programId < 1 || $outletId < 1) {
            throw ValidationException::withMessages([
                'programId' => ['Program and outlet are required.'],
            ]);
        }

        $program = $this->programManagementService->findScoped($user, $programId);
        if ($program === null) {
            throw ValidationException::withMessages([
                'programId' => ['Loyalty program not found.'],
            ]);
        }

        if ($program->outlet_id !== null && (int) $program->outlet_id !== $outletId) {
            throw ValidationException::withMessages([
                'outletId' => ['Program does not belong to this outlet.'],
            ]);
        }

        $effective = $program->isEffectiveAt($simulationDate);
        $rule = LoyaltyProgramRule::query()
            ->where('loyalty_program_id', $program->id)
            ->where('rule_type', $program->type)
            ->orderByDesc('id')
            ->first();

        $triggeredRules = [];
        $breakdown = [];
        $expectedPoints = 0;

        if (! $effective) {
            return $this->resultPayload($program, $simulationDate, $effective, 0, [], [
                ['step' => 'effective_window', 'message' => 'Program is outside its effective date range on the simulation date.'],
            ]);
        }

        if (! $program->is_active) {
            return $this->resultPayload($program, $simulationDate, $effective, 0, [], [
                ['step' => 'activation', 'message' => 'Program is inactive.'],
            ]);
        }

        if ($rule === null) {
            return $this->resultPayload($program, $simulationDate, $effective, 0, [], [
                ['step' => 'rules', 'message' => 'No rule configured for this program type.'],
            ]);
        }

        $config = is_array($rule->config) ? $rule->config : [];
        $triggeredRules[] = [
            'ruleId' => (string) $rule->id,
            'ruleType' => (string) $rule->rule_type,
            'config' => $config,
        ];

        $simulation = match ($program->type) {
            LoyaltyProgram::TYPE_SPEND_BASED => $this->simulateSpendBased($spendingAmount, $config),
            LoyaltyProgram::TYPE_VISIT_BASED => $this->simulateVisitBased($visitCount, $config),
            LoyaltyProgram::TYPE_PERIOD_SPENDING => $this->simulatePeriodSpending($spendingAmount, $config),
            LoyaltyProgram::TYPE_PERCENTAGE_REWARD => $this->simulatePercentageReward($spendingAmount, $config),
            default => [
                'points' => 0,
                'breakdown' => [
                    ['step' => 'type', 'message' => 'Simulation not implemented for program type: '.$program->type],
                ],
            ],
        };

        $expectedPoints = (int) $simulation['points'];
        $breakdown = $simulation['breakdown'];

        return $this->resultPayload($program, $simulationDate, $effective, $expectedPoints, $triggeredRules, $breakdown);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{points: int, breakdown: array<int, array<string, mixed>>}
     */
    private function simulateSpendBased(float $spendingAmount, array $config): array
    {
        $earnPerAmount = (float) ($config['earnPerAmount'] ?? 0);
        $pointsEarned = (int) ($config['pointsEarned'] ?? 0);
        $points = $this->loyaltyProgramService->calculateSpendBasedPoints($spendingAmount, $config);
        $units = $earnPerAmount > 0 ? (int) floor($spendingAmount / $earnPerAmount) : 0;

        return [
            'points' => $points,
            'breakdown' => [
                [
                    'step' => 'spend_based',
                    'formula' => 'floor(spending / earnPerAmount) * pointsEarned',
                    'inputs' => [
                        'spendingAmount' => $spendingAmount,
                        'earnPerAmount' => $earnPerAmount,
                        'pointsEarned' => $pointsEarned,
                        'qualifyingUnits' => $units,
                    ],
                    'result' => $points,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{points: int, breakdown: array<int, array<string, mixed>>}
     */
    private function simulateVisitBased(int $visitCount, array $config): array
    {
        $visitThreshold = (int) ($config['visit_threshold'] ?? $config['visitThreshold'] ?? 0);
        $pointsAwarded = (int) ($config['points_awarded'] ?? $config['pointsAwarded'] ?? 0);
        $points = $visitThreshold > 0
            && $pointsAwarded > 0
            && $visitCount > 0
            && $visitCount % $visitThreshold === 0
            ? $pointsAwarded
            : 0;

        return [
            'points' => $points,
            'breakdown' => [
                [
                    'step' => 'visit_based',
                    'formula' => 'points_awarded when visitCount % visit_threshold === 0',
                    'inputs' => [
                        'visitCount' => $visitCount,
                        'visit_threshold' => $visitThreshold,
                        'points_awarded' => $pointsAwarded,
                    ],
                    'result' => $points,
                    'note' => 'Production rewards issue on paid-order milestones (event-driven).',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{points: int, breakdown: array<int, array<string, mixed>>}
     */
    private function simulatePeriodSpending(float $spendingAmount, array $config): array
    {
        $period = (string) ($config['period'] ?? 'monthly');
        $minimumSpend = (float) ($config['minimum_spend'] ?? $config['minimumSpend'] ?? 0);
        $rewardPercent = (float) (
            $config['reward_percent']
            ?? $config['rewardPercent']
            ?? $config['percentage']
            ?? 0
        );
        $points = $this->loyaltyProgramService->calculatePeriodSpendingPoints($spendingAmount, [
            'period' => $period,
            'minimum_spend' => $minimumSpend,
            'reward_percent' => $rewardPercent,
        ]);

        return [
            'points' => $points,
            'breakdown' => [
                [
                    'step' => 'period_spending',
                    'formula' => 'floor(spending * reward_percent / 100) when spending >= minimum_spend',
                    'inputs' => [
                        'spendingAmount' => $spendingAmount,
                        'minimum_spend' => $minimumSpend,
                        'reward_percent' => $rewardPercent,
                        'period' => $period,
                    ],
                    'result' => $points,
                    'note' => 'Production accrual runs daily via loyalty:process-period-rewards.',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{points: int, breakdown: array<int, array<string, mixed>>}
     */
    private function simulatePercentageReward(float $spendingAmount, array $config): array
    {
        $percentage = (float) ($config['percentage'] ?? 0);
        $points = $percentage > 0 && $spendingAmount > 0
            ? (int) floor($spendingAmount * ($percentage / 100))
            : 0;

        return [
            'points' => $points,
            'breakdown' => [
                [
                    'step' => 'percentage_reward',
                    'formula' => 'floor(spending * percentage / 100)',
                    'inputs' => ['spendingAmount' => $spendingAmount, 'percentage' => $percentage],
                    'result' => $points,
                    'note' => 'Preview only; not applied on order payment in this phase.',
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $triggeredRules
     * @param  array<int, array<string, mixed>>  $breakdown
     * @return array<string, mixed>
     */
    private function resultPayload(
        LoyaltyProgram $program,
        Carbon $simulationDate,
        bool $effective,
        int $expectedPoints,
        array $triggeredRules,
        array $breakdown,
    ): array {
        return [
            'programId' => (string) $program->id,
            'programCode' => (string) $program->code,
            'programName' => (string) $program->name,
            'programType' => (string) $program->type,
            'simulationDate' => $simulationDate->toDateString(),
            'isEffective' => $effective,
            'isActive' => (bool) $program->is_active,
            'expectedPoints' => $expectedPoints,
            'triggeredRules' => $triggeredRules,
            'breakdown' => $breakdown,
        ];
    }
}
