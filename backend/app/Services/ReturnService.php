<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\User;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Customer returns against a completed sale.
 *
 * Value reversed per unit = the line's paid amount (after discounts, incl. tax) ÷ quantity.
 * Restocked units come back at the cost they left with, so COGS is reversed exactly.
 * Units not restocked (damaged) keep their cost in COGS: the shop absorbed that loss.
 * Money handed back = only what the customer had actually paid beyond the new total;
 * an unpaid credit balance is reduced first.
 */
class ReturnService
{
    public function __construct(private InventoryService $inventory, private SaleService $sales) {}

    /**
     * @param  array{client_uuid:string, reason:string, payment_method_id?:int|null,
     *     items:list<array{sale_item_id:int, quantity:int, restock?:bool}>}  $data
     */
    public function process(Sale $sale, array $data, User $user): SaleReturn
    {
        if ($r = SaleReturn::where('client_uuid', $data['client_uuid'])->first()) {
            return $r;
        }

        try {
            return DB::transaction(function () use ($sale, $data, $user) {
                $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
                if ($sale->status !== 'completed') {
                    throw new BusinessRuleException('Only completed sales can be returned (cancel open orders instead)', 'invalid_state');
                }
                $items = SaleItem::where('sale_id', $sale->id)->with('components')->lockForUpdate()->get()->keyBy('id');

                $return = SaleReturn::create([
                    'client_uuid' => $data['client_uuid'],
                    'number' => 'TMP-'.$data['client_uuid'],
                    'sale_id' => $sale->id,
                    'user_id' => $user->id,
                    'shift_id' => $this->sales->openShift($user, $sale->location_id)?->id,
                    'reason' => $data['reason'],
                    'refund_total' => 0, 'cash_refunded' => 0, 'cost_reversed' => 0,
                ]);
                $return->number = 'R'.str_pad((string) $return->id, 7, '0', STR_PAD_LEFT);

                $value = '0.00';
                $tax = '0.00';
                $costReversed = '0.00';
                $restock = [];
                $seen = [];
                foreach ($data['items'] as $line) {
                    $item = $items[$line['sale_item_id']] ?? null;
                    $q = (int) $line['quantity'];
                    if (! $item || isset($seen[$item->id])) {
                        throw new BusinessRuleException('Invalid or repeated item in return', 'invalid_item');
                    }
                    $seen[$item->id] = true;
                    $returnable = $item->quantity - $item->returned_quantity;
                    if ($q < 1 || $q > $returnable) {
                        throw new BusinessRuleException("Only {$returnable} of {$item->name} can still be returned", 'over_return');
                    }
                    $isLast = $q === $returnable;
                    // The final return of a line takes whatever is left, so rounding never leaks.
                    $amount = $isLast ? $this->remaining($item, 'amount') : Money::div(bcmul((string) $item->amount, (string) $q, 8), $item->quantity);
                    $lineTax = $isLast ? $this->remaining($item, 'tax') : Money::div(bcmul((string) $item->tax, (string) $q, 8), $item->quantity);
                    $cost = $isLast ? $this->remaining($item, 'cost_total') : Money::div(bcmul((string) $item->cost_total, (string) $q, 8), $item->quantity);
                    $doRestock = (bool) ($line['restock'] ?? true);

                    $return->items()->create(['sale_item_id' => $item->id, 'quantity' => $q, 'amount' => $amount, 'tax' => $lineTax, 'cost' => $cost, 'restock' => $doRestock]);
                    $item->increment('returned_quantity', $q);

                    $value = Money::add($value, $amount);
                    $tax = Money::add($tax, $lineTax);
                    if ($doRestock) {
                        $costReversed = Money::add($costReversed, $cost);
                        if ($item->is_bundle) {
                            foreach ($item->components as $c) {
                                $restock[] = ['product_id' => $c->product_id, 'quantity' => intdiv($c->quantity, $item->quantity) * $q, 'unit_cost' => (string) $c->unit_cost];
                            }
                        } elseif ($item->product_id) {
                            $restock[] = ['product_id' => $item->product_id, 'quantity' => $q, 'unit_cost' => (string) $item->unit_cost];
                        }
                    }
                }
                if ($restock) {
                    $this->inventory->move('sale_return', $sale->location_id, $restock, $return, $data['reason']);
                }

                // Settle money: reduce any unpaid balance first, refund the rest of what was paid.
                $newNet = Money::sub(Money::sub((string) $sale->total, (string) $sale->refunded_total), $value);
                $paid = (string) $sale->amount_paid;
                $cashBack = Money::cmp($paid, $newNet) > 0 ? Money::sub($paid, $newNet) : '0.00';
                if (Money::cmp($cashBack, '0') > 0) {
                    $method = PaymentMethod::where('is_active', true)->find($data['payment_method_id'] ?? 0)
                        ?? throw new BusinessRuleException('Choose how the refund is paid out', 'refund_method_required');
                    if ($method->type === 'cash' && ! $return->shift_id && Settings::get('require_shift') === '1') {
                        throw new BusinessRuleException('Open a shift before paying out cash', 'no_shift');
                    }
                    $sale->payments()->create([
                        'payment_method_id' => $method->id, 'kind' => 'refund', 'amount' => $cashBack,
                        'reference' => $return->number, 'user_id' => $user->id, 'shift_id' => $return->shift_id, 'paid_at' => now(),
                    ]);
                }
                $sale->amount_paid = Money::sub($paid, $cashBack);
                $sale->balance_due = Money::max('0.00', Money::sub($newNet, (string) $sale->amount_paid));
                $sale->refunded_total = Money::add((string) $sale->refunded_total, $value);
                $sale->refunded_tax = Money::add((string) $sale->refunded_tax, $tax);
                $sale->returned_cost_total = Money::add((string) $sale->returned_cost_total, $costReversed);
                $sale->payment_status = Money::isZero($newNet) ? 'refunded' : $this->sales->paymentStatus((string) $sale->amount_paid, $newNet);
                $sale->save();

                $return->fill(['refund_total' => $value, 'refund_tax' => $tax, 'cash_refunded' => $cashBack, 'cost_reversed' => $costReversed])->save();
                Audit::log('sale.returned', $return, null, ['sale' => $sale->number, 'value' => $value, 'cash_refunded' => $cashBack]);

                return $return->load('items');
            }, 3);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062 && ($r = SaleReturn::where('client_uuid', $data['client_uuid'])->first())) {
                return $r;
            }
            throw $e;
        }
    }

    /** Line field value not yet covered by earlier partial returns of this line. */
    private function remaining(SaleItem $item, string $field): string
    {
        $col = ['amount' => 'amount', 'tax' => 'tax', 'cost_total' => 'cost'][$field];
        $used = (string) (DB::table('sale_return_items')->where('sale_item_id', $item->id)->sum($col) ?: '0');

        return Money::sub((string) $item->{$field}, Money::of($used));
    }
}
