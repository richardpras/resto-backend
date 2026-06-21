<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class OutletLogoUploadTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        Storage::fake('public');
    }

    public function test_upload_increments_version_and_serves_public_logo(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required for outlet logo processing.');
        }

        [, $outlet] = $this->actAsAdminWithOutlet();
        $file = UploadedFile::fake()->image('logo.png', 220, 220);

        $response = $this->postJson('/api/v1/outlets/'.$outlet->id.'/logo', [
            'image' => $file,
        ]);
        $response->assertOk();
        $this->assertTrue((bool) $response->json('data.hasLogo'));
        $this->assertSame(1, (int) $response->json('data.logoVersion'));

        $outlet->refresh();
        $this->assertSame(1, (int) $outlet->logo_version);
        $this->assertNotNull($outlet->logo_path);
        $this->assertNotNull($outlet->logo_thermal_path);
        Storage::disk('public')->assertExists((string) $outlet->logo_path);
        Storage::disk('public')->assertExists((string) $outlet->logo_thermal_path);

        $serve = $this->get('/api/v1/public/outlet-logos/'.$outlet->id.'?v=1');
        $serve->assertOk();

        $this->get('/api/v1/public/outlet-logos/'.$outlet->id.'?v=0')->assertNotFound();
    }

    public function test_delete_removes_logo_files_and_resets_version(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required for outlet logo processing.');
        }

        [, $outlet] = $this->actAsAdminWithOutlet();
        $this->postJson('/api/v1/outlets/'.$outlet->id.'/logo', [
            'image' => UploadedFile::fake()->image('logo.png', 220, 220),
        ])->assertOk();

        $outlet->refresh();
        $displayPath = (string) $outlet->logo_path;
        $thermalPath = (string) $outlet->logo_thermal_path;

        $this->deleteJson('/api/v1/outlets/'.$outlet->id.'/logo')->assertOk();

        $outlet->refresh();
        $this->assertSame(0, (int) $outlet->logo_version);
        $this->assertNull($outlet->logo_path);
        Storage::disk('public')->assertMissing($displayPath);
        Storage::disk('public')->assertMissing($thermalPath);
    }

    /** @return array{0:\App\Models\User,1:Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'Logo Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'logo-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        return [$user, $outlet];
    }
}
