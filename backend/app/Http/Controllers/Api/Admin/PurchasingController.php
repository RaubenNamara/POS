<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\Audit;
use App\Services\PurchaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Suppliers, purchase orders, goods received and supplier payments. */
class PurchasingController extends Controller
{
    public function __construct(private PurchaseService $purchases) {}

    public function suppliers(Request $request)
    {
        $receipts = DB::table('goods_receipts')->selectRaw('supplier_id, SUM(total) AS t')->groupBy('supplier_id');
        $paid = DB::table('supplier_payments')->selectRaw('supplier_id, SUM(amount) AS t')->groupBy('supplier_id');
        $q = Supplier::query()->whereNull('archived_at')
            ->leftJoinSub($receipts, 'r', 'r.supplier_id', '=', 'suppliers.id')
            ->leftJoinSub($paid, 'p', 'p.supplier_id', '=', 'suppliers.id')
            ->select('suppliers.*', DB::raw('(suppliers.opening_balance + COALESCE(r.t,0) - COALESCE(p.t,0)) AS balance'));
        if ($term = $request->query('q')) {
            $q->where('name', 'like', '%'.addcslashes($term, '%_\\').'%');
        }
        if ($request->boolean('owing')) {
            $q->havingRaw('balance > 0');
        }

        return $q->orderBy('name')->paginate($request->integer('per_page', 25));
    }

    public function supplier(Supplier $supplier)
    {
        return [
            'supplier' => $supplier,
            'balance' => PurchaseService::supplierBalance($supplier->id),
            'receipts' => GoodsReceipt::where('supplier_id', $supplier->id)->orderByDesc('id')->limit(50)->get(),
            'payments' => SupplierPayment::where('supplier_id', $supplier->id)->with('method:id,name')->orderByDesc('id')->limit(50)->get(),
            'orders' => PurchaseOrder::where('supplier_id', $supplier->id)->orderByDesc('id')->limit(20)->get(),
        ];
    }

    public function saveSupplier(Request $request, ?Supplier $supplier = null)
    {
        $d = $request->validate([
            'name' => 'required|string|max:150', 'contact_person' => 'nullable|string|max:120', 'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255', 'address' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:2000',
            'opening_balance' => 'nullable|numeric|min:0',
        ]);
        if ($supplier && isset($d['opening_balance']) && (string) $d['opening_balance'] !== (string) $supplier->opening_balance
            && ($supplier->goodsReceipts()->exists() || $supplier->payments()->exists())) {
            throw new BusinessRuleException('Opening balance is locked once transactions exist', 'locked');
        }
        $supplier ??= new Supplier;
        $supplier->fill($d)->save();
        Audit::log('supplier.saved', $supplier, null, $d);

        return response()->json($supplier, $supplier->wasRecentlyCreated ? 201 : 200);
    }

    public function archiveSupplier(Supplier $supplier)
    {
        $supplier->update(['archived_at' => now()]);

        return response()->noContent();
    }

    public function orders(Request $request)
    {
        return PurchaseOrder::with('supplier:id,name')->withCount('items')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->integer('supplier_id'), fn ($q, $s) => $q->where('supplier_id', $s))
            ->orderByDesc('id')->paginate(25);
    }

    public function order(PurchaseOrder $order)
    {
        return $order->load(['supplier:id,name,phone', 'items.product:id,name,sku,barcode', 'receipts:id,purchase_order_id,number,total,received_date', 'location:id,name']);
    }

    public function saveOrder(Request $request, ?PurchaseOrder $order = null)
    {
        $d = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id', 'location_id' => 'nullable|exists:locations,id',
            'status' => 'nullable|in:draft,ordered', 'order_date' => 'nullable|date', 'expected_date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1|max:500',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
        ]);
        $po = $this->purchases->savePurchaseOrder($d, $request->user(), $order);

        return response()->json($po, $order ? 200 : 201);
    }

    public function cancelOrder(PurchaseOrder $order)
    {
        if (in_array($order->status, ['received', 'cancelled'], true)) {
            throw new BusinessRuleException('This order can no longer be cancelled', 'invalid_state');
        }
        $order->update(['status' => 'cancelled']);
        Audit::log('purchase_order.cancelled', $order);

        return $order;
    }

    public function receipts(Request $request)
    {
        return GoodsReceipt::with(['supplier:id,name', 'user:id,name'])->withSum('payments', 'amount')
            ->when($request->integer('supplier_id'), fn ($q, $s) => $q->where('supplier_id', $s))
            ->orderByDesc('id')->paginate(25);
    }

    public function receipt(GoodsReceipt $receipt)
    {
        return $receipt->load(['supplier:id,name', 'items.product:id,name,sku', 'payments.method:id,name', 'purchaseOrder:id,number', 'user:id,name']);
    }

    public function receive(Request $request)
    {
        $d = $request->validate([
            'client_uuid' => 'required|uuid',
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
            'supplier_id' => 'nullable|required_without:purchase_order_id|exists:suppliers,id',
            'location_id' => 'nullable|exists:locations,id',
            'supplier_invoice_no' => 'nullable|string|max:60',
            'received_date' => 'nullable|date|before_or_equal:today',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1|max:500',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.purchase_order_item_id' => 'nullable|integer',
            'payment' => 'nullable|array',
            'payment.payment_method_id' => 'required_with:payment.amount|integer|exists:payment_methods,id',
            'payment.amount' => 'nullable|numeric|min:0',
            'payment.reference' => 'nullable|string|max:100',
        ]);

        return response()->json($this->purchases->receive($d, $request->user()), 201);
    }

    public function pay(Request $request)
    {
        $d = $request->validate([
            'client_uuid' => 'required|uuid', 'supplier_id' => 'required|exists:suppliers,id',
            'goods_receipt_id' => 'nullable|exists:goods_receipts,id', 'payment_method_id' => 'required|exists:payment_methods,id',
            'amount' => 'required|numeric|min:0.01', 'reference' => 'nullable|string|max:100', 'paid_date' => 'nullable|date|before_or_equal:today',
        ]);

        return response()->json($this->purchases->paySupplier($d, $request->user()), 201);
    }
}
