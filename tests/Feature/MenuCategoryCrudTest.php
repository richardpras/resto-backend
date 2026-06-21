<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class MenuCategoryCrudTest extends TestCase
{
    use RefreshDatabase;
    use AccountingRemediationFixture;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_menu_category_crud_with_bilingual_fields(): void
    {
        [$user] = $this->actAsAdminWithOutlet('MC-CRUD');

        $create = $this->actingAs($user, 'api')->postJson('/api/v1/menu-categories', [
            'tenantId' => 1,
            'name' => 'Main Dishes',
            'nameEn' => 'Main Dishes',
            'nameId' => 'Hidangan Utama',
            'sortOrder' => 10,
            'isActive' => true,
        ]);
        $create->assertCreated();
        $categoryId = (int) $create->json('data.id');
        $this->assertSame('Main Dishes', $create->json('data.nameEn'));

        $update = $this->actingAs($user, 'api')->putJson("/api/v1/menu-categories/{$categoryId}", [
            'name' => 'Main Dishes Updated',
            'nameEn' => 'Main Dishes Updated',
            'nameId' => 'Hidangan Utama Diperbarui',
            'sortOrder' => 20,
            'isActive' => true,
        ]);
        $update->assertOk();
        $this->assertSame('Hidangan Utama Diperbarui', $update->json('data.nameId'));

        $list = $this->actingAs($user, 'api')->getJson('/api/v1/menu-categories?tenantId=1');
        $list->assertOk();
        $this->assertTrue(
            collect($list->json('data'))->contains(fn (array $row): bool => (int) ($row['id'] ?? 0) === $categoryId)
        );

        $this->assertDatabaseHas('menu_categories', [
            'id' => $categoryId,
            'name_id' => 'Hidangan Utama Diperbarui',
        ]);
    }
}
