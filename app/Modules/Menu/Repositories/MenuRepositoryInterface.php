<?php

namespace App\Modules\Menu\Repositories;

use App\Models\Modules\Menu\Domain\MenuItem;

interface MenuRepositoryInterface
{
    public function paginateByTenant(int $tenantId, int $perPage = 20);

    public function findById(int $id): ?MenuItem;

    public function findWithRecipes(int $id): ?MenuItem;

    public function create(array $attributes): MenuItem;

    public function update(MenuItem $menuItem, array $attributes): bool;
}
