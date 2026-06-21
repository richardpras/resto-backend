<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\MenuCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MenuCategoryService
{
    /**
     * @return Collection<int, MenuCategory>
     */
    public function list(?int $tenantId, bool $activeOnly = false): Collection
    {
        return MenuCategory::query()
            ->when($tenantId !== null && $tenantId > 0, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function create(array $payload): MenuCategory
    {
        $tenantId = isset($payload['tenantId']) ? (int) $payload['tenantId'] : null;
        $name = trim((string) $payload['name']);
        $code = $this->nextCode($tenantId, $name);

        return MenuCategory::query()->create([
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => $name,
            'name_en' => $this->normalizeOptionalString($payload['nameEn'] ?? $name) ?? $name,
            'name_id' => $this->normalizeOptionalString($payload['nameId'] ?? $name) ?? $name,
            'description' => isset($payload['description']) ? trim((string) $payload['description']) : null,
            'description_en' => $this->normalizeOptionalString($payload['descriptionEn'] ?? $payload['description'] ?? null),
            'description_id' => $this->normalizeOptionalString($payload['descriptionId'] ?? $payload['description'] ?? null),
            'sort_order' => isset($payload['sortOrder']) ? (int) $payload['sortOrder'] : 100,
            'is_active' => isset($payload['isActive']) ? (bool) $payload['isActive'] : true,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function update(int $id, array $payload): MenuCategory
    {
        $category = MenuCategory::query()->find($id);
        if (! $category instanceof MenuCategory) {
            throw ValidationException::withMessages([
                'id' => ['Menu category not found.'],
            ]);
        }

        $attributes = [];
        if (array_key_exists('name', $payload)) {
            $nextName = trim((string) $payload['name']);
            $attributes['name'] = $nextName;
            $attributes['code'] = $this->nextCode(
                $category->tenant_id !== null ? (int) $category->tenant_id : null,
                $nextName,
                (int) $category->id
            );
        }
        if (array_key_exists('description', $payload)) {
            $description = trim((string) ($payload['description'] ?? ''));
            $attributes['description'] = $description === '' ? null : $description;
        }
        if (array_key_exists('nameEn', $payload)) {
            $attributes['name_en'] = $this->normalizeOptionalString($payload['nameEn']);
        }
        if (array_key_exists('nameId', $payload)) {
            $attributes['name_id'] = $this->normalizeOptionalString($payload['nameId']);
        }
        if (array_key_exists('descriptionEn', $payload)) {
            $attributes['description_en'] = $this->normalizeOptionalString($payload['descriptionEn']);
        }
        if (array_key_exists('descriptionId', $payload)) {
            $attributes['description_id'] = $this->normalizeOptionalString($payload['descriptionId']);
        }
        if (array_key_exists('sortOrder', $payload)) {
            $attributes['sort_order'] = (int) $payload['sortOrder'];
        }
        if (array_key_exists('isActive', $payload)) {
            $attributes['is_active'] = (bool) $payload['isActive'];
        }

        if ($attributes !== []) {
            $category->fill($attributes);
            $category->save();
        }

        return $category->fresh() ?? $category;
    }

    private function nextCode(?int $tenantId, string $name, ?int $exceptId = null): string
    {
        $base = Str::slug(Str::lower($name), '_');
        if ($base === '') {
            $base = 'uncategorized';
        }
        $code = $base;
        $suffix = 1;

        while (true) {
            $exists = MenuCategory::query()
                ->where('tenant_id', $tenantId)
                ->where('code', $code)
                ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
                ->exists();
            if (! $exists) {
                break;
            }
            $suffix++;
            $code = substr($base, 0, max(1, 75)).'_'.$suffix;
        }

        return $code;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
