<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\LoyaltyEngine\Domain\MemberSegment;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class MemberSegmentAnalyticsService
{
    public function __construct(
        private readonly MemberSegmentService $segmentService,
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /**
     * @return array{segmentsCount: int, segmentSummary: list<array{segment: array<string, mixed>, memberCount: int}>}
     */
    public function summary(?User $user, int $outletId): array
    {
        if ($outletId < 1) {
            throw ValidationException::withMessages([
                'outletId' => ['Outlet is required.'],
            ]);
        }

        $this->assertOutletAllowed($user, $outletId);

        $segments = MemberSegment::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $segmentSummary = $segments->map(function (MemberSegment $segment): array {
            return [
                'segment' => [
                    'id' => (string) $segment->id,
                    'code' => (string) $segment->code,
                    'name' => (string) $segment->name,
                    'segmentType' => (string) $segment->segment_type,
                ],
                'memberCount' => $this->segmentService->countMembers($segment),
            ];
        })->values()->all();

        return [
            'segmentsCount' => (int) MemberSegment::query()->where('outlet_id', $outletId)->count(),
            'segmentSummary' => $segmentSummary,
        ];
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
