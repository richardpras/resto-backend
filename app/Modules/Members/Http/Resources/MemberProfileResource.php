<?php

namespace App\Modules\Members\Http\Resources;

use App\Modules\LoyaltyEngine\Http\Resources\LoyaltyNotificationResource;
use App\Modules\LoyaltyEngine\Http\Resources\LoyaltyRewardCatalogItemResource;
use App\Modules\LoyaltyEngine\Http\Resources\LoyaltyRewardRedemptionResource;
use App\Modules\LoyaltyEngine\Http\Resources\MemberSegmentMembershipResource;
use App\Modules\LoyaltyEngine\Http\Resources\MemberTierBenefitResource;
use App\Modules\LoyaltyEngine\Http\Resources\MemberTierHistoryResource;
use App\Modules\LoyaltyEngine\Http\Resources\MemberTierMembershipResource;
use App\Modules\LoyaltyEngine\Http\Resources\MemberVoucherProfileResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var array{member: mixed, stats: array<string, mixed>, transactions: mixed, currentPoints?: int, loyaltyHistory?: mixed} $payload */
        $payload = $this->resource;

        return [
            'member' => new MemberResource($payload['member']),
            'stats' => [
                'totalVisits' => (int) ($payload['stats']['totalVisits'] ?? 0),
                'totalSpending' => (float) ($payload['stats']['totalSpending'] ?? 0),
                'lastVisit' => $payload['stats']['lastVisit'] !== null
                    ? (string) $payload['stats']['lastVisit']
                    : null,
            ],
            'currentPoints' => (int) ($payload['currentPoints'] ?? 0),
            'loyaltyHistory' => LoyaltyMemberLedgerResource::collection($payload['loyaltyHistory'] ?? []),
            'availableRewards' => LoyaltyRewardCatalogItemResource::collection($payload['availableRewards'] ?? []),
            'rewardRedemptions' => LoyaltyRewardRedemptionResource::collection($payload['rewardRedemptions'] ?? []),
            'expiryPolicy' => [
                'enabled' => (bool) ($payload['expiryPolicy']['enabled'] ?? false),
                'days' => isset($payload['expiryPolicy']['days'])
                    ? (int) $payload['expiryPolicy']['days']
                    : null,
            ],
            'expiredPointsTotal' => (int) ($payload['expiredPointsTotal'] ?? 0),
            'expiryHistory' => LoyaltyMemberLedgerResource::collection($payload['expiryHistory'] ?? []),
            'memberSegments' => MemberSegmentMembershipResource::collection($payload['memberSegments'] ?? []),
            'availableVouchers' => MemberVoucherProfileResource::collection($payload['availableVouchers'] ?? []),
            'voucherHistory' => MemberVoucherProfileResource::collection($payload['voucherHistory'] ?? []),
            'tier' => isset($payload['tier']) && $payload['tier'] !== null
                ? new MemberTierMembershipResource($payload['tier'])
                : null,
            'benefits' => MemberTierBenefitResource::collection($payload['benefits'] ?? []),
            'tierHistory' => MemberTierHistoryResource::collection($payload['tierHistory'] ?? []),
            'notifications' => LoyaltyNotificationResource::collection($payload['notifications'] ?? []),
            'transactions' => MemberTransactionResource::collection($payload['transactions']),
        ];
    }
}
