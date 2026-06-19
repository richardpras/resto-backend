<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Menu\Domain\MenuItemOutlet;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class MenuItemImageTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Storage::fake('public');
        $this->actingAsInventoryUser();
    }

    public function test_upload_stores_compressed_image_and_sets_version(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required.');
        }

        $menuItem = $this->seedMenuItem();

        $response = $this->post('/api/v1/menu-items/'.$menuItem->id.'/image', [
            'image' => $this->makeUploadedJpeg(900, 700),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.imageVersion', 1);
        $response->assertJsonPath('data.hasImage', true);

        $menuItem->refresh();
        $this->assertSame(1, (int) $menuItem->image_version);
        $this->assertNotNull($menuItem->image_path);
        $this->assertLessThanOrEqual(200 * 1024, Storage::disk('public')->size((string) $menuItem->image_path));
    }

    public function test_replace_upload_increments_version_and_deletes_old_file(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required.');
        }

        $menuItem = $this->seedMenuItem();

        $this->post('/api/v1/menu-items/'.$menuItem->id.'/image', [
            'image' => $this->makeUploadedJpeg(800, 600),
        ], ['Accept' => 'application/json'])->assertOk();

        $menuItem->refresh();
        $firstPath = (string) $menuItem->image_path;

        $this->post('/api/v1/menu-items/'.$menuItem->id.'/image', [
            'image' => $this->makeUploadedJpeg(700, 500),
        ], ['Accept' => 'application/json'])->assertOk();

        $menuItem->refresh();
        $this->assertSame(2, (int) $menuItem->image_version);
        $this->assertNotSame($firstPath, $menuItem->image_path);
        Storage::disk('public')->assertMissing($firstPath);
    }

    public function test_delete_clears_image_columns_and_storage(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required.');
        }

        $menuItem = $this->seedMenuItem();

        $this->post('/api/v1/menu-items/'.$menuItem->id.'/image', [
            'image' => $this->makeUploadedJpeg(500, 400),
        ], ['Accept' => 'application/json'])->assertOk();

        $menuItem->refresh();
        $path = (string) $menuItem->image_path;

        $this->deleteJson('/api/v1/menu-items/'.$menuItem->id.'/image')
            ->assertOk()
            ->assertJsonPath('data.hasImage', false);

        $menuItem->refresh();
        $this->assertNull($menuItem->image_path);
        $this->assertSame(0, (int) $menuItem->image_version);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_public_qr_menu_returns_active_outlet_items_with_image_url(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required.');
        }

        [$outlet, $table, $menuItem] = $this->seedQrMenuSetup();

        $this->post('/api/v1/menu-items/'.$menuItem->id.'/image', [
            'image' => $this->makeUploadedJpeg(500, 400),
        ], ['Accept' => 'application/json'])->assertOk();

        $response = $this->getJson('/api/v1/public/qr/tables/'.$table->qr_public_id.'/menu');
        $response->assertOk();
        $response->assertJsonPath('data.0.id', (string) $menuItem->id);
        $response->assertJsonPath('data.0.hasImage', true);
        $this->assertNotNull($response->json('data.0.imageUrl'));
    }

    public function test_image_serve_returns_cache_headers_and_rejects_wrong_version(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required.');
        }

        $menuItem = $this->seedMenuItem();
        $this->post('/api/v1/menu-items/'.$menuItem->id.'/image', [
            'image' => $this->makeUploadedJpeg(500, 400),
        ], ['Accept' => 'application/json'])->assertOk();

        $menuItem->refresh();
        $version = (int) $menuItem->image_version;

        $ok = $this->get('/api/v1/public/menu-images/'.$menuItem->id.'?v='.$version);
        $ok->assertOk();
        $cacheControl = (string) $ok->headers->get('Cache-Control');
        $this->assertStringContainsString('immutable', $cacheControl);
        $this->assertStringContainsString('max-age=31536000', $cacheControl);
        $this->assertStringContainsString('public', $cacheControl);

        $this->get('/api/v1/public/menu-images/'.$menuItem->id.'?v='.($version + 1))
            ->assertNotFound();
    }

    private function seedMenuItem(): MenuItem
    {
        $outlet = Outlet::query()->create([
            'name' => 'Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'img-'.uniqid(),
        ]);

        return MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Menu '.uniqid(),
            'category' => 'Main',
            'price' => 25000,
            'available' => true,
            'emoji' => '🍛',
        ]);
    }

    /** @return array{0: Outlet, 1: RestaurantTable, 2: MenuItem} */
    private function seedQrMenuSetup(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'qr-menu-'.uniqid(),
        ]);

        $table = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T1',
            'capacity' => 4,
            'status' => 'active',
            'active' => true,
            'qr_public_id' => 'qr-menu-'.uniqid(),
            'qr_enabled' => true,
        ]);

        $menuItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'QR Menu Item',
            'category' => 'Main',
            'price' => 30000,
            'available' => true,
            'emoji' => '🍗',
        ]);

        MenuItemOutlet::query()->create([
            'menu_item_id' => $menuItem->id,
            'outlet_id' => $outlet->id,
            'is_active' => true,
        ]);

        return [$outlet, $table, $menuItem];
    }

    private function makeUploadedJpeg(int $width, int $height): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 220, 120, 60);
        imagefill($image, 0, 0, $color);
        ob_start();
        imagejpeg($image, null, 92);
        $binary = ob_get_clean();
        imagedestroy($image);

        $path = tempnam(sys_get_temp_dir(), 'menu-test-');
        file_put_contents($path, $binary);

        return new UploadedFile($path, 'menu-test.jpg', 'image/jpeg', null, true);
    }
}
