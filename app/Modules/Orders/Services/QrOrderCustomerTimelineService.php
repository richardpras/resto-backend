<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\User;
use App\Modules\Kitchen\Support\KitchenStatusNormalizer;

class QrOrderCustomerTimelineService
{
    /** @return list<array{status: string, label: string, timestamp: string|null, actor: string|null}> */
    public function build(QrOrderRequest $request, string $locale = 'en'): array
    {
        $events = PosEventLog::query()
            ->where('entity_type', 'qr_order_request')
            ->where('entity_id', (int) $request->id)
            ->orderBy('id')
            ->get();

        $mapped = [];
        foreach ($events as $event) {
            $step = $this->mapEventToStep((string) $event->event_type, $locale);
            if ($step === null) {
                continue;
            }

            $mapped[] = [
                'status' => $step['status'],
                'label' => $step['label'],
                'timestamp' => $event->occurred_at?->toIso8601String() ?? $event->created_at?->toIso8601String(),
                'actor' => $this->resolveActorLabel($event->actor_user_id),
            ];
        }

        if ($mapped === []) {
            return $this->fallbackTimeline($request, $locale);
        }

        return $this->dedupeByStatus($mapped);
    }

    /** @return list<array{status: string, label: string, timestamp: string|null, actor: string|null}> */
    private function fallbackTimeline(QrOrderRequest $request, string $locale): array
    {
        $timeline = [[
            'status' => 'pending_review',
            'label' => $this->tl('pending_review', $locale),
            'timestamp' => $request->created_at?->toIso8601String(),
            'actor' => 'Customer',
        ]];

        if ($request->reviewed_at !== null) {
            $timeline[] = [
                'status' => 'under_review',
                'label' => $this->t('under_review', $locale),
                'timestamp' => $request->reviewed_at->toIso8601String(),
                'actor' => 'Cashier',
            ];
        }

        if ($request->confirmed_at !== null) {
            $timeline[] = [
                'status' => 'confirmed',
                'label' => $this->tl('confirmed', $locale),
                'timestamp' => $request->confirmed_at->toIso8601String(),
                'actor' => 'Cashier',
            ];
        }

        $order = $request->relationLoaded('order') ? $request->order : $request->order()->first();
        if ($order !== null) {
            $kitchen = KitchenStatusNormalizer::forOrder((string) ($order->kitchen_status ?? 'queued'));
            if (KitchenStatusNormalizer::isCookingPhase($kitchen) || in_array($kitchen, ['ready', 'served', 'completed'], true)) {
                $timeline[] = [
                    'status' => 'cooking',
                    'label' => $this->tl('sent_to_kitchen', $locale),
                    'timestamp' => $order->confirmed_at?->toIso8601String() ?? $request->confirmed_at?->toIso8601String(),
                    'actor' => 'Kitchen',
                ];
            }
            if (in_array($kitchen, ['ready', 'served', 'completed'], true)) {
                $timeline[] = [
                    'status' => 'ready',
                    'label' => $this->tl('ready', $locale),
                    'timestamp' => null,
                    'actor' => 'Kitchen',
                ];
            }
        }

        if ($request->customer_served_at !== null) {
            $timeline[] = [
                'status' => 'served',
                'label' => $this->tl('served', $locale),
                'timestamp' => $request->customer_served_at->toIso8601String(),
                'actor' => 'Cashier',
            ];
        }

        return $timeline;
    }

    /** @param list<array{status: string, label: string, timestamp: string|null, actor: string|null}> $rows */
    private function dedupeByStatus(array $rows): array
    {
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            if (isset($seen[$row['status']])) {
                continue;
            }
            $seen[$row['status']] = true;
            $result[] = $row;
        }

        return $result;
    }

    /** @return array{status: string, label: string}|null */
    private function mapEventToStep(string $eventType, string $locale): ?array
    {
        return match ($eventType) {
            'qr.request.created', 'customer_order.created' => ['status' => 'pending_review', 'label' => $this->tl('pending_review', $locale)],
            'qr_order.reviewed', 'customer_order.reviewed' => ['status' => 'under_review', 'label' => $this->t('under_review', $locale)],
            'qr_order.adjusted', 'customer_order.adjusted' => ['status' => 'adjusted', 'label' => $this->tl('adjusted', $locale)],
            'qr.request.confirmed', 'qr_order.confirmed', 'customer_order.confirmed' => ['status' => 'confirmed', 'label' => $this->tl('confirmed', $locale)],
            'customer_order.sent_to_kitchen' => ['status' => 'cooking', 'label' => $this->tl('sent_to_kitchen', $locale)],
            'customer_order.ready' => ['status' => 'ready', 'label' => $this->tl('ready', $locale)],
            'customer_order.served' => ['status' => 'served', 'label' => $this->tl('served', $locale)],
            'customer_order.completed' => ['status' => 'completed', 'label' => $this->tl('completed', $locale)],
            'customer_order.call_cashier' => ['status' => 'call_cashier', 'label' => $this->t('call_cashier', $locale)],
            default => null,
        };
    }

    private function t(string $key, string $locale): string
    {
        return (string) trans('qr.status.'.$key, [], $locale);
    }

    private function tl(string $key, string $locale): string
    {
        return (string) trans('qr.timeline.'.$key, [], $locale);
    }

    private function resolveActorLabel(?int $userId): ?string
    {
        if ($userId === null) {
            return 'System';
        }

        $user = User::query()->whereKey($userId)->first();
        if ($user === null) {
            return 'Staff';
        }

        return (string) ($user->name ?? 'Staff');
    }
}
