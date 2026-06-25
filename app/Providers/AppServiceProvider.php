<?php

namespace App\Providers;

use App\Console\Commands\CustomerDemoSeedCommand;
use App\Console\Commands\DemoSeedCommand;
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
use App\Modules\Production\Repositories\EloquentProductionStationRepository;
use App\Modules\Production\Repositories\ProductionStationRepositoryInterface;
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
use Laravel\Passport\Passport;

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
        $this->app->bind(ProductionStationRepositoryInterface::class, EloquentProductionStationRepository::class);
        $this->app->bind(KitchenTicketRepositoryInterface::class, EloquentKitchenTicketRepository::class);
        $this->app->bind(PaymentTransactionRepositoryInterface::class, EloquentPaymentTransactionRepository::class);
        $this->commands([
            DemoSeedCommand::class,
            CustomerDemoSeedCommand::class,
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
        $tokenLifetimeMinutes = max(1, (int) config('passport.personal_access_token_expire_minutes', 1440));
        Passport::personalAccessTokensExpireIn(now()->addMinutes($tokenLifetimeMinutes));

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
