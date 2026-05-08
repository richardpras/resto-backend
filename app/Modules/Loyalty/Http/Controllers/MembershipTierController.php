<?php

namespace App\Modules\Loyalty\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Loyalty\Domain\LoyaltyMembershipTier;
use App\Models\User;
use App\Modules\Loyalty\Http\Requests\ListMembershipTiersRequest;
use App\Modules\Loyalty\Http\Resources\LoyaltyMembershipTierResource;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class MembershipTierController extends Controller
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    public function index(ListMembershipTiersRequest $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $allowedOutlets = $this->outletAccessResolver->allowedOutletIds($user);
        $outletId = $request->validated('outletId');

        $query = LoyaltyMembershipTier::query()
            ->where('is_active', true)
            ->where(function ($builder) use ($allowedOutlets): void {
                $builder->whereNull('outlet_id')
                    ->orWhereIn('outlet_id', $allowedOutlets);
            });

        if (is_int($outletId)) {
            if (! in_array($outletId, $allowedOutlets, true)) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'The selected outletId is invalid.');
            }
            $query->where(function ($builder) use ($outletId): void {
                $builder->where('outlet_id', $outletId)
                    ->orWhereNull('outlet_id');
            });
        }

        $tiers = $query->orderByDesc('priority')->orderByDesc('min_lifetime_spend')->get();

        return response()->json([
            'success' => true,
            'message' => 'Membership tiers retrieved successfully.',
            'data' => LoyaltyMembershipTierResource::collection($tiers)->resolve(),
            'meta' => ['count' => $tiers->count()],
        ]);
    }
}
