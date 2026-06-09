<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Account;
use Database\Seeders\TemplateAccountingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategorizedCoaSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_accounting_seeder_creates_grni_account(): void
    {
        $this->seed(TemplateAccountingSeeder::class);

        $this->assertDatabaseHas('accounts', [
            'code' => '2140',
            'name' => 'GRNI',
            'category' => 'grni',
        ]);
        $this->assertSame(1, Account::query()->where('code', '2140')->where('category', 'grni')->count());
    }
}
