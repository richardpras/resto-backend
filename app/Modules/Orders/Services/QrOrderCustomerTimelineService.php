<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\User;

class QrOrderCustomerTimelineService
{
    /** @return list<array{status: string, label: string, timestamp: string|null, actor: string|null}> */
    public function build(QrOrderRequest $request): array
    {
        $events = PosEventLog::query()
            ->where('entity_type', 'qr_order_request')
            ->where('entity_id', (int) $request->id)
            ->orderBy('id')
            ->get();

        $mapped = [];
        foreach ($events as $event) {
            $step = $this->mapEventToStep((string) $event->event_type);
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
            return $this->fallbackTimeline($request);
        }

        return $this->dedupeByStatus($mapped);
    }

    /** @return list<array{status: string, label: string, timestamp: string|null, actor: string|null}> */
    private function fallbackTimeline(QrOrderRequest $request): array
    {
        $timeline = [[
            'status' => 'pending_review',
            'label' => 'Pesanan dikirim',
            'timestamp' => $request->created_at?->toIso8601String(),
            'actor' => 'Customer',
        ]];

        if ($request->reviewed_at !== null) {
            $timeline[] = [
                'status' => 'under_review',
                'label' => 'Direview kasir',
                'timestamp' => $request->reviewed_at->toIso8601String(),
                'actor' => 'Cashier',
            ];
        }

        if ($request->confirmed_at !== null) {
            $timeline[] = [
                'status' => 'confirmed',
                'label' => 'Dikonfirmasi',
                'timestamp' => $request->confirmed_at->toIso8601String(),
                'actor' => 'Cashier',
            ];
        }

        $order = $request->relationLoaded('order') ? $request->order : $request->order()->first();
        if ($order !== null) {
            $kitchen = (string) ($order->kitchen_status ?? 'queued');
            if (in_array($kitchen, ['preparing', 'in_kitchen', 'cooking', 'ready', 'served', 'completed'], true)) {
                $timeline[] = [
                    'status' => 'cooking',
                    'label' => 'Dikirim ke dapur',
                    'timestamp' => $order->confirmed_at?->toIso8601String() ?? $request->confirmed_at?->toIso8601String(),
                    'actor' => 'Kitchen',
                ];
            }
            if (in_array($kitchen, ['ready', 'served', 'completed'], true)) {
                $timeline[] = [
                    'status' => 'ready',
                    'label' => 'Siap diantar',
                    'timestamp' => null,
                    'actor' => 'Kitchen',
                ];
            }
        }

        if ($request->customer_served_at !== null) {
            $timeline[] = [
                'status' => 'served',
                'label' => 'Sudah diantar',
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
    private function mapEventToStep(string $eventType): ?array
    {
        return match ($eventType) {
            'qr.request.created', 'customer_order.created' => ['status' => 'pending_review', 'label' => 'Pesanan dikirim'],
            'qr_order.reviewed', 'customer_order.reviewed' => ['status' => 'under_review', 'label' => 'Direview kasir'],
            'qr_order.adjusted', 'customer_order.adjusted' => ['status' => 'adjusted', 'label' => 'Diubah kasir'],
            'qr.request.confirmed', 'qr_order.confirmed', 'customer_order.confirmed' => ['status' => 'confirmed', 'label' => 'Dikonfirmasi'],
            'customer_order.sent_to_kitchen' => ['status' => 'cooking', 'label' => 'Dikirim ke dapur'],
            'customer_order.ready' => ['status' => 'ready', 'label' => 'Siap diantar'],
            'customer_order.served' => ['status' => 'served', 'label' => 'Sudah diantar'],
            'customer_order.completed' => ['status' => 'completed', 'label' => 'Selesai'],
            'customer_order.call_cashier' => ['status' => 'call_cashier', 'label' => 'Memanggil kasir'],
            default => null,
        };
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
