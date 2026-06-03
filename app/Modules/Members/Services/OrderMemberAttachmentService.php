<?php

namespace App\Modules\Members\Services;

use App\Models\Member;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\User;
use App\Modules\Orders\Repositories\OrderRepositoryInterface;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderMemberAttachmentService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly MemberService $memberService,
    ) {}

    public function setOrderMember(User $user, int $orderId, ?int $memberId): Order
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);

        return DB::transaction(function () use ($user, $orderId, $memberId, $allowed): Order {
            $order = $this->orderRepository->findScoped($orderId, $allowed);
            if ($order === null) {
                throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
            }

            $this->assertOrderMemberEditable($order);

            if ($memberId === null) {
                $this->orderRepository->update($order, ['member_id' => null]);

                return $this->orderRepository->findWithRelations($order->id)
                    ?? throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
            }

            $member = $this->memberService->findForOutlet($user, $memberId, (int) $order->outlet_id);
            if ($member === null) {
                throw ValidationException::withMessages([
                    'memberId' => ['Member not found for this outlet.'],
                ]);
            }

            if (! (bool) $member->is_active) {
                throw ValidationException::withMessages([
                    'memberId' => ['Member is inactive.'],
                ]);
            }

            $this->orderRepository->update($order, [
                'member_id' => $member->id,
                'customer_name' => $member->displayName(),
                'customer_phone' => $member->phone,
            ]);

            $fresh = $this->orderRepository->findWithRelations($order->id);
            if ($fresh === null) {
                throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
            }

            return $fresh;
        });
    }

    public function resolveMemberForOrderCreate(?User $user, ?int $outletId, ?int $memberId): ?Member
    {
        if ($memberId === null || $outletId === null || $outletId < 1) {
            return null;
        }

        $member = $this->memberService->findForOutlet($user, $memberId, $outletId);
        if ($member === null) {
            throw ValidationException::withMessages([
                'memberId' => ['Member not found for this outlet.'],
            ]);
        }

        if (! (bool) $member->is_active) {
            throw ValidationException::withMessages([
                'memberId' => ['Member is inactive.'],
            ]);
        }

        return $member;
    }

    private function assertOrderMemberEditable(Order $order): void
    {
        $paymentStatus = (string) $order->payment_status;
        if (! in_array($paymentStatus, ['unpaid', 'partial'], true)) {
            throw ValidationException::withMessages([
                'memberId' => ['Order member cannot be changed after payment is complete (status: '.$paymentStatus.').'],
            ]);
        }
        if ((string) $order->status === 'cancelled') {
            throw ValidationException::withMessages([
                'memberId' => ['Cancelled orders cannot be updated.'],
            ]);
        }
    }
}
