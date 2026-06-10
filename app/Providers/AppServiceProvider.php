<?php

namespace App\Providers;

use App\Console\Commands\ExpirePendingPaymentsCommand;
use App\Console\Commands\FailedJobMonitorCommand;
use App\Console\Commands\FailedJobSnapshotCommand;
use App\Console\Commands\ReconcileStalePaymentsCommand;
use App\Console\Commands\SyncStaffNotificationsCommand;
use App\Modules\Inventory\Events\InventoryCriticalAlertRaised;
use App\Modules\Notifications\Listeners\InventoryCriticalAlertNotificationListener;
use App\Modules\System\Listeners\QueueJobFailedNotificationListener;
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
use App\Modules\Payments\Services\PaymentConfigurationHealthService;
use App\Events\Hardware\CommandAcknowledged;
use App\Modules\LoyaltyEngine\Listeners\RedeemVoucherOnOrderPaidListener;
use App\Modules\Orders\Events\OrderLifecycleChanged;
use App\Modules\Print\Listeners\CompletePrintJobOnHardwareCommandAck;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
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
            SyncStaffNotificationsCommand::class,
            FailedJobSnapshotCommand::class,
            FailedJobMonitorCommand::class,
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(
            OrderLifecycleChanged::class,
            RedeemVoucherOnOrderPaidListener::class,
        );

        Event::listen(
            CommandAcknowledged::class,
            CompletePrintJobOnHardwareCommandAck::class,
        );

        Event::listen(
            InventoryCriticalAlertRaised::class,
            InventoryCriticalAlertNotificationListener::class,
        );

        Event::listen(
            JobFailed::class,
            QueueJobFailedNotificationListener::class,
        );

        $this->app->make(PaymentConfigurationHealthService::class)->assertProductionBootReady();
    }
}
