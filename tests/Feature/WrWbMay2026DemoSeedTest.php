<?php

namespace Tests\Feature;

use App\Models\Modules\Loyalty\Domain\LoyaltyAccount;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Database\Seeders\CustomerDemo\WrWbMay2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WrWbMay2026DemoSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_wr_wb_demo_seed_produces_expected_may_2026_dataset(): void
    {
        $this->seed(WrWbMay2026Seeder::class);

        $outlet = Outlet::query()->where('code', 'DEMO-WRWB')->first();
        $this->assertNotNull($outlet);

        $this->assertSame(1, Outlet::query()->where('code', 'DEMO-WRWB')->count());
        $this->assertSame(6, User::query()->where('email', 'like', '%@wrwb.demo')->count());
        $this->assertNotNull(User::query()->where('email', 'admin@wrwb.demo')->first());

        $paidPosted = Order::query()
            ->where('outlet_id', $outlet->id)
            ->where('payment_status', 'paid')
            ->where('is_posted', true)
            ->where('code', 'like', 'WRWB-POS-%')
            ->count();
        $this->assertGreaterThanOrEqual(9, $paidPosted);

        $this->assertDatabaseHas('orders', [
            'outlet_id' => $outlet->id,
            'code' => 'WRWB-OPEN-01',
            'payment_status' => 'unpaid',
            'status' => 'confirmed',
        ]);

        $this->assertSame(2, QrOrderRequest::query()->where('outlet_id', $outlet->id)->where('request_code', 'like', 'WRWB-QR-%')->count());
        $this->assertSame(1, PosSession::query()->where('outlet_id', $outlet->id)->where('status', 'closed')->count());

        $procurementPostings = DB::table('procurement_postings')
            ->where('outlet_id', $outlet->id)
            ->where('status', 'posted')
            ->count();
        $this->assertGreaterThanOrEqual(5, $procurementPostings);

        $payrollJournal = DB::table('journals')
            ->where('outlet_id', $outlet->id)
            ->where('source_type', 'payroll_run_v2')
            ->where('status', 'posted')
            ->count();
        $this->assertGreaterThanOrEqual(1, $payrollJournal);

        $this->assertGreaterThanOrEqual(1, LoyaltyAccount::query()->where('outlet_id', $outlet->id)->count());

        $mayDebits = (float) DB::table('journal_entries')
            ->join('journals', 'journals.id', '=', 'journal_entries.journal_id')
            ->where('journals.outlet_id', $outlet->id)
            ->whereBetween('journals.journal_date', ['2026-05-01', '2026-05-31'])
            ->where('journals.status', 'posted')
            ->sum('journal_entries.debit');

        $mayCredits = (float) DB::table('journal_entries')
            ->join('journals', 'journals.id', '=', 'journal_entries.journal_id')
            ->where('journals.outlet_id', $outlet->id)
            ->whereBetween('journals.journal_date', ['2026-05-01', '2026-05-31'])
            ->where('journals.status', 'posted')
            ->sum('journal_entries.credit');

        $this->assertEquals(round($mayDebits, 2), round($mayCredits, 2));
    }
}
