<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use App\Modules\Orders\Http\Resources\OrderResource;
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
        private readonly OrderService $orderService,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function build(User $user, array $input): array
    {
        $outletId = (int) $input['outletId'];
        $tenantId = (int) ($input['tenantId'] ?? 1);
        $bootstrap = $this->posBootstrapService->build($user, $input);

        $outlet = Outlet::query()->whereKey($outletId)->first();
        if ($outlet === null) {
            throw (new ModelNotFoundException)->setModel(Outlet::class, [(string) $outletId]);
        }

        $tables = $this->tableMasterService->listForOutlet($user, $outletId);
        $checkoutMethods = $this->paymentMethodConfigService->listCheckoutMethods($user, $outletId);
        $receiptSettings = $this->receiptSettingService->forOutlet($outlet);

        $openOrdersPage = $this->orderService->listOrders($user, $tenantId, 200, [
            'outlet_id' => $outletId,
            'status' => 'confirmed',
            'order_type' => 'Dine-in',
        ]);
        $openOrders = collect($openOrdersPage->items())
            ->filter(static fn ($order): bool => in_array((string) $order->payment_status, ['unpaid', 'partial'], true))
            ->values();

        return [
            'generatedAt' => now()->toIso8601String(),
            'schemaVersion' => 2,
            'outletId' => $outletId,
            'merchant' => $bootstrap['merchant'],
            'system' => $bootstrap['system'],
            'outletTaxRules' => $bootstrap['outletTaxRules'],
            'menuItems' => $bootstrap['menuItems'],
            'tables' => RestaurantTableResource::collection($tables)->resolve(),
            'checkoutMethods' => $checkoutMethods,
            'receiptSettings' => $receiptSettings,
            'thermalPaperWidth' => '58mm',
            'posSession' => $bootstrap['posSession'] ?? null,
            'defaultCashFloat' => $bootstrap['defaultCashFloat'] ?? null,
            'openOrders' => OrderResource::collection($openOrders)->resolve(),
        ];
    }
}
