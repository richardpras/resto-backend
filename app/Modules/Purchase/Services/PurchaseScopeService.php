<?php

namespace App\Modules\Purchase\Services;

use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\Response;

final class PurchaseScopeService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    public function resolveOutletId(?User $actor, mixed $requestedOutletId): int
    {
        abort_if(! is_numeric($requestedOutletId) || (int) $requestedOutletId < 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'outletId is required.');

        $outletId = (int) $requestedOutletId;
        $this->assertOutletAllowed($actor, $outletId);

        return $outletId;
    }

    /** @return list<int>|null null = unrestricted (view all outlets) */
    public function allowedOutletIds(?User $actor): ?array
    {
        if ($actor === null) {
            return null;
        }

        if ($actor->hasPermission('outlets.view_all') || $actor->hasPermission('dashboard.view_all_outlets')) {
            return null;
        }

        return $this->outletAccessResolver->allowedOutletIds($actor);
    }

    public function assertOutletAllowed(?User $actor, int $outletId): void
    {
        $allowed = $this->allowedOutletIds($actor);
        if ($allowed === null) {
            return;
        }

        abort_if(
            ! in_array($outletId, $allowed, true),
            Response::HTTP_FORBIDDEN,
            'Outlet access denied.'
        );
    }

    public function assertDocumentOutlet(?User $actor, ?int $documentOutletId): void
    {
        if ($documentOutletId === null || $documentOutletId < 1) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Document outlet_id is required.');
        }

        $this->assertOutletAllowed($actor, $documentOutletId);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function applyOutletScope(Builder $query, ?User $actor, mixed $requestedOutletId, string $column = 'outlet_id'): Builder
    {
        if (is_numeric($requestedOutletId) && (int) $requestedOutletId >= 1) {
            $outletId = (int) $requestedOutletId;
            $this->assertOutletAllowed($actor, $outletId);

            return $query->where($column, $outletId);
        }

        $allowed = $this->allowedOutletIds($actor);
        if ($allowed !== null) {
            if ($allowed === []) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereIn($column, $allowed);
        }

        return $query;
    }

    public function requestedOutletIdFromRequest(): ?int
    {
        $raw = request()->query('outletId', request()->input('outletId'));

        return is_numeric($raw) && (int) $raw >= 1 ? (int) $raw : null;
    }
}
