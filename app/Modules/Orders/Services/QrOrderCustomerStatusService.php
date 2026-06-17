<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Modules\Kitchen\Support\KitchenStatusNormalizer;

class QrOrderCustomerStatusService
{
    /** @return array{status: string, customerStatus: string, customerStatusLabel: string, timelineStep: int|null, isTerminal: bool} */
    public function resolve(QrOrderRequest $request, string $locale = 'en'): array
    {
        $requestStatus = (string) $request->status;

        if ($requestStatus === 'rejected' || $requestStatus === 'expired') {
            return $this->pack('cancelled', 'cancelled', $this->t('cancelled', $locale), null, true);
        }

        if (in_array($requestStatus, ['pending_cashier_confirmation', 'under_review'], true)) {
            if ($this->hasPendingAdjustments($request)) {
                if ((string) ($request->customer_approval_status ?? '') === 'pending_approval') {
                    return $this->pack('adjusted', 'adjusted', $this->t('adjusted_pending', $locale), null, false);
                }

                return $this->pack('adjusted', 'adjusted', $this->t('adjusted', $locale), null, false);
            }

            if ($requestStatus === 'pending_cashier_confirmation') {
                return $this->pack('pending_review', 'pending_review', $this->t('pending_review', $locale), 0, false);
            }

            return $this->pack('under_review', 'under_review', $this->t('under_review', $locale), 0, false);
        }

        if ($requestStatus === 'confirmed' || $requestStatus === 'paid') {
            $order = $request->relationLoaded('order') ? $request->order : $request->order()->first();
            if ($order === null) {
                return $this->pack('confirmed', 'confirmed', $this->t('confirmed', $locale), 1, false);
            }

            if ((string) $order->status === 'cancelled') {
                return $this->pack('cancelled', 'cancelled', $this->t('cancelled', $locale), null, true);
            }

            return $this->resolveFromKitchenStatus(
                KitchenStatusNormalizer::forOrder((string) ($order->kitchen_status ?? 'queued')),
                (string) $order->payment_status,
                $request->customer_served_at !== null,
                $locale,
            );
        }

        return $this->pack($requestStatus, $requestStatus, $requestStatus, null, false);
    }

    /** @return list<array{key: string, label: string}> */
    public function timelineSteps(string $locale = 'en'): array
    {
        return [
            ['key' => 'pending_review', 'label' => $this->tl('pending_review', $locale)],
            ['key' => 'confirmed', 'label' => $this->tl('confirmed', $locale)],
            ['key' => 'cooking', 'label' => $this->tl('cooking', $locale)],
            ['key' => 'ready', 'label' => $this->tl('ready', $locale)],
            ['key' => 'served', 'label' => $this->tl('served', $locale)],
            ['key' => 'completed', 'label' => $this->tl('completed', $locale)],
        ];
    }

    /** @return array{status: string, customerStatus: string, customerStatusLabel: string, timelineStep: int|null, isTerminal: bool} */
    private function resolveFromKitchenStatus(
        string $kitchenStatus,
        string $paymentStatus,
        bool $customerMarkedServed,
        string $locale,
    ): array {
        if ($this->isFullyComplete($kitchenStatus, $paymentStatus, $customerMarkedServed)) {
            return $this->pack('completed', 'completed', $this->t('completed', $locale), 5, true);
        }

        if ($this->isKitchenFinished($kitchenStatus, $customerMarkedServed)) {
            return $this->pack('served', 'served', $this->t('served', $locale), 4, false);
        }

        return match ($kitchenStatus) {
            'queued' => $this->pack('confirmed', 'confirmed', $this->t('confirmed', $locale), 1, false),
            'preparing', 'in_kitchen', 'cooking', 'in_progress' => $this->pack('cooking', 'cooking', $this->t('cooking', $locale), 2, false),
            'ready' => $this->pack('ready', 'ready', $this->t('ready', $locale), 3, false),
            default => $this->pack('confirmed', 'confirmed', $this->t('confirmed', $locale), 1, false),
        };
    }

    private function isKitchenFinished(string $kitchenStatus, bool $customerMarkedServed): bool
    {
        if ($customerMarkedServed) {
            return true;
        }

        return in_array($kitchenStatus, ['served', 'completed'], true);
    }

    private function isFullyComplete(string $kitchenStatus, string $paymentStatus, bool $customerMarkedServed): bool
    {
        return $this->isKitchenFinished($kitchenStatus, $customerMarkedServed)
            && $paymentStatus === 'paid';
    }

    /** @return array{status: string, customerStatus: string, customerStatusLabel: string, timelineStep: int|null, isTerminal: bool} */
    private function pack(
        string $status,
        string $customerStatus,
        string $customerStatusLabel,
        ?int $timelineStep,
        bool $isTerminal,
    ): array {
        return [
            'status' => $status,
            'customerStatus' => $customerStatus,
            'customerStatusLabel' => $customerStatusLabel,
            'timelineStep' => $timelineStep,
            'isTerminal' => $isTerminal,
        ];
    }

    private function t(string $key, string $locale): string
    {
        return (string) trans('qr.status.'.$key, [], $locale);
    }

    private function tl(string $key, string $locale): string
    {
        return (string) trans('qr.timeline.'.$key, [], $locale);
    }

    private function hasPendingAdjustments(QrOrderRequest $request): bool
    {
        if ($this->hasReviewAdjustments($request)) {
            return true;
        }

        $log = $request->adjustment_log;
        if (! is_array($log) || $log === []) {
            return false;
        }

        return collect($log)->contains(function (array $entry): bool {
            $summary = $entry['summary'] ?? [];

            return is_array($summary) && $summary !== [];
        });
    }

    private function hasReviewAdjustments(QrOrderRequest $request): bool
    {
        $draft = is_array($request->review_draft) ? $request->review_draft : null;
        if ($draft === null) {
            return false;
        }

        return ($draft['adjustments'] ?? []) !== [];
    }
}
