<?php

namespace App\Modules\HR\Services;

use App\Models\User;
use Symfony\Component\HttpFoundation\Response;

class AuthorizationService
{
    public function ensurePermission(int $userId, string $permissionCode): void
    {
        $user = User::query()->find($userId);
        abort_if($user === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Request user not found.');
        abort_if(! $user->hasPermission($permissionCode), Response::HTTP_FORBIDDEN, 'Permission denied.');
    }
}
