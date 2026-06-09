<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\AutomationAlert;
use App\Models\Modules\Menu\Domain\AutomationNotification;
use App\Models\User;
use Illuminate\Support\Collection;

final class NotificationDispatchService
{
    public function __construct(
        private readonly MenuAutomationAuditService $auditService,
    ) {}

    /** @param array<int,string> $channels */
    public function dispatch(AutomationAlert $alert, array $channels, ?User $actor = null): Collection
    {
        $notifications = collect();

        foreach ($channels as $channel) {
            $notification = match ($channel) {
                AutomationNotification::CHANNEL_EMAIL => $this->dispatchEmail($alert),
                AutomationNotification::CHANNEL_WEBHOOK => $this->dispatchWebhook($alert),
                default => $this->dispatchDatabase($alert),
            };

            $notifications->push($notification);

            $this->auditService->log('automation_notification_sent', (int) $alert->id, (int) $alert->outlet_id, $actor, [
                'channel' => $channel,
                'notificationId' => (int) $notification->id,
            ]);
        }

        return $notifications;
    }

    private function dispatchDatabase(AutomationAlert $alert): AutomationNotification
    {
        return AutomationNotification::query()->create([
            'outlet_id' => $alert->outlet_id,
            'automation_alert_id' => $alert->id,
            'channel' => AutomationNotification::CHANNEL_DATABASE,
            'status' => 'sent',
            'payload_json' => [
                'title' => $alert->title,
                'description' => $alert->description,
                'severity' => $alert->severity,
                'alertType' => $alert->alert_type,
            ],
            'sent_at' => now(),
        ]);
    }

    private function dispatchEmail(AutomationAlert $alert): AutomationNotification
    {
        return AutomationNotification::query()->create([
            'outlet_id' => $alert->outlet_id,
            'automation_alert_id' => $alert->id,
            'channel' => AutomationNotification::CHANNEL_EMAIL,
            'status' => 'sent',
            'payload_json' => [
                'stub' => true,
                'recipient' => 'manager@outlet.local',
                'subject' => $alert->title,
                'body' => $alert->description,
            ],
            'sent_at' => now(),
        ]);
    }

    private function dispatchWebhook(AutomationAlert $alert): AutomationNotification
    {
        return AutomationNotification::query()->create([
            'outlet_id' => $alert->outlet_id,
            'automation_alert_id' => $alert->id,
            'channel' => AutomationNotification::CHANNEL_WEBHOOK,
            'status' => 'sent',
            'payload_json' => [
                'stub' => true,
                'url' => null,
                'event' => 'automation.alert',
                'alertId' => (string) $alert->id,
            ],
            'sent_at' => now(),
        ]);
    }
}
