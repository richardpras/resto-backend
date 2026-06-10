<?php

namespace App\Modules\Notifications\Http\Requests;

use App\Models\Modules\Notifications\Domain\UserNotification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListUserNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'unread' => ['sometimes', 'boolean'],
            'severity' => ['sometimes', 'string', Rule::in([
                UserNotification::SEVERITY_INFO,
                UserNotification::SEVERITY_SUCCESS,
                UserNotification::SEVERITY_WARNING,
                UserNotification::SEVERITY_CRITICAL,
            ])],
            'source' => ['sometimes', 'string', Rule::in([
                UserNotification::MODULE_ACCOUNTING,
                UserNotification::MODULE_PAYMENTS,
                UserNotification::MODULE_MONITORING,
                UserNotification::MODULE_INVENTORY,
                UserNotification::MODULE_PROCUREMENT,
                UserNotification::MODULE_PAYROLL,
                UserNotification::MODULE_HR,
                UserNotification::MODULE_CRM,
                UserNotification::MODULE_SYSTEM,
                UserNotification::MODULE_MENU_INTELLIGENCE,
            ])],
            'outletId' => ['sometimes', 'integer', 'min:1'],
            'dateFrom' => ['sometimes', 'date'],
            'dateTo' => ['sometimes', 'date'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
