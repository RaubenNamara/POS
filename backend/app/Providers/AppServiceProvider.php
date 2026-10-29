<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Services\InventoryService::class);
    }

    public function boot(): void
    {
        // Catch N+1 queries during development instead of discovering them in production.
        Model::preventLazyLoading(! $this->app->isProduction());

        Relation::enforceMorphMap([
            'sale' => \App\Models\Sale::class,
            'sale_return' => \App\Models\SaleReturn::class,
            'goods_receipt' => \App\Models\GoodsReceipt::class,
            'product' => \App\Models\Product::class,
            'supplier_payment' => \App\Models\SupplierPayment::class,
            'payment' => \App\Models\Payment::class,
            'shift' => \App\Models\Shift::class,
            'purchase_order' => \App\Models\PurchaseOrder::class,
            'offline_conflict' => \App\Models\OfflineConflict::class,
            'expense' => \App\Models\Expense::class,
            'customer' => \App\Models\Customer::class,
            'supplier' => \App\Models\Supplier::class,
            'category' => \App\Models\Category::class,
            'user' => \App\Models\User::class,
            'setting' => \App\Models\Setting::class,
            'payment_method' => \App\Models\PaymentMethod::class,
            'location' => \App\Models\Location::class,
            'brand' => \App\Models\Brand::class,
            'expense_category' => \App\Models\ExpenseCategory::class,
            'product_image' => \App\Models\ProductImage::class,
            'sale_item' => \App\Models\SaleItem::class,
            'stock_level' => \App\Models\StockLevel::class,
            'stock_movement' => \App\Models\StockMovement::class,
            'goods_receipt_item' => \App\Models\GoodsReceiptItem::class,
            'purchase_order_item' => \App\Models\PurchaseOrderItem::class,
            'bundle_item' => \App\Models\BundleItem::class,
            'feed_notification' => \App\Models\FeedNotification::class,
            'ad' => \App\Models\Ad::class,
            'sale_item_component' => \App\Models\SaleItemComponent::class,
            'sale_return_item' => \App\Models\SaleReturnItem::class,
        ]);

        \App\Support\Settings::applyIntegrations();

        // Any catalog edit invalidates every cached storefront response (keys embed this version).
        foreach ([\App\Models\Product::class, \App\Models\Category::class, \App\Models\Brand::class, \App\Models\ProductImage::class, \App\Models\BundleItem::class] as $m) {
            $bump = fn () => \Illuminate\Support\Facades\DB::afterCommit(fn () => \Illuminate\Support\Facades\Cache::increment('catalog_version'));
            $m::saved($bump);
            $m::deleted($bump);
        }

        foreach (Permissions::ABILITIES as $ability) {
            Gate::define($ability, fn (User $u) => $u->is_active && Permissions::allows($u->role, $ability));
        }

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(240)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(10)->by(strtolower((string) $r->input('login')).'|'.$r->ip()),
            Limit::perMinute(30)->by($r->ip()),
        ]);
        // "Does this email/phone have an account?" Kept low per address so it cannot be used to list customers.
        RateLimiter::for('identify', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
        RateLimiter::for('checkout', fn (Request $r) => Limit::perMinute(30)->by($r->user()?->id ?: $r->ip()));
    }
}
