<?php

namespace App\Modules\Orders\Services;

use Illuminate\Validation\ValidationException;

class PosTransitionValidator
{
    public function assertPaymentStatusTransition(string $from, string $to): void
    {
        $this->assertAllowed($from, $to, [
            'unpaid' => ['partial', 'paid'],
            'partial' => ['paid'],
            'paid' => [],
        ], 'paymentStatus');
    }

    public function assertKitchenStatusTransition(string $from, string $to): void
    {
        $this->assertAllowed($from, $to, [
            'queued' => ['in_progress', 'cancelled'],
            'in_progress' => ['ready', 'cancelled'],
            'ready' => ['served'],
            'served' => [],
            'cancelled' => [],
        ], 'kitchenStatus');
    }

    public function assertSessionStatusTransition(string $from, string $to): void
    {
        $this->assertAllowed($from, $to, [
            'open' => ['closed'],
            'closed' => [],
        ], 'status');
    }

    public function assertQrRequestStatusTransition(string $from, string $to): void
    {
        $this->assertAllowed($from, $to, [
            'pending_cashier_confirmation' => ['under_review', 'confirmed', 'paid', 'rejected', 'expired'],
            'under_review' => ['confirmed', 'paid', 'rejected', 'expired'],
            'confirmed' => ['paid'],
            'paid' => [],
            'rejected' => [],
            'expired' => [],
        ], 'status');
    }

    /** @param array<string,list<string>> $graph */
    private function assertAllowed(string $from, string $to, array $graph, string $field): void
    {
        if ($from === $to) {
            return;
        }
        if (! in_array($to, $graph[$from] ?? [], true)) {
            throw ValidationException::withMessages([
                $field => ["Invalid transition: {$from} -> {$to}."],
            ]);
        }
    }
}
