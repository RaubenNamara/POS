<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use App\Support\Money;
use App\Support\Permissions;
use App\Support\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales: POS checkout, online orders, payments and debt collection.
 *
 * The client sends only *what* is being bought (product ids, quantities, requested
 * discounts) and *what* was tendered. Prices, costs, tax, totals, change and balances
 * are always recomputed here from the database.
 */
class SaleService
{
    public function __construct(private InventoryService $inventory) {}

    /**
     * @param  array{client_uuid:string, location_id?:int|null, customer_id?:int|null,
     *     items: list<array{product_id:int, quantity:int, discount?:string|null}>,
     *     discount?:string|null, payments?: list<array{payment_method_id:int, amount:string, reference?:string|null}>,
     *     notes?:string|null, offline_created_at?:string|null}  $data
     * @return array{sale: Sale, replayed: bool}
     */
    public function checkout(array $data, User $user, bool $offline = false): array
    {
        if ($existing = Sale::where('client_uuid', $data['client_uuid'])->first()) {
            return ['sale' => $existing, 'replayed' => true];
        }

        try {
            $sale = DB::transaction(function () use ($data, $user, $offline) {
                $location = $this->resolveLocation($data['location_id'] ?? null);
                // An offline sale already happened at the till: attach it to the shift it was rung up
                // in (even if since closed) rather than refusing it for lack of an open shift.
                $shift = $offline
                    ? (Shift::where('user_id', $user->id)->find($data['shift_id'] ?? 0) ?? $this->openShift($user, $location->id))
                    : $this->resolveShift($user, $location);
                $customer = ! empty($data['customer_id']) ? Customer::findOrFail($data['customer_id']) : null;

                $priced = $this->priceCart($data['items'], $data['discount'] ?? null, $user);

                // Reserve the idempotency key first: a concurrent retry with the same uuid
                // blocks on the unique index here and then fails cleanly instead of double-selling.
                $sale = Sale::create([
                    'client_uuid' => $data['client_uuid'],
                    'number' => 'TMP-'.$data['client_uuid'],
                    'channel' => 'pos',
                    'status' => 'completed',
                    'payment_status' => 'unpaid',
                    'location_id' => $location->id,
                    'user_id' => $user->id,
                    'customer_id' => $customer?->id,
                    'shift_id' => $shift?->id,
                    'notes' => $data['notes'] ?? null,
                    'offline' => ! empty($data['offline_created_at']),
                    'offline_created_at' => $data['offline_created_at'] ?? null,
                    'completed_at' => now(),
                ]);
                $sale->number = 'S'.str_pad((string) $sale->id, 7, '0', STR_PAD_LEFT);

                $this->writeItemsAndDeductStock($sale, $priced, $location->id);

                // Payments: validate tendered amounts against the server total.
                [$applied, $change] = $this->applyTenders($sale, $data['payments'] ?? [], $priced['total'], $user, $shift, $offline);
                $balance = Money::sub($priced['total'], $applied);
                if (Money::cmp($balance, '0') > 0) {
                    $this->assertCreditAllowed($customer, $balance);
                }

                $sale->fill([
                    'subtotal' => $priced['subtotal'],
                    'discount_total' => $priced['discount_total'],
                    'tax_total' => $priced['tax_total'],
                    'total' => $priced['total'],
                    'amount_paid' => $applied,
                    'balance_due' => $balance,
                    'change_given' => $change,
                    'payment_status' => $this->paymentStatus($applied, $priced['total']),
                ])->save();

                Audit::log('sale.completed', $sale, null, ['number' => $sale->number, 'total' => $sale->total, 'paid' => $applied]);

                return $sale;
            }, 3);
        } catch (QueryException $e) {
            if ($this->isDuplicateUuid($e) && ($existing = Sale::where('client_uuid', $data['client_uuid'])->first())) {
                return ['sale' => $existing, 'replayed' => true];
            }
            throw $e;
        }

        return ['sale' => $sale->fresh(['items', 'payments.method', 'customer', 'cashier', 'location']), 'replayed' => false];
    }

