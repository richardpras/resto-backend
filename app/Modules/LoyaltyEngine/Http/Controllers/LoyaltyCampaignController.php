<?php

namespace App\Modules\LoyaltyEngine\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyCampaign;
use App\Modules\LoyaltyEngine\Http\Requests\StoreLoyaltyCampaignRequest;
use App\Modules\LoyaltyEngine\Http\Requests\UpdateLoyaltyCampaignRequest;
use App\Modules\LoyaltyEngine\Http\Requests\UpdateLoyaltyCampaignStatusRequest;
use App\Modules\LoyaltyEngine\Http\Resources\LoyaltyCampaignAudienceResource;
use App\Modules\LoyaltyEngine\Http\Resources\LoyaltyCampaignResource;
use App\Modules\LoyaltyEngine\Http\Resources\LoyaltyCampaignSnapshotResource;
use App\Modules\LoyaltyEngine\Http\Requests\IssueCampaignVoucherRequest;
use App\Modules\LoyaltyEngine\Services\CampaignVoucherIssuanceService;
use App\Modules\LoyaltyEngine\Services\LoyaltyCampaignExecutionService;
use App\Modules\LoyaltyEngine\Services\LoyaltyCampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LoyaltyCampaignController extends Controller
{
    public function __construct(
        private readonly LoyaltyCampaignService $campaignService,
        private readonly LoyaltyCampaignExecutionService $executionService,
        private readonly CampaignVoucherIssuanceService $campaignVoucherIssuanceService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', 'string'],
        ]);

        $campaigns = $this->campaignService->list(
            $this->resolveUser($request),
            (int) $request->query('outletId'),
            $request->query('status'),
        );

        return response()->json([
            'data' => LoyaltyCampaignResource::collection($campaigns),
        ]);
    }

    public function store(StoreLoyaltyCampaignRequest $request): JsonResponse
    {
        $campaign = $this->campaignService->create(
            $this->resolveUser($request),
            $request->validated(),
        );

        return response()->json([
            'message' => 'Loyalty campaign created successfully.',
            'data' => new LoyaltyCampaignResource($campaign),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, LoyaltyCampaign $loyaltyCampaign): JsonResponse
    {
        $campaign = $this->campaignService->findScoped($this->resolveUser($request), (int) $loyaltyCampaign->id);
        abort_if($campaign === null, Response::HTTP_NOT_FOUND, 'Loyalty campaign not found.');

        return response()->json([
            'data' => new LoyaltyCampaignResource($campaign),
        ]);
    }

    public function update(UpdateLoyaltyCampaignRequest $request, LoyaltyCampaign $loyaltyCampaign): JsonResponse
    {
        $campaign = $this->campaignService->findScoped($this->resolveUser($request), (int) $loyaltyCampaign->id);
        abort_if($campaign === null, Response::HTTP_NOT_FOUND, 'Loyalty campaign not found.');

        $updated = $this->campaignService->update(
            $this->resolveUser($request),
            $campaign,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Loyalty campaign updated successfully.',
            'data' => new LoyaltyCampaignResource($updated),
        ]);
    }

    public function updateStatus(UpdateLoyaltyCampaignStatusRequest $request, LoyaltyCampaign $loyaltyCampaign): JsonResponse
    {
        $campaign = $this->campaignService->findScoped($this->resolveUser($request), (int) $loyaltyCampaign->id);
        abort_if($campaign === null, Response::HTTP_NOT_FOUND, 'Loyalty campaign not found.');

        $updated = $this->campaignService->updateStatus(
            $this->resolveUser($request),
            $campaign,
            (string) $request->validated('status'),
        );
        $updated->setAttribute('audience_count', $this->campaignService->audienceCount($updated));
        $updated->setAttribute('captured_count', $this->executionService->countCapturedAudience($updated));

        return response()->json([
            'message' => 'Loyalty campaign status updated.',
            'data' => new LoyaltyCampaignResource($updated),
        ]);
    }

    public function audience(Request $request, LoyaltyCampaign $loyaltyCampaign): JsonResponse
    {
        $campaign = $this->campaignService->findScoped($this->resolveUser($request), (int) $loyaltyCampaign->id);
        abort_if($campaign === null, Response::HTTP_NOT_FOUND, 'Loyalty campaign not found.');

        $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $payload = $this->campaignService->audiencePreview(
            $campaign,
            (int) ($request->query('limit') ?? 50),
        );

        return response()->json([
            'data' => (new LoyaltyCampaignAudienceResource($payload))->resolve(),
        ]);
    }

    public function audienceSnapshot(Request $request, LoyaltyCampaign $loyaltyCampaign): JsonResponse
    {
        $campaign = $this->campaignService->findScoped($this->resolveUser($request), (int) $loyaltyCampaign->id);
        abort_if($campaign === null, Response::HTTP_NOT_FOUND, 'Loyalty campaign not found.');

        $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $payload = $this->executionService->audienceSnapshot(
            $campaign,
            (int) ($request->query('limit') ?? 50),
        );

        return response()->json([
            'data' => (new LoyaltyCampaignSnapshotResource($payload))->resolve(),
        ]);
    }

    public function activate(Request $request, LoyaltyCampaign $loyaltyCampaign): JsonResponse
    {
        $campaign = $this->campaignService->findScoped($this->resolveUser($request), (int) $loyaltyCampaign->id);
        abort_if($campaign === null, Response::HTTP_NOT_FOUND, 'Loyalty campaign not found.');

        $activated = $this->executionService->activate($this->resolveUser($request), $campaign);
        $activated->setAttribute('audience_count', $this->campaignService->audienceCount($activated));
        $activated->setAttribute('captured_count', $this->executionService->countCapturedAudience($activated));

        return response()->json([
            'message' => 'Loyalty campaign activated.',
            'data' => new LoyaltyCampaignResource($activated),
        ]);
    }

    public function complete(Request $request, LoyaltyCampaign $loyaltyCampaign): JsonResponse
    {
        $campaign = $this->campaignService->findScoped($this->resolveUser($request), (int) $loyaltyCampaign->id);
        abort_if($campaign === null, Response::HTTP_NOT_FOUND, 'Loyalty campaign not found.');

        $completed = $this->executionService->complete($this->resolveUser($request), $campaign);
        $completed->setAttribute('captured_count', $this->executionService->countCapturedAudience($completed));

        return response()->json([
            'message' => 'Loyalty campaign completed.',
            'data' => new LoyaltyCampaignResource($completed),
        ]);
    }

    public function cancel(Request $request, LoyaltyCampaign $loyaltyCampaign): JsonResponse
    {
        $campaign = $this->campaignService->findScoped($this->resolveUser($request), (int) $loyaltyCampaign->id);
        abort_if($campaign === null, Response::HTTP_NOT_FOUND, 'Loyalty campaign not found.');

        $cancelled = $this->executionService->cancel($this->resolveUser($request), $campaign);
        $cancelled->setAttribute('captured_count', $this->executionService->countCapturedAudience($cancelled));

        return response()->json([
            'message' => 'Loyalty campaign cancelled.',
            'data' => new LoyaltyCampaignResource($cancelled),
        ]);
    }

    public function issueVoucher(IssueCampaignVoucherRequest $request, LoyaltyCampaign $loyaltyCampaign): JsonResponse
    {
        $campaign = $this->campaignService->findScoped($this->resolveUser($request), (int) $loyaltyCampaign->id);
        abort_if($campaign === null, Response::HTTP_NOT_FOUND, 'Loyalty campaign not found.');

        $result = $this->campaignVoucherIssuanceService->issueToCampaignAudience(
            $this->resolveUser($request),
            $campaign,
            (int) $request->validated('voucherId'),
        );

        return response()->json([
            'message' => 'Vouchers issued to campaign audience.',
            'data' => [
                'campaignId' => (string) $result['campaign']->id,
                'voucherId' => (string) $result['voucherId'],
                'audienceCount' => $result['audienceCount'],
                'issuedCount' => $result['issuedCount'],
                'skippedCount' => $result['skippedCount'],
            ],
        ]);
    }

    private function resolveUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
