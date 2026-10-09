<?php

namespace App\Providers;

use App\Models\CashSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Policies\CashSessionPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\ItemPolicy;
use App\Policies\OrderPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\StockBalancePolicy;
use App\Policies\StockLocationPolicy;
use App\Policies\StockMovementPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Item::class, ItemPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(CashSession::class, CashSessionPolicy::class);
        Gate::policy(StockLocation::class, StockLocationPolicy::class);
        Gate::policy(StockBalance::class, StockBalancePolicy::class);
        Gate::policy(StockMovement::class, StockMovementPolicy::class);
    }
}
