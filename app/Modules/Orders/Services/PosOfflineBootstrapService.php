<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use App\Modules\Menu\Http\Resources\MenuItemResource;
use App\Modules\Orders\Http\Resources\RestaurantTableResource;
use App\Modules\Settings\Services\OutletPaymentMethodConfigService;
use App\Modules\Settings\Services\OutletReceiptSettingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PosOfflineBootstrapService
{
    public function __construct(
        private readonly PosBootstrapService $posBootstrapService,
        private readonly TableMasterService $tableMasterService,
        private readonly OutletPaymentMethodConfigService $paymentMethodConfigService,
        private readonly OutletReceiptSettingService $receiptSettingService,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function build(User $user, array $input): array
    {
        $outletId = (int) $input['outletId'];
        $bootstrap = $this->posBootstrapService->build($user, $input);

        $outlet = Outlet::query()->whereKey($outletId)->first();
        if ($outlet === null) {
            throw (new ModelNotFoundException)->setModel(Outlet::class, [(string) $outletId]);
        }

        $tables = $this->tableMasterService->listForOutlet($user, $outletId);
        $checkoutMethods = $this->paymentMethodConfigService->listCheckoutMethods($user, $outletId);
        $receiptSettings = $this->receiptSettingService->forOutlet($outlet);

        return [
            'generatedAt' => now()->toIso8601String(),
            'schemaVersion' => 1,
            'outletId' => $outletId,
            'merchant' => $bootstrap['merchant'],
            'system' => $bootstrap['system'],
            'outletTaxRules' => $bootstrap['outletTaxRules'],
            'menuItems' => $bootstrap['menuItems'],
            'tables' => RestaurantTableResource::collection($tables)->resolve(),
            'checkoutMethods' => $checkoutMethods,
            'receiptSettings' => $receiptSettings,
            'thermalPaperWidth' => '58mm',
        ];
    }
}
