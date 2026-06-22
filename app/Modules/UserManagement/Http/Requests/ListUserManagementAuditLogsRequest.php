<?php

namespace App\Modules\UserManagement\Http\Requests;

use App\Models\Modules\UserManagement\Domain\UserManagementAuditLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListUserManagementAuditLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'action' => ['sometimes', 'string', Rule::in([
                UserManagementAuditLog::ACTION_USER_CREATED,
                UserManagementAuditLog::ACTION_ROLE_PERMISSION_CHANGED,
                UserManagementAuditLog::ACTION_USER_PIN_SET,
                UserManagementAuditLog::ACTION_USER_PIN_CLEARED,
                UserManagementAuditLog::ACTION_ROLE_CREATED,
                UserManagementAuditLog::ACTION_PERMISSION_CREATED,
            ])],
            'entityType' => ['sometimes', 'string', Rule::in([
                UserManagementAuditLog::ENTITY_USER,
                UserManagementAuditLog::ENTITY_ROLE,
                UserManagementAuditLog::ENTITY_PERMISSION,
            ])],
            'entityId' => ['sometimes', 'integer', 'min:1'],
            'targetUserId' => ['sometimes', 'integer', 'min:1'],
            'actorUserId' => ['sometimes', 'integer', 'min:1'],
            'fromDate' => ['sometimes', 'date'],
            'toDate' => ['sometimes', 'date', 'after_or_equal:fromDate'],
            'search' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
