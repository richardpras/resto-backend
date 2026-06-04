<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyTier;
use Illuminate\Support\Collection;

class TierBenefitService
{
    public const KEY_PRIORITY_CAMPAIGN = 'priorityCampaign';

    public const KEY_EXCLUSIVE_VOUCHER = 'exclusiveVoucher';

    public const KEY_EXCLUSIVE_REWARD = 'exclusiveReward';

    public const KEY_MONTHLY_VOUCHER = 'monthlyVoucher';

    /** @var array<string, array{code: string, name: string}> */
    private const BENEFIT_DEFINITIONS = [
        self::KEY_PRIORITY_CAMPAIGN => [
            'code' => 'priority_campaign',
            'name' => 'Priority Campaign Access',
        ],
        self::KEY_EXCLUSIVE_VOUCHER => [
            'code' => 'exclusive_voucher',
            'name' => 'Exclusive Voucher Access',
        ],
        self::KEY_EXCLUSIVE_REWARD => [
            'code' => 'exclusive_reward',
            'name' => 'Exclusive Rewards',
        ],
        self::KEY_MONTHLY_VOUCHER => [
            'code' => 'monthly_voucher',
            'name' => 'Monthly Voucher Program',
        ],
    ];

    /**
     * @param  array<string, mixed>  $config
     * @return array{
     *     priorityCampaign: bool,
     *     exclusiveVoucher: bool,
     *     exclusiveReward: bool,
     *     monthlyVoucher: bool
     * }
     */
    public function normalizeConfig(array $config): array
    {
        return [
            self::KEY_PRIORITY_CAMPAIGN => (bool) ($config[self::KEY_PRIORITY_CAMPAIGN] ?? false),
            self::KEY_EXCLUSIVE_VOUCHER => (bool) ($config[self::KEY_EXCLUSIVE_VOUCHER] ?? false),
            self::KEY_EXCLUSIVE_REWARD => (bool) ($config[self::KEY_EXCLUSIVE_REWARD] ?? false),
            self::KEY_MONTHLY_VOUCHER => (bool) ($config[self::KEY_MONTHLY_VOUCHER] ?? false),
        ];
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function benefitsForTier(?LoyaltyTier $tier): array
    {
        if ($tier === null) {
            return [];
        }

        return $this->resolveBenefitList($tier->benefitConfig());
    }

    public function enabledBenefitCount(?LoyaltyTier $tier): int
    {
        return count($this->benefitsForTier($tier));
    }

    /**
     * @return list<array{tier: string, members: int, benefits: int}>
     */
    public function tierBenefitSummary(Collection $tiers, LoyaltyTierService $loyaltyTierService): array
    {
        return $tiers->map(function (LoyaltyTier $tier) use ($loyaltyTierService): array {
            return [
                'tier' => (string) $tier->name,
                'members' => $loyaltyTierService->countMembersInTier($tier),
                'benefits' => $this->enabledBenefitCount($tier),
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{code: string, name: string}>
     */
    private function resolveBenefitList(array $config): array
    {
        $normalized = $this->normalizeConfig($config);
        $benefits = [];

        foreach (self::BENEFIT_DEFINITIONS as $key => $definition) {
            if ($normalized[$key]) {
                $benefits[] = $definition;
            }
        }

        return $benefits;
    }
}
