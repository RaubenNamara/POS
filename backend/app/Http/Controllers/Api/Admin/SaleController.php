<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Support\Settings;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $v = $request->validate([
            'q' => 'nullable|string|max:60', 'channel' => 'nullable|in:pos,online', 'status' => 'nullable|in:pending,completed,cancelled',
            'payment_status' => 'nullable|in:unpaid,partial,paid,refunded', 'customer_id' => 'nullable|integer', 'user_id' => 'nullable|integer',
            'fulfillment_status' => 'nullable|string|max:20', 'from' => 'nullable|date', 'to' => 'nullable|date', 'per_page' => 'nullable|integer|max:100',
        ]);
        $q = Sale::with(['customer:id,name,phone', 'cashier:id,name'])->orderByDesc('id');
        // Cashiers see only their own sales.
        if (! $request->user()->can('sales.view_all')) {
            $q->where('user_id', $request->user()->id);
        }
        foreach (['channel', 'status', 'payment_status', 'customer_id', 'user_id', 'fulfillment_status'] as $f) {
            if (! empty($v[$f])) {
                $q->where($f, $v[$f]);
            }
        }
        if (! empty($v['q'])) {
            $q->where(fn ($w) => $w->where('number', 'like', '%'.addcslashes($v['q'], '%_\\').'%')
                ->orWhereIn('customer_id', \App\Models\Customer::where('phone', $v['q'])->orWhere('name', 'like', '%'.addcslashes($v['q'], '%_\\').'%')->select('id')));
        }
        if (! empty($v['from'])) {
            $q->where('created_at', '>=', $v['from']);
        }
        if (! empty($v['to'])) {
            $q->where('created_at', '<=', $v['to'].' 23:59:59');
        }

        return $q->paginate($v['per_page'] ?? 25, ['id', 'number', 'channel', 'status', 'fulfillment_status', 'payment_status', 'customer_id', 'user_id',
            'total', 'amount_paid', 'balance_due', 'refunded_total', 'offline', 'created_at', 'completed_at']);
    }

    public function show(Request $request, Sale $sale)
    {
        $this->authorizeView($request, $sale);
        $sale->load(['items.components.product:id,name,sku', 'payments.method:id,name,type', 'payments.user:id,name',
            'returns.items', 'returns.user:id,name', 'customer', 'cashier:id,name', 'location:id,name']);

        return $sale;
    }

    /** Receipt data. Only issued once the server has the sale; offline receipts are marked provisional client-side. */
    public function receipt(Request $request, Sale $sale)
    {
        $this->authorizeView($request, $sale);

        return self::receiptPayload($sale);
    }

    /** A4 invoice / receipt as PDF (server-rendered so it is identical on every device). */
    public function invoicePdf(Request $request, Sale $sale)
    {
        $this->authorizeView($request, $sale);
        $r = self::receiptPayload($sale);
        $dec = $r['shop']['currency_decimals'];
        $html = view('invoice', ['r' => $r, 'fmt' => fn ($v) => number_format((float) $v, $dec)])->render();
        $pdf = new \Dompdf\Dompdf(['defaultFont' => 'DejaVu Sans']);
        $pdf->loadHtml($html);
        $pdf->setPaper('A4');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$sale->number.'.pdf"',
        ]);
    }

    public static function receiptPayload(Sale $sale): array
    {
        $sale->loadMissing(['items', 'payments.method:id,name,type', 'customer:id,name,phone', 'cashier:id,name', 'location:id,name,address']);
        $s = Settings::all();

        return [
            'shop' => [
                'name' => $s['shop_name'], 'address' => $s['shop_address'], 'phone' => $s['shop_phone'], 'email' => $s['shop_email'],
                'logo' => $s['shop_logo'], 'currency' => $s['currency'], 'currency_decimals' => (int) $s['currency_decimals'],
                'tax_label' => $s['tax_label'], 'tax_inclusive' => $s['tax_inclusive'] === '1',
                'footer' => $s['receipt_footer'], 'return_policy' => $s['return_policy'],
            ],
            'id' => $sale->id,
            'number' => $sale->number,
            'channel' => $sale->channel,
            'status' => $sale->status,
            'payment_status' => $sale->payment_status,
            // A receipt only reads "PAID" when the server has the money recorded.
            'is_final_paid' => $sale->status === 'completed' && $sale->payment_status === 'paid',
            'date' => ($sale->completed_at ?? $sale->created_at)->toDateTimeString(),
            'offline_created_at' => $sale->offline_created_at?->toDateTimeString(),
            'cashier' => $sale->cashier?->name,
            'customer' => $sale->customer?->only(['id', 'name', 'phone']),
            'location' => $sale->location?->only(['name', 'address']),
            'items' => $sale->items->map(fn ($i) => [
                'id' => $i->id, 'name' => $i->name, 'sku' => $i->sku, 'quantity' => $i->quantity, 'unit_price' => (string) $i->unit_price,
                'discount' => (string) $i->discount, 'amount' => (string) $i->amount, 'returned_quantity' => $i->returned_quantity,
            ]),
            'subtotal' => (string) $sale->subtotal,
            'discount_total' => (string) $sale->discount_total,
            'tax_total' => (string) $sale->tax_total,
            'delivery_fee' => (string) $sale->delivery_fee,
            'total' => (string) $sale->total,
            'refunded_total' => (string) $sale->refunded_total,
            'payments' => $sale->payments->map(fn ($p) => ['method' => $p->method->name, 'kind' => $p->kind, 'amount' => (string) $p->amount, 'reference' => $p->reference]),
            'amount_paid' => (string) $sale->amount_paid,
            'change_given' => (string) $sale->change_given,
            'balance_due' => (string) $sale->balance_due,
        ];
    }

    public function addPayment(Request $request, Sale $sale, SaleService $sales)
    {
        $d = $request->validate([
            'client_uuid' => 'required|uuid', 'payment_method_id' => 'required|integer|exists:payment_methods,id',
            'amount' => 'required|numeric|min:0.01', 'reference' => 'nullable|string|max:100',
        ]);
        $sales->addPayment($sale, $d, $request->user());

        return self::receiptPayload($sale->fresh());
    }

    public function processReturn(Request $request, Sale $sale, ReturnService $returns)
    {
        $d = $request->validate([
            'client_uuid' => 'required|uuid',
            'reason' => 'required|string|max:255',
            'payment_method_id' => 'nullable|integer|exists:payment_methods,id',
            'items' => 'required|array|min:1|max:200',
            'items.*.sale_item_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.restock' => 'nullable|boolean',
        ]);
        $ret = $returns->process($sale, $d, $request->user());

        return response()->json(['return' => $ret, 'sale' => self::receiptPayload($sale->fresh())], 201);
    }

    public function updateOrderStatus(Request $request, Sale $sale, SaleService $sales)
    {
        $d = $request->validate(['status' => 'required|in:confirmed,processing,ready,out_for_delivery,delivered,cancelled']);

        return $sales->updateFulfillment($sale, $d['status']);
    }

    private function authorizeView(Request $request, Sale $sale): void
    {
        abort_unless($request->user()->can('sales.view_all') || $sale->user_id === $request->user()->id, 403);
    }
}
