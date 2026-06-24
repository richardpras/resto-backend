<?php

namespace Database\Seeders;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Accounting\Domain\AccountingPostingMapping;
use Database\Seeders\Support\PostingMappingDefaults;
use Illuminate\Database\Seeder;

/**
 * Idempotent missing-only seeder for tenant-default posting mappings.
 * Does not overwrite rows already configured via UI or prior seed runs.
 */
class AccountingPostingMappingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PostingMappingDefaults::allModules() as $module => $rules) {
            foreach ($rules as $ruleKey => $accountCode) {
                $this->seedRuleIfMissing($module, $ruleKey, $accountCode);
            }
        }
    }

    private function seedRuleIfMissing(string $module, string $ruleKey, string $accountCode): void
    {
        $exists = AccountingPostingMapping::query()
            ->whereNull('tenant_id')
            ->whereNull('outlet_id')
            ->where('module', $module)
            ->where('rule_key', $ruleKey)
            ->exists();

        if ($exists) {
            return;
        }

        $accountId = Account::query()->where('code', $accountCode)->value('id');
        if ($accountId === null) {
            return;
        }

        AccountingPostingMapping::query()->create([
            'tenant_id' => null,
            'outlet_id' => null,
            'module' => $module,
            'rule_key' => $ruleKey,
            'chart_account_id' => (int) $accountId,
        ]);
    }
}
