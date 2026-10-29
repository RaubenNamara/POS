<?php

use App\Http\Controllers\Api\Admin\CatalogSetupController as Setup;
use App\Http\Controllers\Api\Admin\CustomerController;
use App\Http\Controllers\Api\Admin\ExpenseController;
use App\Http\Controllers\Api\Admin\InventoryController;
use App\Http\Controllers\Api\Admin\PosController;
use App\Http\Controllers\Api\Admin\ProductController;
use App\Http\Controllers\Api\Admin\PurchasingController as Purchasing;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Admin\SaleController;
use App\Http\Controllers\Api\Admin\ShiftController;
use App\Http\Controllers\Api\Admin\SystemController as System;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CustomerOrderController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------- public
Route::prefix('shop')->group(function () {
    Route::get('home', [CatalogController::class, 'home']);
    Route::get('categories', [CatalogController::class, 'categories']);
    Route::get('products', [CatalogController::class, 'products']);
    Route::get('products/{slug}', [CatalogController::class, 'product']);
    Route::post('quote', [CustomerOrderController::class, 'quote'])->middleware('throttle:60,1');
    // Advert click counter (fire-and-forget from the browser).
    Route::post('ads/{ad}/click', fn (\App\Models\Ad $ad) => tap(response()->noContent(), fn () => $ad->increment('clicks')))->middleware('throttle:30,1');
});

Route::post('auth/identify', [AuthController::class, 'identify'])->middleware('throttle:identify');
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('auth/google', [AuthController::class, 'google'])->middleware('throttle:login');
Route::post('auth/facebook', [AuthController::class, 'facebook'])->middleware('throttle:login');