    /**
     * Online order: stock is committed when the order is placed (so it cannot be oversold),
     * revenue is recognised only when it is delivered. Nothing is "paid" until staff confirm money.
     */
    public function placeOnlineOrder(array $data, Customer $customer): array
    {
        if ($existing = Sale::where('client_uuid', $data['client_uuid'])->first()) {
            if ($existing->customer_id !== $customer->id) {
                throw new BusinessRuleException('Duplicate order reference', 'duplicate');
            }

            return ['sale' => $existing, 'replayed' => true];
        }
        if (Settings::get('online_orders_enabled') !== '1') {
            throw new BusinessRuleException('Online ordering is currently closed', 'orders_closed');
        }
        $isDelivery = ($data['fulfillment'] ?? 'delivery') === 'delivery';
        $zone = null;
        if ($isDelivery) {
            if (! isset($data['delivery_lat'], $data['delivery_lng'])) {
                throw new BusinessRuleException('Set your delivery location on the map', 'location_required');
            }
            $zone = app(LocationService::class)->classify((float) $data['delivery_lat'], (float) $data['delivery_lng']);
        }
        $option = $data['payment_option'] ?? null;
        $allowed = self::paymentOptions($isDelivery, $zone['zone'] ?? null);
        if (! in_array($option, $allowed, true)) {
            throw new BusinessRuleException(match (true) {
                $option === 'cod' && $isDelivery => 'Pay on delivery is only available in '.implode(' and ', config('services.delivery.cod_districts')).'. Please pay online.',
                $option === 'online' => 'Online payment is not available right now',
                default => 'Choose a payment option',
            }, 'payment_option_not_allowed', ['allowed' => $allowed, 'zone' => $zone]);
        }

        try {
            $sale = DB::transaction(function () use ($data, $customer, $isDelivery, $zone, $option) {
                $location = $this->resolveLocation(null);
                $items = array_map(fn ($i) => ['product_id' => $i['product_id'], 'quantity' => $i['quantity']], $data['items']);
                $priced = $this->priceCart($items, null, null, onlineOnly: true);

                $fee = Money::of(Settings::get('delivery_fee', '0'));
                $total = $isDelivery ? Money::add($priced['total'], $fee) : $priced['total'];

                $sale = Sale::create([
                    'client_uuid' => $data['client_uuid'],
                    'number' => 'TMP-'.$data['client_uuid'],
                    'channel' => 'online',
                    'status' => 'pending',
                    // Online-payment orders wait for the gateway before staff start on them.
                    'fulfillment_status' => $option === 'online' ? 'awaiting_payment' : 'placed',
                    'payment_status' => 'unpaid',
                    'payment_option' => $option,
                    'payment_expires_at' => $option === 'online' ? now()->addMinutes((int) Settings::get('online_payment_expiry_minutes', '60')) : null,
                    'location_id' => $location->id,
                    'customer_id' => $customer->id,
                    'delivery_name' => $data['delivery_name'] ?? $customer->name,
                    'delivery_phone' => $data['delivery_phone'] ?? $customer->phone,
                    'delivery_address' => $isDelivery ? ($data['delivery_address'] ?? null) : 'Pickup at shop',
                    'delivery_lat' => $isDelivery ? $data['delivery_lat'] : null,
                    'delivery_lng' => $isDelivery ? $data['delivery_lng'] : null,
                    'delivery_zone' => $zone['zone'] ?? null,
                    'delivery_district' => $zone['district'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]);
                $sale->number = 'W'.str_pad((string) $sale->id, 7, '0', STR_PAD_LEFT);
                $this->writeItemsAndDeductStock($sale, $priced, $location->id);

                $sale->fill([
                    'subtotal' => $priced['subtotal'],
                    'discount_total' => $priced['discount_total'],
                    'tax_total' => $priced['tax_total'],
                    // Delivery fee is service revenue on top of goods; in total, not in subtotal.
                    'delivery_fee' => $isDelivery ? $fee : '0.00',
                    'total' => $total,
                    'amount_paid' => '0.00',
                    'balance_due' => $total,
                ])->save();

                if ($option !== 'online') \App\Models\FeedNotification::create([
                    'type' => 'online_order', 'title' => "New online order {$sale->number}",
                    'body' => "{$customer->name} · ".$total,
                    'subject_type' => $sale->getMorphClass(), 'subject_id' => $sale->id,
                ]);

                return $sale;
            }, 3);
        } catch (QueryException $e) {
            if ($this->isDuplicateUuid($e) && ($existing = Sale::where('client_uuid', $data['client_uuid'])->first())) {
                return ['sale' => $existing, 'replayed' => true];
            }
            throw $e;
        }

        return ['sale' => $sale->fresh(['items']), 'replayed' => false];
    }

    public const FULFILLMENT_FLOW = ['placed', 'confirmed', 'processing', 'ready', 'out_for_delivery', 'delivered'];

    /**
     * Payment options for an online order.
     *  cod    = pay on delivery: only for deliveries the server places in a COD district
     *  pickup = pay when collecting at the shop
     *  online = pay now through the gateway (anywhere)
     *
     * @return list<string>
     */
    public static function paymentOptions(bool $isDelivery, ?string $zone): array
    {
        $opts = [];
        if (FlutterwaveService::configured()) {
            $opts[] = 'online';
        }
        if (Settings::get('cod_enabled', '1') === '1') {
            if (! $isDelivery) {
                $opts[] = 'pickup';
            } elseif ($zone === 'metro') {
                $opts[] = 'cod';
            }
        }

        return $opts;
    }

    /** Money confirmed by the payment gateway (no cashier, no shift). Caller holds the sale lock. */
    public function recordGatewayPayment(Sale $sale, string $amount, string $reference): Payment
    {
        $method = PaymentMethod::where('code', 'flutterwave')->firstOrFail();
        $payment = $sale->payments()->create([
            'payment_method_id' => $method->id, 'kind' => 'payment', 'amount' => $amount,
            'reference' => $reference, 'user_id' => null, 'shift_id' => null, 'paid_at' => now(),
        ]);
        $paid = Money::add((string) $sale->amount_paid, $amount);
        $sale->amount_paid = $paid;
        $sale->balance_due = Money::max('0.00', Money::sub((string) $sale->balance_due, $amount));
        $sale->payment_status = $this->paymentStatus($paid, $this->netTotal($sale));
        if ($sale->fulfillment_status === 'awaiting_payment' && $sale->payment_status === 'paid') {
            $sale->fulfillment_status = 'placed';
            $sale->payment_expires_at = null;
            \App\Models\FeedNotification::create([
                'type' => 'online_order', 'title' => "New paid online order {$sale->number}", 'body' => 'Paid online · '.$sale->total,
                'subject_type' => $sale->getMorphClass(), 'subject_id' => $sale->id,
            ]);
        }
        $sale->save();
        Audit::log('payment.gateway', $sale, null, ['amount' => $amount, 'reference' => $reference]);

        return $payment;
    }

    public function updateFulfillment(Sale $sale, string $status): Sale
    {
        return DB::transaction(function () use ($sale, $status) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->channel !== 'online' || $sale->status !== 'pending') {
                throw new BusinessRuleException('Only open online orders can change status', 'invalid_state');
            }
            if ($status === 'cancelled') {
                return $this->cancelOrder($sale);
            }
            if (! in_array($status, self::FULFILLMENT_FLOW, true)) {
                throw new BusinessRuleException('Unknown status', 'invalid_state');
            }
            if ($sale->fulfillment_status === 'awaiting_payment') {
                throw new BusinessRuleException('This order is waiting for online payment', 'awaiting_payment');
            }
            $old = $sale->fulfillment_status;
            $sale->fulfillment_status = $status;
            if ($status === 'delivered') {
                $sale->status = 'completed';
                $sale->completed_at = now();
            }
            $sale->save();
            Audit::log('order.status', $sale, ['fulfillment_status' => $old], ['fulfillment_status' => $status]);

            return $sale;
        });
    }

    private function cancelOrder(Sale $sale): Sale
    {
        if (Money::cmp((string) $sale->amount_paid, '0') > 0) {
            throw new BusinessRuleException('Refund the recorded payment before cancelling this order', 'has_payments');
        }
        $lines = [];
        foreach ($sale->items()->with('components')->get() as $item) {
            if ($item->is_bundle) {
                foreach ($item->components as $c) {
                    $lines[] = ['product_id' => $c->product_id, 'quantity' => $c->quantity, 'unit_cost' => (string) $c->unit_cost];
                }
            } elseif ($item->product_id) {
                $lines[] = ['product_id' => $item->product_id, 'quantity' => $item->quantity, 'unit_cost' => (string) $item->unit_cost];
            }
        }
        $this->inventory->move('order_cancel', $sale->location_id, $lines, $sale, 'Online order cancelled');
        $sale->fill(['status' => 'cancelled', 'fulfillment_status' => 'cancelled', 'cancelled_at' => now(), 'balance_due' => '0.00'])->save();
        Audit::log('order.cancelled', $sale);

        return $sale;
    }

    /** Record money received against a sale (debt collection, online order paid on delivery). */
    public function addPayment(Sale $sale, array $data, User $user): Payment
    {
        if (! empty($data['client_uuid']) && ($p = Payment::where('client_uuid', $data['client_uuid'])->first())) {
            return $p;
        }

        return DB::transaction(function () use ($sale, $data, $user) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status === 'cancelled') {
                throw new BusinessRuleException('Sale is cancelled', 'invalid_state');
            }
            $amount = Money::of($data['amount']);
            if (Money::cmp($amount, '0') <= 0) {
                throw new BusinessRuleException('Amount must be positive', 'invalid_amount');
            }
            if (Money::cmp($amount, (string) $sale->balance_due) > 0) {
                throw new BusinessRuleException("Amount exceeds the balance due ({$sale->balance_due})", 'overpayment');
            }
            $method = $this->method($data['payment_method_id'], true, $data['reference'] ?? null);
            $shift = $this->openShift($user, $sale->location_id);
            if ($method->type === 'cash' && ! $shift && Settings::get('require_shift') === '1') {
                throw new BusinessRuleException('Open a cash shift before receiving cash', 'no_shift');
            }

            $payment = $sale->payments()->create([
                'client_uuid' => $data['client_uuid'] ?? null,
                'payment_method_id' => $method->id,
                'kind' => 'payment',
                'amount' => $amount,
                'reference' => $data['reference'] ?? null,
                'user_id' => $user->id,
                'shift_id' => $shift?->id,
                'paid_at' => now(),
            ]);
            $paid = Money::add((string) $sale->amount_paid, $amount);
            $sale->amount_paid = $paid;
            $sale->balance_due = Money::sub((string) $sale->balance_due, $amount);
            $sale->payment_status = $this->paymentStatus($paid, $this->netTotal($sale));
            $sale->save();
            Audit::log('payment.received', $sale, null, ['amount' => $amount, 'method' => $method->code]);

            return $payment;
        });
    }

    /**
     * Collect a lump sum from a customer and allocate it to their oldest unpaid sales first.
     *
     * @return Collection<int, Payment>
     */
    public function collectFromCustomer(Customer $customer, array $data, User $user): Collection
    {
        $uuid = $data['client_uuid'];
        // Allocation rows get uuids sharing the first 24 chars of the client key (see below).
        if (Payment::where('client_uuid', $uuid)->exists()) {
            return Payment::where('client_uuid', 'like', substr($uuid, 0, 24).'%')->get();
        }

        return DB::transaction(function () use ($customer, $data, $user, $uuid) {
            $remaining = Money::of($data['amount']);
            $sales = Sale::where('customer_id', $customer->id)->where('status', '!=', 'cancelled')
                ->where('balance_due', '>', 0)->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
            $owed = Money::add(...$sales->map(fn ($s) => (string) $s->balance_due)->all() ?: ['0']);
            if (Money::cmp($remaining, $owed) > 0) {
                throw new BusinessRuleException("Amount exceeds the customer's balance ({$owed})", 'overpayment');
            }
            $payments = collect();
            foreach ($sales as $i => $sale) {
                if (Money::cmp($remaining, '0') <= 0) {
                    break;
                }
                $portion = Money::min($remaining, (string) $sale->balance_due);
                $payments->push($this->addPayment($sale, [
                    // Derived uuids keep the whole allocation idempotent under one client key.
                    'client_uuid' => $i === 0 ? $uuid : substr($uuid, 0, 24).str_pad(dechex($i), 12, '0', STR_PAD_LEFT),
                    'payment_method_id' => $data['payment_method_id'],
                    'amount' => $portion,
                    'reference' => $data['reference'] ?? null,
                ], $user));
                $remaining = Money::sub($remaining, $portion);
            }

            return $payments;
        });
    }

    // ---------------------------------------------------------------- internals

    /**
     * Price a cart from the database. Returns per-line figures plus totals.
     * $user null = customer-facing (no discounts allowed).
     */
    public function priceCart(array $items, ?string $cartDiscount, ?User $user, bool $onlineOnly = false): array
    {
        if (! $items) {
            throw new BusinessRuleException('Cart is empty', 'empty_cart');
        }
        $ids = array_unique(array_map(fn ($i) => (int) $i['product_id'], $items));
        $products = Product::with('bundleItems')->whereIn('id', $ids)->get()->keyBy('id');

        $lines = [];
        $subtotal = '0.00';
        $lineDiscounts = '0.00';
        foreach ($items as $i) {
            $p = $products[(int) $i['product_id']] ?? null;
            $qty = (int) $i['quantity'];
            if (! $p || ! $p->is_active || $p->archived_at || ($onlineOnly && ! $p->show_online)) {
                throw new BusinessRuleException('A product in the cart is no longer available', 'unavailable', ['product_id' => $i['product_id']]);
            }
            if ($qty < 1) {
                throw new BusinessRuleException('Quantity must be at least 1', 'invalid_quantity');
            }
            if ($p->isBundle() && $p->bundleItems->isEmpty()) {
                throw new BusinessRuleException("{$p->name} has no components configured", 'empty_bundle');
            }
            $gross = Money::mul((string) $p->selling_price, $qty);
            $disc = Money::of($i['discount'] ?? '0');
            if (Money::isNegative($disc) || Money::cmp($disc, $gross) > 0) {
                throw new BusinessRuleException("Invalid discount on {$p->name}", 'invalid_discount');
            }
            $lines[] = ['product' => $p, 'quantity' => $qty, 'unit_price' => (string) $p->selling_price, 'gross' => $gross, 'discount' => $disc];
            $subtotal = Money::add($subtotal, $gross);
            $lineDiscounts = Money::add($lineDiscounts, $disc);
        }

        // Spread a cart-level discount over lines in proportion to their value so returns refund fairly.
        $cartDiscount = Money::of($cartDiscount ?? '0');
        $afterLine = Money::sub($subtotal, $lineDiscounts);
        if (Money::isNegative($cartDiscount) || Money::cmp($cartDiscount, $afterLine) > 0) {
            throw new BusinessRuleException('Invalid cart discount', 'invalid_discount');
        }
        if (! Money::isZero($cartDiscount)) {
            $left = $cartDiscount;
            $last = count($lines) - 1;
            foreach ($lines as $k => &$l) {
                $net = Money::sub($l['gross'], $l['discount']);
                $share = $k === $last ? $left : (Money::isZero($afterLine) ? '0.00' : Money::div(bcmul($cartDiscount, $net, 8), $afterLine));
                $share = Money::min($share, $net);
                $l['discount'] = Money::add($l['discount'], $share);
                $left = Money::sub($left, $share);
            }
            unset($l);
        }
        $discountTotal = Money::add($lineDiscounts, $cartDiscount);

        if (! Money::isZero($discountTotal)) {
            if (! $user) {
                throw new BusinessRuleException('Discounts are not available here', 'invalid_discount');
            }
            if (! Permissions::allows($user->role, 'sales.refund')) {
                // Cashiers: capped percentage. Managers/owners: unrestricted (still ≤ line value).
                $maxPct = Settings::get('max_cashier_discount_pct', '0');
                if (Money::cmp(Money::pct($subtotal, $maxPct), $discountTotal) < 0) {
                    throw new BusinessRuleException("Discount exceeds the {$maxPct}% cashier limit", 'discount_limit');
                }
            }
        }

        $rate = Settings::get('tax_rate', '0');
        $inclusive = Settings::get('tax_inclusive', '1') === '1';
        $taxTotal = '0.00';
        $total = '0.00';
        foreach ($lines as &$l) {
            $l['line_total'] = Money::sub($l['gross'], $l['discount']);
            if (Money::isZero($rate)) {
                $l['tax'] = '0.00';
                $l['amount'] = $l['line_total'];
            } elseif ($inclusive) {
                // Shelf price already contains tax: amount unchanged, record the tax portion.
                $l['tax'] = Money::sub($l['line_total'], Money::div(bcmul($l['line_total'], '100', 8), bcadd('100', $rate, 8)));
                $l['amount'] = $l['line_total'];
            } else {
                $l['tax'] = Money::pct($l['line_total'], $rate);
                $l['amount'] = Money::add($l['line_total'], $l['tax']);
            }
            $taxTotal = Money::add($taxTotal, $l['tax']);
            $total = Money::add($total, $l['amount']);
        }
        unset($l);

        return compact('lines', 'subtotal', 'taxTotal', 'total') + ['discount_total' => $discountTotal, 'tax_total' => $taxTotal];
    }

    private function writeItemsAndDeductStock(Sale $sale, array $priced, int $locationId): void
    {
        // 1. Work out every physical unit leaving the shelf (bundles expand to components).
        $moves = [];
        foreach ($priced['lines'] as $l) {
            $p = $l['product'];
            if ($p->isBundle()) {
                foreach ($p->bundleItems as $bi) {
                    $moves[] = ['product_id' => $bi->product_id, 'quantity' => -($bi->quantity * $l['quantity'])];
                }
            } else {
                $moves[] = ['product_id' => $p->id, 'quantity' => -$l['quantity']];
            }
        }
        // 2. Deduct atomically (locks, availability check, movements). Throws on shortage.
        $costs = $this->inventory->move('sale', $locationId, $moves, $sale, "Sale {$sale->number}");

        // 3. Persist lines with the cost actually consumed (historical COGS never changes later).
        $costTotal = '0.00';
        foreach ($priced['lines'] as $l) {
            $p = $l['product'];
            $item = $sale->items()->create([
                'product_id' => $p->id,
                'is_bundle' => $p->isBundle(),
                'name' => $p->name,
                'sku' => $p->sku,
                'quantity' => $l['quantity'],
                'unit_price' => $l['unit_price'],
                'discount' => $l['discount'],
                'line_total' => $l['line_total'],
                'tax' => $l['tax'],
                'amount' => $l['amount'],
                'unit_cost' => '0',
                'cost_total' => '0',
            ]);
            if ($p->isBundle()) {
                $lineCost = '0.00';
                $unitCost = '0';
                foreach ($p->bundleItems as $bi) {
                    $q = $bi->quantity * $l['quantity'];
                    $c = Money::mul($costs[$bi->product_id], $q);
                    $item->components()->create(['product_id' => $bi->product_id, 'quantity' => $q, 'unit_cost' => $costs[$bi->product_id], 'cost_total' => $c]);
                    $lineCost = Money::add($lineCost, $c);
                    $unitCost = bcadd($unitCost, bcmul($costs[$bi->product_id], (string) $bi->quantity, 4), 4);
                }
                $item->update(['unit_cost' => $unitCost, 'cost_total' => $lineCost]);
            } else {
                $lineCost = Money::mul($costs[$p->id], $l['quantity']);
                $item->update(['unit_cost' => $costs[$p->id], 'cost_total' => $lineCost]);
            }
            $costTotal = Money::add($costTotal, $lineCost);
        }
        $sale->cost_total = $costTotal;

        $sold = [];
        foreach ($priced['lines'] as $l) {
            $sold[] = $l['product']->id;
            foreach ($l['product']->isBundle() ? $l['product']->bundleItems : [] as $bi) {
                $sold[] = $bi->product_id;
            }
        }
        DB::table('products')->whereIn('id', array_unique($sold))->update(['last_sold_at' => now()]);
    }

    /** @return array{0:string,1:string} [amount applied to the sale, change given] */
    private function applyTenders(Sale $sale, array $tenders, string $total, User $user, ?Shift $shift, bool $offline = false): array
    {
        $applied = '0.00';
        $change = '0.00';
        // Non-cash first: they must never exceed what is due (no change from mobile money).
        usort($tenders, fn ($a, $b) => ($this->method($a['payment_method_id'])->type === 'cash') <=> ($this->method($b['payment_method_id'])->type === 'cash'));
        foreach ($tenders as $t) {
            $method = $this->method($t['payment_method_id'], true, $t['reference'] ?? null);
            $amount = Money::of($t['amount']);
            if (Money::cmp($amount, '0') <= 0) {
                continue;
            }
            $due = Money::sub($total, $applied);
            if ($method->type === 'cash') {
                if (! $shift && ! $offline && Settings::get('require_shift') === '1') {
                    throw new BusinessRuleException('Open a cash shift before taking cash', 'no_shift');
                }
                $use = Money::min($amount, $due);
                $change = Money::add($change, Money::sub($amount, $use));
            } else {
                if (Money::cmp($amount, $due) > 0) {
                    throw new BusinessRuleException("{$method->name} amount is more than the balance due", 'overpayment');
                }
                $use = $amount;
            }
            if (Money::cmp($use, '0') <= 0) {
                continue;
            }
            $sale->payments()->create([
                'payment_method_id' => $method->id,
                'kind' => 'payment',
                'amount' => $use,
                'reference' => $t['reference'] ?? null,
                'user_id' => $user->id,
                'shift_id' => $shift?->id,
                'paid_at' => now(),
            ]);
            $applied = Money::add($applied, $use);
        }

        return [$applied, $change];
    }

    private function assertCreditAllowed(?Customer $customer, string $newDebt): void
    {
        if (! $customer) {
            throw new BusinessRuleException('Select a customer to sell on credit (unpaid balance)', 'credit_needs_customer');
        }
        $limit = (string) $customer->credit_limit;
        if (Money::isZero($limit)) {
            throw new BusinessRuleException("{$customer->name} has no credit allowance", 'credit_not_allowed');
        }
        $owed = (string) (Sale::where('customer_id', $customer->id)->where('status', '!=', 'cancelled')->sum('balance_due') ?: '0');
        $after = Money::add(Money::of($owed), $newDebt);
        if (Money::cmp($after, $limit) > 0) {
            throw new BusinessRuleException("Credit limit exceeded: owes {$owed}, limit {$limit}", 'credit_limit', ['owed' => $owed, 'limit' => $limit]);
        }
    }

    public function netTotal(Sale $sale): string
    {
        return Money::sub((string) $sale->total, (string) $sale->refunded_total);
    }

    public function paymentStatus(string $paid, string $total): string
    {
        if (Money::cmp($total, '0') <= 0) {
            return 'paid';
        }
        if (Money::cmp($paid, '0') <= 0) {
            return 'unpaid';
        }

        return Money::cmp($paid, $total) >= 0 ? 'paid' : 'partial';
    }

    private array $methodCache = [];

    private function method(int|string $id, bool $checkReference = false, ?string $reference = null): PaymentMethod
    {
        $m = $this->methodCache[$id] ??= PaymentMethod::find($id) ?? throw new BusinessRuleException('Unknown payment method', 'invalid_method');
        if (! $m->is_active) {
            throw new BusinessRuleException("{$m->name} is disabled", 'invalid_method');
        }
        if ($checkReference && $m->requires_reference && trim((string) $reference) === '') {
            throw new BusinessRuleException("{$m->name} needs a transaction reference", 'reference_required');
        }

        return $m;
    }

    public function resolveLocation(?int $id): Location
    {
        $loc = $id ? Location::where('is_active', true)->find($id) : Location::where('is_default', true)->first();

        return $loc ?? Location::where('is_active', true)->orderBy('id')->firstOr(fn () => throw new BusinessRuleException('No active location', 'no_location'));
    }

    private function resolveShift(User $user, Location $location): ?Shift
    {
        $shift = $this->openShift($user, $location->id);
        if (! $shift && Settings::get('require_shift') === '1') {
            throw new BusinessRuleException('Open a shift before selling', 'no_shift');
        }

        return $shift;
    }

    public function openShift(User $user, int $locationId): ?Shift
    {
        return Shift::where('user_id', $user->id)->where('location_id', $locationId)->where('status', 'open')->latest('id')->first();
    }

    private function isDuplicateUuid(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), 'client_uuid');
    }
}
