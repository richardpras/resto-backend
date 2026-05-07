<?php

namespace App\Providers;

use App\Console\Commands\ExpirePendingPaymentsCommand;
use App\Console\Commands\ReconcileStalePaymentsCommand;
use App\Modules\Inventory\Repositories\EloquentIngredientRepository;
use App\Modules\Inventory\Repositories\EloquentStockMovementRepository;
use App\Modules\Inventory\Repositories\IngredientRepositoryInterface;
use App\Modules\Inventory\Repositories\StockMovementRepositoryInterface;
use App\Modules\Kitchen\Repositories\EloquentKitchenTicketRepository;
use App\Modules\Kitchen\Repositories\KitchenTicketRepositoryInterface;
use App\Modules\Menu\Repositories\EloquentMenuRepository;
use App\Modules\Menu\Repositories\MenuRepositoryInterface;
use App\Modules\Orders\Repositories\EloquentOrderRepository;
use App\Modules\Orders\Repositories\EloquentQrOrderRequestRepository;
use App\Modules\Orders\Repositories\OrderRepositoryInterface;
use App\Modules\Orders\Repositories\QrOrderRequestRepositoryInterface;
use App\Modules\Payments\Repositories\EloquentPaymentTransactionRepository;
use App\Modules\Payments\Repositories\PaymentTransactionRepositoryInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OrderRepositoryInterface::class, EloquentOrderRepository::class);
        $this->app->bind(QrOrderRequestRepositoryInterface::class, EloquentQrOrderRequestRepository::class);
        $this->app->bind(IngredientRepositoryInterface::class, EloquentIngredientRepository::class);
        $this->app->bind(StockMovementRepositoryInterface::class, EloquentStockMovementRepository::class);
        $this->app->bind(MenuRepositoryInterface::class, EloquentMenuRepository::class);
        $this->app->bind(KitchenTicketRepositoryInterface::class, EloquentKitchenTicketRepository::class);
        $this->app->bind(PaymentTransactionRepositoryInterface::class, EloquentPaymentTransactionRepository::class);
        $this->commands([
            ReconcileStalePaymentsCommand::class,
            ExpirePendingPaymentsCommand::class,
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
