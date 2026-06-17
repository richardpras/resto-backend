<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\QrGuestSession;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class QrGuestSessionOrdersService
{
    public function __construct(
        private readonly QrGuestSessionService $guestSessionService,
        private readonly QrOrderCustomerStatusService $customerStatusService,
        private readonly QrOrderExpiryService $qrOrderExpiryService,
    ) {}

    /**
     * @return list<array{orderCode: string, customerStatus: string, customerStatusLabel: string, createdAt: string|null}>
     */
    public function listForToken(string $guestSessionToken, string $locale = 'en'): array
    {
        $session = $this->guestSessionService->findActiveByToken($guestSessionToken);
        if ($session === null) {
            throw (new ModelNotFoundException())->setModel(QrGuestSession::class, [$guestSessionToken]);
        }

        $requests = QrOrderRequest::query()
            ->where('guest_session_id', (int) $session->id)
            ->orderByDesc('id')
            ->get();

        $rows = [];
        foreach ($requests as $request) {
            $request = $this->qrOrderExpiryService->markExpiredIfNeeded($request);
            $status = $this->customerStatusService->resolve($request, $locale);
            $rows[] = [
                'orderCode' => (string) $request->request_code,
                'customerStatus' => $status['customerStatus'],
                'customerStatusLabel' => $status['customerStatusLabel'],
                'createdAt' => $request->created_at?->toIso8601String(),
            ];
        }

        return $rows;
    }
}
