<?php

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Menu\Http\Resources\MenuItemResource;
use App\Modules\Menu\Services\MenuService;
use App\Modules\Orders\Http\Resources\PosSessionResource;
use App\Modules\Settings\Services\SettingsDomainService;

class PosBootstrapService
{
    public function __construct(
        private readonly MenuService $menuService,
        private readonly SettingsDomainService $settingsDomainService,
        private readonly PosSessionService $posSessionService,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function build(User $user, array $input): array
    {
        $tenantId = (int) ($input['tenantId'] ?? 0);
        $outletId = (int) $input['outletId'];
        $perPage = (int) ($input['perPage'] ?? 200);

        $menuItems = $this->menuService->listByTenant($tenantId, $perPage, $outletId);
        $session = $this->posSessionService->current($user, $outletId);

        return [
            'merchant' => $this->posMerchantSnapshot(),
            'system' => $this->posSystemSnapshot(),
            'defaultCashFloat' => $this->posSessionService->defaultCashFloatForOutlet($outletId),
            'menuItems' => [
                'data' => MenuItemResource::collection($menuItems->getCollection())->resolve(),
                'meta' => [
                    'current_page' => $menuItems->currentPage(),
                    'perPage' => $menuItems->perPage(),
                    'total' => $menuItems->total(),
                    'lastPage' => $menuItems->lastPage(),
                ],
            ],
            'posSession' => $session !== null ? (new PosSessionResource($session))->resolve() : null,
            'outletTaxRules' => $this->settingsDomainService->listOutletTaxRulesForPos($outletId),
        ];
    }

    /** @return array<string, mixed> */
    private function posMerchantSnapshot(): array
    {
        $merchant = $this->settingsDomainService->getMerchant();

        return [
            'name' => (string) ($merchant['name'] ?? ''),
            'currency' => (string) ($merchant['currency'] ?? 'IDR'),
            'timezone' => (string) ($merchant['timezone'] ?? 'Asia/Jakarta'),
            'language' => (string) ($merchant['language'] ?? 'en'),
            'logo' => $merchant['logo'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function posSystemSnapshot(): array
    {
        $system = $this->settingsDomainService->getSystem();

        return [
            'enableSplitBill' => (bool) ($system['enableSplitBill'] ?? true),
            'enableMultiPayment' => (bool) ($system['enableMultiPayment'] ?? true),
            'confirmBeforePayment' => (bool) ($system['confirmBeforePayment'] ?? true),
            'enableQROrdering' => (bool) ($system['enableQROrdering'] ?? true),
            'enableCallCashier' => (bool) ($system['enableCallCashier'] ?? true),
            'requireCustomerApprovalForAdjustments' => (bool) ($system['requireCustomerApprovalForAdjustments'] ?? false),
            'qrPendingConfirmationTtlMinutes' => (int) ($system['qrPendingConfirmationTtlMinutes'] ?? 20),
            'enforceStockOnSale' => (bool) ($system['enforceStockOnSale'] ?? false),
            'stockEnforcementMode' => (string) ($system['stockEnforcementMode'] ?? 'deferred'),
            'allowNegativeStock' => (bool) ($system['allowNegativeStock'] ?? true),
        ];
    }
}
