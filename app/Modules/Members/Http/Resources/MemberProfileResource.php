<?php

namespace App\Modules\Members\Http\Resources;

use App\Modules\LoyaltyEngine\Http\Resources\LoyaltyRewardCatalogItemResource;
use App\Modules\LoyaltyEngine\Http\Resources\LoyaltyRewardRedemptionResource;
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
            'transactions' => MemberTransactionResource::collection($payload['transactions']),
        ];
    }
}
