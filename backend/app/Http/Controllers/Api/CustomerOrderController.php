<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Sale;
use App\Services\FlutterwaveService;
use App\Services\LocationService;
use App\Services\SaleService;
use Illuminate\Http\Request;

/** Logged-in shopper: place orders, see own order history and status. */
class CustomerOrderController extends Controller
{
    public function quote(Request $request, SaleService $sales)
    {
        $d = $request->validate(['items' => 'required|array|min:1|max:100', 'items.*.product_id' => 'required|integer', 'items.*.quantity' => 'required|integer|min:1|max:1000']);
        $p = $sales->priceCart($d['items'], null, null, onlineOnly: true);

        return [
            'lines' => array_map(fn ($l) => ['product_id' => $l['product']->id, 'name' => $l['product']->name, 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'], 'amount' => $l['amount']], $p['lines']),
            'subtotal' => $p['subtotal'], 'tax_total' => $p['tax_total'], 'total' => $p['total'],
            'delivery_fee' => \App\Support\Settings::get('delivery_fee', '0'),
        ];
    }

    public function store(Request $request, SaleService $sales)
    {
        $d = $request->validate([
            'client_uuid' => 'required|uuid',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1|max:1000',
            'fulfillment' => 'required|in:delivery,pickup',
            'delivery_name' => 'required|string|max:120',
            'delivery_phone' => 'required|string|max:30',
            'delivery_address' => 'required_if:fulfillment,delivery|nullable|string|max:255',
            'delivery_lat' => 'required_if:fulfillment,delivery|nullable|numeric|between:-90,90',
            'delivery_lng' => 'required_if:fulfillment,delivery|nullable|numeric|between:-180,180',
            'payment_option' => 'required|in:cod,online,pickup',
            'notes' => 'nullable|string|max:500',
        ]);
        $result = $sales->placeOnlineOrder($d, $this->customer($request));
        $sale = $result['sale']->fresh('items');
        $checkoutUrl = null;
        if ($sale->payment_option === 'online' && $sale->fulfillment_status === 'awaiting_payment') {
            try {
                $checkoutUrl = app(FlutterwaveService::class)->initiate($sale, $this->returnUrl($request));
            } catch (\App\Exceptions\BusinessRuleException $e) {
                // The order exists with stock held; the customer can retry "Pay now" from the order page.
                return response()->json(['order' => $this->present($sale), 'checkout_url' => null, 'payment_error' => $e->getMessage()], 201);
            }
        }

        return response()->json(['order' => $this->present($sale), 'checkout_url' => $checkoutUrl], $result['replayed'] ? 200 : 201);
    }

    /**
     * A shopper may cancel their own order only before the shop starts on it and before any
     * money is recorded; stock goes straight back on sale. After that, they contact the shop.
     */
    public function cancel(Request $request, string $number, SaleService $sales)
    {
        $sale = Sale::where('customer_id', $this->customer($request)->id)->where('number', $number)->firstOrFail();
        if (! self::canCustomerCancel($sale)) {
            throw new \App\Exceptions\BusinessRuleException('This order can no longer be cancelled online. Please contact the shop.', 'cannot_cancel');
        }
        $sales->updateFulfillment($sale, 'cancelled');

        return $this->present($sale->fresh('items'));
    }

    public static function canCustomerCancel(Sale $s): bool
    {
        return $s->channel === 'online' && $s->status === 'pending'
            && in_array($s->fulfillment_status, ['placed', 'awaiting_payment'], true)
            && \App\Support\Money::isZero((string) $s->amount_paid);
    }

    /** Payment options for a delivery pin (checkout shows only what the server will accept). */
    public function deliveryOptions(Request $request, LocationService $locations)
    {
        $d = $request->validate(['fulfillment' => 'required|in:delivery,pickup', 'lat' => 'required_if:fulfillment,delivery|nullable|numeric|between:-90,90', 'lng' => 'required_if:fulfillment,delivery|nullable|numeric|between:-180,180']);
        $zone = $d['fulfillment'] === 'delivery' ? $locations->classify((float) $d['lat'], (float) $d['lng']) : null;

        return ['zone' => $zone, 'options' => SaleService::paymentOptions($d['fulfillment'] === 'delivery', $zone['zone'] ?? null),
            'cod_districts' => config('services.delivery.cod_districts')];
    }

    /** (Re)start online payment for an order still awaiting it. */
    public function pay(Request $request, string $number, FlutterwaveService $gateway)
    {
        $sale = Sale::where('customer_id', $this->customer($request)->id)->where('number', $number)->firstOrFail();
        if ($sale->payment_option !== 'online' || $sale->fulfillment_status !== 'awaiting_payment') {
            throw new \App\Exceptions\BusinessRuleException('This order is not waiting for online payment', 'invalid_state');
        }

        return ['checkout_url' => $gateway->initiate($sale, $this->returnUrl($request))];
    }

    /** Called by the page Flutterwave redirects back to; confirmation always comes from Flutterwave's API. */
    public function confirmPayment(Request $request, FlutterwaveService $gateway)
    {
        $d = $request->validate(['tx_ref' => 'required|string|max:64', 'transaction_id' => 'nullable|string|max:64']);
        $saleId = \Illuminate\Support\Facades\DB::table('gateway_transactions')->where('tx_ref', $d['tx_ref'])->value('sale_id');
        abort_unless($saleId && Sale::whereKey($saleId)->where('customer_id', $this->customer($request)->id)->exists(), 404);
        $r = $gateway->confirm($d['tx_ref'], $d['transaction_id'] ?? null);

        return ['status' => $r['status'], 'order' => $this->present($r['sale']->fresh('items'))];
    }

    private function returnUrl(Request $request): string
    {
        return rtrim(config('app.url') !== 'http://localhost' ? config('app.url') : $request->getSchemeAndHttpHost(), '/').'/payment/return';
    }

    public function index(Request $request)
    {
        return Sale::where('customer_id', $this->customer($request)->id)->orderByDesc('id')
            ->paginate(15, ['id', 'number', 'channel', 'status', 'fulfillment_status', 'payment_status', 'total', 'balance_due', 'created_at']);
    }

    public function show(Request $request, string $number)
    {
        $sale = Sale::where('customer_id', $this->customer($request)->id)->where('number', $number)->with('items')->firstOrFail();

        return $this->present($sale);
    }

    private function customer(Request $request): Customer
    {
        $u = $request->user();

        // Staff accounts can shop too; give them a customer profile on first order.
        return $u->customer ?? Customer::create(['user_id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone]);
    }

    private function present(Sale $s): array
    {
        return [
            'number' => $s->number, 'status' => $s->status, 'fulfillment_status' => $s->fulfillment_status, 'payment_status' => $s->payment_status,
            'created_at' => $s->created_at->toDateTimeString(), 'delivery_name' => $s->delivery_name, 'delivery_phone' => $s->delivery_phone,
            'delivery_address' => $s->delivery_address, 'subtotal' => (string) $s->subtotal, 'tax_total' => (string) $s->tax_total,
            'delivery_fee' => (string) $s->delivery_fee, 'total' => (string) $s->total, 'amount_paid' => (string) $s->amount_paid,
            'balance_due' => (string) $s->balance_due, 'payment_option' => $s->payment_option,
            'delivery_district' => $s->delivery_district, 'payment_expires_at' => $s->payment_expires_at?->toIso8601String(),
            'can_cancel' => self::canCustomerCancel($s),
            'can_pay_online' => $s->payment_option === 'online' && $s->fulfillment_status === 'awaiting_payment' && $s->status === 'pending',
            'items' => $s->items->map(fn ($i) => ['name' => $i->name, 'quantity' => $i->quantity, 'unit_price' => (string) $i->unit_price, 'amount' => (string) $i->amount]),
            'steps' => SaleService::FULFILLMENT_FLOW,
        ];
    }
}