// Flutterwave calls this server-to-server; authenticated by the shared "verif-hash" secret.
Route::post('webhooks/flutterwave', function (\Illuminate\Http\Request $request, \App\Services\FlutterwaveService $gateway) {
    $status = $gateway->handleWebhook($request->header('verif-hash'), $request->all());

    return response()->json(['status' => $status], $status === 'rejected' ? 401 : 200);
})->middleware('throttle:120,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // Shopper
    Route::get('my/orders', [CustomerOrderController::class, 'index']);
    Route::get('my/orders/{number}', [CustomerOrderController::class, 'show']);
    Route::post('my/orders', [CustomerOrderController::class, 'store'])->middleware('throttle:checkout');
    Route::post('my/orders/{number}/pay', [CustomerOrderController::class, 'pay'])->middleware('throttle:checkout');
    Route::post('my/orders/{number}/cancel', [CustomerOrderController::class, 'cancel'])->middleware('throttle:10,1');
    Route::post('my/payments/confirm', [CustomerOrderController::class, 'confirmPayment'])->middleware('throttle:60,1');
    Route::post('my/delivery-options', [CustomerOrderController::class, 'deliveryOptions'])->middleware('throttle:60,1');
    Route::put('my/location', [\App\Http\Controllers\Api\CustomerAccountController::class, 'saveLocation'])->middleware('throttle:30,1');

    // ------------------------------------------------------------ staff
    Route::middleware('staff')->prefix('admin')->group(function () {
        Route::get('reports/pulse', [ReportController::class, 'pulse']);
        Route::get('lookups', fn () => [
            'categories' => \App\Models\Category::orderBy('name')->get(['id', 'name', 'slug', 'parent_id']),
            'brands' => \App\Models\Brand::orderBy('name')->get(['id', 'name']),
            'suppliers' => \App\Models\Supplier::whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
            'locations' => \App\Models\Location::where('is_active', true)->orderByDesc('is_default')->get(['id', 'name', 'code', 'is_default']),
            'payment_methods' => \App\Models\PaymentMethod::where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'type', 'requires_reference']),
            'expense_categories' => \App\Models\ExpenseCategory::orderBy('name')->get(['id', 'name']),
        ]);

        Route::middleware('can:products.view')->group(function () {
            Route::get('products', [ProductController::class, 'index']);
            Route::get('products/lookup', [ProductController::class, 'lookup']);
            Route::get('products/{product}', [ProductController::class, 'show']);
        });
        Route::middleware('can:products.manage')->group(function () {
            Route::post('products', [ProductController::class, 'store']);
            Route::put('products/{product}', [ProductController::class, 'update']);
            Route::post('products/{product}/archive', [ProductController::class, 'archive']);
            Route::post('products/{product}/restore', [ProductController::class, 'restore']);
            Route::delete('products/{product}', [ProductController::class, 'destroy']);
            Route::post('products/{product}/images', [ProductController::class, 'uploadImage']);
            Route::delete('products/{product}/images/{image}', [ProductController::class, 'deleteImage']);
            Route::post('products/{product}/images/{image}/primary', [ProductController::class, 'setPrimaryImage']);
            Route::get('categories', [Setup::class, 'categories']);
            Route::post('categories', [Setup::class, 'saveCategory']);
            Route::put('categories/{category}', [Setup::class, 'saveCategory']);
            Route::delete('categories/{category}', [Setup::class, 'deleteCategory']);
            Route::get('brands', [Setup::class, 'brands']);
            Route::post('brands', [Setup::class, 'saveBrand']);
            Route::put('brands/{brand}', [Setup::class, 'saveBrand']);
        });

        Route::middleware('can:inventory.view')->group(function () {
            Route::get('inventory/movements', [InventoryController::class, 'movements']);
            Route::get('inventory/low-stock', [InventoryController::class, 'lowStock']);
            Route::get('notifications', [System::class, 'notifications']);
            Route::post('notifications/read', [System::class, 'markNotificationsRead']);
        });
        Route::post('inventory/adjust', [InventoryController::class, 'adjust'])->middleware('can:inventory.adjust');

        Route::middleware('can:suppliers.manage')->group(function () {
            Route::get('suppliers/{supplier}', [Purchasing::class, 'supplier']);
            Route::post('suppliers', [Purchasing::class, 'saveSupplier']);
            Route::put('suppliers/{supplier}', [Purchasing::class, 'saveSupplier']);
            Route::post('suppliers/{supplier}/archive', [Purchasing::class, 'archiveSupplier']);
            Route::post('supplier-payments', [Purchasing::class, 'pay']);
        });
        Route::middleware('can:purchases.manage')->group(function () {
            Route::post('purchase-orders', [Purchasing::class, 'saveOrder']);
            Route::put('purchase-orders/{order}', [Purchasing::class, 'saveOrder']);
            Route::post('purchase-orders/{order}/cancel', [Purchasing::class, 'cancelOrder']);
        });
        Route::middleware('can:purchases.receive')->group(function () {
            Route::get('suppliers', [Purchasing::class, 'suppliers']);
            Route::get('purchase-orders', [Purchasing::class, 'orders']);
            Route::get('purchase-orders/{order}', [Purchasing::class, 'order']);
            Route::get('goods-receipts', [Purchasing::class, 'receipts']);
            Route::get('goods-receipts/{receipt}', [Purchasing::class, 'receipt']);
            Route::post('goods-receipts', [Purchasing::class, 'receive']);
        });

        Route::middleware('can:pos.sell')->group(function () {
            Route::get('pos/bootstrap', [PosController::class, 'bootstrap']);
            Route::get('pos/stock', [PosController::class, 'stock']);
            Route::get('pos/customers', [PosController::class, 'customers']);
            Route::post('pos/checkout', [PosController::class, 'checkout'])->middleware('throttle:checkout');
            Route::post('pos/sync', [PosController::class, 'sync']);
            Route::get('shifts', [ShiftController::class, 'index']);
            Route::get('shifts/current', [ShiftController::class, 'current']);
            Route::post('shifts', [ShiftController::class, 'open']);
            Route::get('shifts/{shift}', [ShiftController::class, 'show']);
            Route::post('shifts/{shift}/close', [ShiftController::class, 'close']);
            Route::get('sales', [SaleController::class, 'index']);
            Route::get('sales/{sale}', [SaleController::class, 'show']);
            Route::get('sales/{sale}/receipt', [SaleController::class, 'receipt']);
            Route::get('sales/{sale}/invoice.pdf', [SaleController::class, 'invoicePdf']);
        });
        Route::post('sales/{sale}/payments', [SaleController::class, 'addPayment'])->middleware('can:customers.collect');
        Route::post('sales/{sale}/returns', [SaleController::class, 'processReturn'])->middleware('can:sales.refund');
        Route::post('orders/{sale}/status', [SaleController::class, 'updateOrderStatus'])->middleware('can:orders.manage');

        Route::middleware('can:customers.view')->group(function () {
            Route::get('customers', [CustomerController::class, 'index']);
            Route::get('customers/{customer}', [CustomerController::class, 'show']);
        });
        Route::middleware('can:customers.manage')->group(function () {
            Route::post('customers', [CustomerController::class, 'store']);
            Route::put('customers/{customer}', [CustomerController::class, 'update']);
            Route::post('customers/{customer}/archive', [CustomerController::class, 'archive']);
        });
        Route::post('customers/{customer}/collect', [CustomerController::class, 'collect'])->middleware('can:customers.collect');

        Route::middleware('can:expenses.manage')->group(function () {
            Route::get('expenses', [ExpenseController::class, 'index']);
            Route::post('expenses', [ExpenseController::class, 'store']);
            Route::post('expenses/{expense}/void', [ExpenseController::class, 'void']);
            Route::get('expense-categories', [ExpenseController::class, 'categories']);
            Route::post('expense-categories', [ExpenseController::class, 'saveCategory']);
        });

        Route::get('dashboard', [ReportController::class, 'dashboard'])->middleware('can:reports.operational');
        Route::get('reports/{report}', [ReportController::class, 'show']);
        Route::post('exports', [ReportController::class, 'export']);
        Route::get('exports/{id}', [ReportController::class, 'exportStatus'])->whereNumber('id');
        Route::get('exports/{id}/download', [ReportController::class, 'download'])->whereNumber('id');

        Route::middleware('can:offline.resolve')->group(function () {
            Route::get('offline-conflicts', [System::class, 'conflicts']);
            Route::post('offline-conflicts/{conflict}/retry', [System::class, 'retryConflict']);
            Route::post('offline-conflicts/{conflict}/void', [System::class, 'voidConflict']);
        });

        Route::middleware('can:marketing.manage')->group(function () {
            Route::get('ads', [\App\Http\Controllers\Api\Admin\AdController::class, 'index']);
            Route::post('ads', [\App\Http\Controllers\Api\Admin\AdController::class, 'store']);
            Route::post('ads/{ad}', [\App\Http\Controllers\Api\Admin\AdController::class, 'update']);
            Route::delete('ads/{ad}', [\App\Http\Controllers\Api\Admin\AdController::class, 'destroy']);
        });

        Route::middleware('can:users.manage')->group(function () {
            Route::get('users', [System::class, 'users']);
            Route::post('users', [System::class, 'saveUser']);
            Route::put('users/{user}', [System::class, 'saveUser']);
            Route::get('audit', [System::class, 'audit']);
        });
        Route::middleware('can:settings.manage')->group(function () {
            Route::get('settings', [System::class, 'settings']);
            Route::put('settings', [System::class, 'saveSettings']);
            Route::post('settings/logo', [System::class, 'uploadLogo']);
            Route::post('settings/test-flutterwave', [System::class, 'testFlutterwave'])->middleware('throttle:10,1');
            Route::get('locations', [Setup::class, 'locations']);
            Route::post('locations', [Setup::class, 'saveLocation']);
            Route::put('locations/{location}', [Setup::class, 'saveLocation']);
            Route::get('payment-methods', [Setup::class, 'paymentMethods']);
            Route::post('payment-methods', [Setup::class, 'savePaymentMethod']);
            Route::put('payment-methods/{method}', [Setup::class, 'savePaymentMethod']);
        });
    });
});
