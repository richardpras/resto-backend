<?php

namespace Tests\Feature;

use App\Models\Modules\Inventory\Domain\DailyStocktakeSession;
use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\Concerns\BugReportTestFixture;
use Tests\TestCase;

class DailyStocktakeApiTest extends TestCase
{
    use BugReportTestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_daily_stocktake_session_lifecycle(): void
    {
        $outlet = Outlet::query()->create([
            'name' => 'Stocktake Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'stk-'.uniqid(),
        ]);

        $user = $this->createUserWithPermission('inventory.manage', $outlet);
        Passport::actingAs($user);

        $ingredient = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Flour',
            'type' => 'ingredient',
            'unit' => 'kg',
            'stock' => 10,
            'min' => 1,
            'price' => 10000,
        ]);
        DB::table('inventory_stocks')->insert([
            'ingredient_id' => $ingredient->id,
            'outlet_id' => $outlet->id,
            'stock' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $businessDate = now()->toDateString();

        $create = $this->postJson('/api/v1/inventory/daily-stocktake', [
            'outletId' => $outlet->id,
            'businessDate' => $businessDate,
        ]);
        $create->assertCreated();
        $sessionId = (int) $create->json('data.id');
        $this->assertSame('draft', $create->json('data.status'));
        $this->assertCount(1, $create->json('data.lines'));
        $create->assertJsonPath('data.lines.0.previousClosingQty', 10);
        $create->assertJsonPath('data.lines.0.openingQty', 10);
        $create->assertJsonPath('data.lines.0.overnightVarianceQty', 0);

        $this->getJson('/api/v1/inventory/daily-stocktake?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->patchJson("/api/v1/inventory/daily-stocktake/{$sessionId}/opening", [
            'lines' => [['ingredientId' => $ingredient->id, 'openingQty' => 9]],
        ])->assertOk()
            ->assertJsonPath('data.openingSubmittedAt', fn ($v) => $v !== null);

        $this->patchJson("/api/v1/inventory/daily-stocktake/{$sessionId}/closing", [
            'lines' => [['ingredientId' => $ingredient->id, 'closingQty' => 8]],
        ])->assertOk()
            ->assertJsonPath('data.closingSubmittedAt', fn ($v) => $v !== null);

        $this->postJson("/api/v1/inventory/daily-stocktake/{$sessionId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', DailyStocktakeSession::STATUS_PENDING_APPROVAL);

        $this->postJson("/api/v1/inventory/daily-stocktake/{$sessionId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', DailyStocktakeSession::STATUS_POSTED);

        $this->getJson("/api/v1/inventory/daily-stocktake/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.status', DailyStocktakeSession::STATUS_POSTED);
    }
}
