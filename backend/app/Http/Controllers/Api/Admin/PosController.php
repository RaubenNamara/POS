<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\InventoryService;
use App\Services\OfflineSyncService;
use App\Services\SaleService;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    public function __construct(private SaleService $sales) {}

    /**
     * Everything the till needs to work (and keep working offline): a compact product list
     * (no descriptions/images), payment methods, settings and the cashier's open shift.
     * Clients send `since` (last sync time) to receive only changed products.
     */
    public function bootstrap(Request $request)
    {
        $since = $request->query('since');
        // Plain query builder (no Eloquent models): the full catalogue for a till can be
        // thousands of rows, and hydrating models for them was the measured cost here.
        $q = DB::table('products')->where('is_active', true)->whereNull('archived_at')
            ->select(['id', 'type', 'name', 'sku', 'barcode', 'selling_price', 'total_stock', 'image_path', 'category_id']);
        if ($since) {
            // >= (not >): a change in the same second as the last sync must not be missed.
            $q->where('updated_at', '>=', $since);
        }
        $rows = $q->orderBy('id')->get();
        $bundleIds = $rows->where('type', 'bundle')->pluck('id');
        $components = $bundleIds->isEmpty() ? collect() : DB::table('bundle_items')->whereIn('bundle_id', $bundleIds)->get(['bundle_id', 'product_id', 'quantity'])->groupBy('bundle_id');
        $products = $rows->map(fn ($p) => [
            'id' => $p->id, 't' => $p->type === 'bundle' ? 'b' : 's', 'n' => $p->name, 'sku' => $p->sku, 'bc' => $p->barcode,
            'p' => $p->selling_price, 'q' => (int) $p->total_stock, 'img' => $p->image_path, 'c' => $p->category_id,
            'bi' => $p->type === 'bundle' ? ($components[$p->id] ?? collect())->map(fn ($b) => [$b->product_id, $b->quantity])->values()->all() : null,
        ]);
        $user = $request->user();
        $location = $this->sales->resolveLocation($request->integer('location_id') ?: null);
        // Archived/deactivated since last sync, so the till can drop them.
        $removed = $since ? Product::where('updated_at', '>=', $since)->where(fn ($w) => $w->where('is_active', false)->orWhereNotNull('archived_at'))->pluck('id') : [];

        return response()->json([
            'server_time' => now()->toDateTimeString(),
            'full' => ! $since,
            'products' => $products,
            'removed' => $removed,
            'payment_methods' => PaymentMethod::where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'code', 'type', 'requires_reference']),
            'settings' => array_intersect_key(Settings::all(), array_flip(['shop_name', 'shop_address', 'shop_phone', 'shop_email', 'shop_logo', 'currency',
                'currency_decimals', 'tax_rate', 'tax_inclusive', 'tax_label', 'receipt_footer', 'return_policy', 'max_cashier_discount_pct',
                'require_shift', 'offline_max_sale_total', 'offline_max_queue'])),
            'location' => $location->only(['id', 'name', 'code']),
            'shift' => $this->sales->openShift($user, $location->id),
            'can_discount_freely' => $user->can('sales.refund'),
        ]);
    }

    /** Live stock for specific products, the till re-checks before checkout. */
    public function stock(Request $request)
    {
        $ids = array_slice(array_filter(array_map('intval', explode(',', (string) $request->query('ids')))), 0, 200);
        $rows = Product::whereIn('id', $ids)->with('bundleItems.product:id,total_stock')->get(['id', 'type', 'total_stock']);

        return $rows->mapWithKeys(fn ($p) => [$p->id => $p->type === 'bundle' ? InventoryService::bundleAvailability($p) : (int) $p->total_stock]);
    }

    public function customers(Request $request)
    {
        $term = trim((string) $request->query('q'));
        $like = '%'.addcslashes($term, '%_\\').'%';

        return Customer::whereNull('archived_at')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like)))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'phone', 'credit_limit']);
    }

    public function checkout(Request $request)
    {
        $data = $this->validateSale($request);
        $result = $this->sales->checkout($data, $request->user());

        return response()->json(['sale' => SaleController::receiptPayload($result['sale']), 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }

    /** Upload of sales queued while offline. Each is reported individually; order preserved. */
    public function sync(Request $request, OfflineSyncService $sync)
    {
        $request->validate(['sales' => 'required|array|min:1|max:50']);
        $results = [];
        foreach ($request->input('sales') as $i => $raw) {
            $sub = new Request($raw);
            try {
                $data = $this->validateSale($sub, offline: true);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $results[] = ['client_uuid' => $raw['client_uuid'] ?? "index-{$i}", 'status' => 'rejected', 'message' => collect($e->errors())->flatten()->first()];

                continue;
            }
            $results[] = $sync->syncOne($data, $request->user());
        }

        return response()->json(['results' => $results]);
    }

    private function validateSale(Request $request, bool $offline = false): array
    {
        return $request->validate([
            'client_uuid' => 'required|uuid',
            'location_id' => 'nullable|integer|exists:locations,id',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'shift_id' => 'nullable|integer',
            'items' => 'required|array|min:1|max:200',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1|max:100000',
            'items.*.discount' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'payments' => 'nullable|array|max:10',
            'payments.*.payment_method_id' => 'required|integer',
            'payments.*.amount' => 'required|numeric|min:0|max:9999999999999',
            'payments.*.reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            'offline_created_at' => $offline ? 'required|date|before_or_equal:now' : 'prohibited',
        ]);
    }
}
