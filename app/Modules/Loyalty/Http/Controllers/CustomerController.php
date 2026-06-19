<?php

namespace App\Modules\Loyalty\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Loyalty\Http\Requests\ListCustomersRequest;
use App\Modules\Loyalty\Http\Requests\MergeCustomerRequest;
use App\Modules\Loyalty\Http\Requests\StoreCustomerRequest;
use App\Modules\Loyalty\Http\Requests\StoreLoyaltyLedgerRequest;
use App\Modules\Loyalty\Http\Requests\StoreRewardRedemptionRequest;
use App\Modules\Loyalty\Http\Resources\LoyaltyAccountResource;
use App\Modules\Loyalty\Http\Resources\LoyaltyPointsLedgerResource;
use App\Modules\Loyalty\Http\Resources\LoyaltyRewardRedemptionResource;
use App\Models\Modules\Loyalty\Domain\LoyaltyPointsLedger;
use App\Models\Modules\Loyalty\Domain\LoyaltyRewardRedemption;
use App\Modules\Loyalty\Services\CustomerAnalyticsService;
use App\Modules\Loyalty\Services\CustomerProfileService;
use App\Modules\Loyalty\Services\LoyaltyPointService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerProfileService $customerProfileService,
        private readonly LoyaltyPointService $loyaltyPointService,
        private readonly CustomerAnalyticsService $customerAnalyticsService,
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    public function index(ListCustomersRequest $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $outletId = $request->validated('outletId');

        $accounts = $this->customerProfileService->list($user, is_int($outletId) ? $outletId : null);

        return response()->json([
            'success' => true,
            'message' => 'Customers retrieved successfully.',
            'data' => LoyaltyAccountResource::collection($accounts)->resolve(),
            'meta' => ['count' => count($accounts)],
        ]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $account = $this->customerProfileService->create($user, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Customer created successfully.',
            'data' => (new LoyaltyAccountResource($account))->resolve(),
            'meta' => null,
        ], Response::HTTP_CREATED);
    }

    public function show(int $customer): JsonResponse
    {
        $user = request()->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $account = $this->customerProfileService->findScoped($user, $customer);

        return response()->json([
            'success' => true,
            'message' => 'Customer retrieved successfully.',
            'data' => (new LoyaltyAccountResource($account))->resolve(),
            'meta' => null,
        ]);
    }

    public function timeline(int $customer): JsonResponse
    {
        $user = request()->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $account = $this->customerProfileService->findScoped($user, $customer);
        $timeline = $this->customerAnalyticsService->timeline($account);

        return response()->json([
            'success' => true,
            'message' => 'Customer timeline retrieved successfully.',
            'data' => $timeline,
            'meta' => ['count' => count($timeline)],
        ]);
    }

    public function loyaltyLedger(StoreLoyaltyLedgerRequest $request, int $customer): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $account = $this->customerProfileService->findScoped($user, $customer);

        $result = $this->loyaltyPointService->accrue($user, $account, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Loyalty accrual applied successfully.',
            'data' => [
                'idempotent' => $result['idempotent'],
                'ledger' => (new LoyaltyPointsLedgerResource($result['ledger']))->resolve(),
                'account' => (new LoyaltyAccountResource($result['account']))->resolve(),
            ],
            'meta' => null,
        ], Response::HTTP_CREATED);
    }

    public function loyaltyLedgerIndex(int $customer): JsonResponse
    {
        $user = request()->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $account = $this->customerProfileService->findScoped($user, $customer);
        $ledgers = LoyaltyPointsLedger::query()
            ->where('loyalty_account_id', $account->id)
            ->latest('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Customer loyalty ledger retrieved successfully.',
            'data' => LoyaltyPointsLedgerResource::collection($ledgers)->resolve(),
            'meta' => ['count' => $ledgers->count()],
        ]);
    }

    public function crmLoyaltyLedgerIndex(Request $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $validated = validator($request->all(), [
            'customerId' => ['nullable', 'integer', 'min:1'],
            'outletId' => ['nullable', 'integer', 'min:1', 'exists:outlets,id'],
        ])->validate();
        $query = LoyaltyPointsLedger::query()->latest('created_at');

        if (isset($validated['customerId'])) {
            $account = $this->customerProfileService->findScoped($user, (int) $validated['customerId']);
            $query->where('loyalty_account_id', $account->id);
        } else {
            $allowed = $this->outletAccessResolver->allowedOutletIds($user);
            if (isset($validated['outletId'])) {
                $outletId = (int) $validated['outletId'];
                if (! in_array($outletId, $allowed, true)) {
                    throw ValidationException::withMessages(['outletId' => ['The selected outletId is invalid.']]);
                }
                $query->where('outlet_id', $outletId);
            } else {
                $query->whereIn('outlet_id', $allowed);
            }
        }
        $ledgers = $query->limit(100)->get();

        return response()->json([
            'success' => true,
            'message' => 'Customer loyalty ledger retrieved successfully.',
            'data' => LoyaltyPointsLedgerResource::collection($ledgers)->resolve(),
            'meta' => ['count' => $ledgers->count()],
        ]);
    }

    public function redeem(StoreRewardRedemptionRequest $request, int $customer): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $account = $this->customerProfileService->findScoped($user, $customer);

        $result = $this->loyaltyPointService->redeem($user, $account, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Reward redemption created successfully.',
            'data' => [
                'idempotent' => $result['idempotent'],
                'redemption' => (new LoyaltyRewardRedemptionResource($result['redemption']))->resolve(),
                'account' => (new LoyaltyAccountResource($result['account']))->resolve(),
            ],
            'meta' => null,
        ], Response::HTTP_CREATED);
    }

    public function crmRedemptionsIndex(Request $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $validated = validator($request->all(), [
            'customerId' => ['nullable', 'integer', 'min:1'],
            'outletId' => ['nullable', 'integer', 'min:1', 'exists:outlets,id'],
        ])->validate();
        $query = LoyaltyRewardRedemption::query()->latest('created_at');
        if (isset($validated['customerId'])) {
            $account = $this->customerProfileService->findScoped($user, (int) $validated['customerId']);
            $query->where('loyalty_account_id', $account->id);
        } else {
            $allowed = $this->outletAccessResolver->allowedOutletIds($user);
            if (isset($validated['outletId'])) {
                $outletId = (int) $validated['outletId'];
                if (! in_array($outletId, $allowed, true)) {
                    throw ValidationException::withMessages(['outletId' => ['The selected outletId is invalid.']]);
                }
                $query->where('outlet_id', $outletId);
            } else {
                $query->whereIn('outlet_id', $allowed);
            }
        }
        $rows = $query->limit(100)->get();

        return response()->json([
            'success' => true,
            'message' => 'Loyalty redemptions retrieved successfully.',
            'data' => LoyaltyRewardRedemptionResource::collection($rows)->resolve(),
            'meta' => ['count' => $rows->count()],
        ]);
    }

    public function crmRedeem(Request $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $validated = validator($request->all(), [
            'customerId' => ['required', 'integer', 'min:1'],
            'outletId' => ['nullable', 'integer', 'min:1', 'exists:outlets,id'],
            'rewardCode' => ['nullable', 'string', 'max:100'],
            'pointsCost' => ['nullable', 'integer', 'min:1'],
            'idempotencyKey' => ['nullable', 'string', 'max:128'],
            'pointsUsed' => ['nullable', 'integer', 'min:1'],
            'replayFingerprint' => ['nullable', 'string', 'max:128'],
            'clientOccurredAt' => ['nullable', 'date'],
            'meta' => ['nullable', 'array'],
        ])->validate();
        $account = $this->customerProfileService->findScoped($user, (int) $validated['customerId']);
        $payload = $validated;
        $payload['outletId'] = (int) ($validated['outletId'] ?? $account->outlet_id ?? 0);
        $payload['rewardCode'] = $validated['rewardCode'] ?? 'crm-redemption';
        $payload['pointsCost'] = (int) ($validated['pointsCost'] ?? $validated['pointsUsed'] ?? 0);
        $payload['idempotencyKey'] = (string) ($validated['idempotencyKey'] ?? $validated['replayFingerprint'] ?? '');
        if ($payload['pointsCost'] < 1 || $payload['idempotencyKey'] === '' || $payload['outletId'] < 1) {
            throw ValidationException::withMessages([
                'pointsCost' => ['pointsCost/pointsUsed is required.'],
                'idempotencyKey' => ['idempotencyKey/replayFingerprint is required.'],
                'outletId' => ['outletId is required for redemption.'],
            ]);
        }
        $result = $this->loyaltyPointService->redeem($user, $account, $payload);

        return response()->json([
            'success' => true,
            'message' => 'Reward redemption created successfully.',
            'data' => (new LoyaltyRewardRedemptionResource($result['redemption']))->resolve(),
            'meta' => ['idempotent' => (bool) $result['idempotent']],
        ], Response::HTTP_CREATED);
    }

    public function merge(MergeCustomerRequest $request, int $customer): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $source = $this->customerProfileService->findScoped($user, $customer);
        $payload = $request->validated();

        if ((int) $source->outlet_id !== (int) $payload['outletId']) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'The selected outletId is invalid.');
        }

        $result = $this->customerProfileService->merge($user, $source, (int) $payload['targetCustomerId']);

        return response()->json([
            'success' => true,
            'message' => 'Customer merge completed successfully.',
            'data' => [
                'source' => (new LoyaltyAccountResource($result['source']))->resolve(),
                'target' => (new LoyaltyAccountResource($result['target']))->resolve(),
            ],
            'meta' => null,
        ]);
    }
}
