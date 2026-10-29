<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\GoodsReceipt;
use App\Models\PaymentMethod;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Purchase orders -> goods received (= supplier invoice) -> supplier payments.
 * Receiving stock is the moment inventory capital increases and the weighted-average
 * cost is updated. Buying stock is NOT an expense: it only becomes cost (COGS) when sold.
 */
class PurchaseService
{
    public function __construct(private InventoryService $inventory, private SaleService $sales) {}

    public function savePurchaseOrder(array $data, User $user, ?PurchaseOrder $po = null): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $user, $po) {
            if ($po && $po->status !== 'draft' && $po->status !== 'ordered') {
                throw new BusinessRuleException('Only draft or ordered purchase orders can be edited', 'invalid_state');
            }
            if ($po && $po->items()->where('quantity_received', '>', 0)->exists()) {
                throw new BusinessRuleException('Items have already been received against this order', 'invalid_state');
            }
            $total = '0.00';
            foreach ($data['items'] as $i) {
                $total = Money::add($total, Money::mul(Money::of($i['unit_cost'], 4), (int) $i['quantity']));
            }
            $attrs = [
                'supplier_id' => $data['supplier_id'],
                'location_id' => $data['location_id'] ?? $this->sales->resolveLocation(null)->id,
                'status' => $data['status'] ?? 'ordered',
                'order_date' => $data['order_date'] ?? now()->toDateString(),
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'total' => $total,
            ];
            if ($po) {
                $po->update($attrs);
                $po->items()->delete();
            } else {
                $po = PurchaseOrder::create($attrs + ['number' => Sequence::next('po', 'PO-'), 'user_id' => $user->id]);
            }
            foreach ($data['items'] as $i) {
                $po->items()->create(['product_id' => $i['product_id'], 'quantity_ordered' => (int) $i['quantity'], 'unit_cost' => Money::of($i['unit_cost'], 4)]);
            }
            Audit::log('purchase_order.saved', $po, null, ['total' => $total, 'status' => $po->status]);

            return $po->load('items.product:id,name,sku', 'supplier:id,name');
        });
    }

    /**
     * @param  array{client_uuid:string, supplier_id?:int|null, purchase_order_id?:int|null, location_id?:int|null,
     *     supplier_invoice_no?:string|null, received_date?:string|null, due_date?:string|null, notes?:string|null,
     *     items:list<array{product_id:int, quantity:int, unit_cost:string, purchase_order_item_id?:int|null}>,
     *     payment?:array{payment_method_id:int, amount:string, reference?:string|null}|null}  $data
     */
    public function receive(array $data, User $user): GoodsReceipt
    {
        if ($r = GoodsReceipt::where('client_uuid', $data['client_uuid'])->first()) {
            return $r;
        }
        try {
            return DB::transaction(function () use ($data, $user) {
                $po = null;
                if (! empty($data['purchase_order_id'])) {
                    $po = PurchaseOrder::whereKey($data['purchase_order_id'])->lockForUpdate()->firstOrFail();
                    if (in_array($po->status, ['received', 'cancelled'], true)) {
                        throw new BusinessRuleException("Purchase order {$po->number} is {$po->status}", 'invalid_state');
                    }
                }
                $supplierId = $po?->supplier_id ?? ($data['supplier_id'] ?? null);
                $locationId = $po?->location_id ?? ($data['location_id'] ?? $this->sales->resolveLocation(null)->id);

                $receipt = GoodsReceipt::create([
                    'client_uuid' => $data['client_uuid'],
                    'number' => Sequence::next('grn', 'GRN-'),
                    'purchase_order_id' => $po?->id,
                    'supplier_id' => $supplierId,
                    'location_id' => $locationId,
                    'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                    'received_date' => $data['received_date'] ?? now()->toDateString(),
                    'due_date' => $data['due_date'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'user_id' => $user->id,
                ]);

                $total = '0.00';
                $moves = [];
                foreach ($data['items'] as $i) {
                    $qty = (int) $i['quantity'];
                    $cost = Money::of($i['unit_cost'], Money::COST_SCALE);
                    if ($qty < 1 || Money::isNegative($cost)) {
                        throw new BusinessRuleException('Quantities must be positive and costs not negative', 'invalid_line');
                    }
                    $poItemId = $i['purchase_order_item_id'] ?? null;
                    if ($poItemId) {
                        $poItem = PurchaseOrderItem::where('purchase_order_id', $po?->id)->lockForUpdate()->findOrFail($poItemId);
                        if ($poItem->product_id !== (int) $i['product_id']) {
                            throw new BusinessRuleException('Line does not match the purchase order', 'invalid_line');
                        }
                        // Receiving more than ordered is allowed (supplier sent extra) but recorded.
                        $poItem->increment('quantity_received', $qty);
                    }
                    $line = Money::mul($cost, $qty);
                    $receipt->items()->create(['purchase_order_item_id' => $poItemId, 'product_id' => $i['product_id'], 'quantity' => $qty, 'unit_cost' => $cost, 'line_total' => $line]);
                    $moves[] = ['product_id' => $i['product_id'], 'quantity' => $qty, 'unit_cost' => $cost];
                    $total = Money::add($total, $line);
                }
                $this->inventory->move('purchase', $locationId, $moves, $receipt, "Goods received {$receipt->number}");
                $receipt->update(['total' => $total]);

                if ($po) {
                    $po->load('items');
                    $complete = $po->items->every(fn ($it) => $it->quantity_received >= $it->quantity_ordered);
                    $po->update(['status' => $complete ? 'received' : 'partial']);
                }

                if (! empty($data['payment']['amount']) && Money::cmp(Money::of($data['payment']['amount']), '0') > 0) {
                    if (! $supplierId) {
                        throw new BusinessRuleException('A supplier is required to record a payment', 'invalid_line');
                    }
                    $this->paySupplier([
                        'client_uuid' => (string) \Illuminate\Support\Str::uuid(),
                        'supplier_id' => $supplierId,
                        'goods_receipt_id' => $receipt->id,
                    ] + $data['payment'], $user);
                }
                Audit::log('goods.received', $receipt, null, ['total' => $total, 'lines' => count($moves)]);

                return $receipt->load('items.product:id,name,sku');
            }, 3);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062 && ($r = GoodsReceipt::where('client_uuid', $data['client_uuid'])->first())) {
                return $r;
            }
            throw $e;
        }
    }

    public function paySupplier(array $data, User $user): SupplierPayment
    {
        if ($p = SupplierPayment::where('client_uuid', $data['client_uuid'])->first()) {
            return $p;
        }

        return DB::transaction(function () use ($data, $user) {
            $supplier = Supplier::whereKey($data['supplier_id'])->lockForUpdate()->firstOrFail();
            $amount = Money::of($data['amount']);
            $balance = self::supplierBalance($supplier->id);
            if (Money::cmp($amount, '0') <= 0 || Money::cmp($amount, $balance) > 0) {
                throw new BusinessRuleException("Payment must be between 0 and the amount owed ({$balance})", 'overpayment');
            }
            $method = PaymentMethod::where('is_active', true)->findOrFail($data['payment_method_id']);
            $payment = SupplierPayment::create([
                'client_uuid' => $data['client_uuid'],
                'supplier_id' => $supplier->id,
                'goods_receipt_id' => $data['goods_receipt_id'] ?? null,
                'payment_method_id' => $method->id,
                'amount' => $amount,
                'reference' => $data['reference'] ?? null,
                'paid_date' => $data['paid_date'] ?? now()->toDateString(),
                'user_id' => $user->id,
                // Cash paid from the till comes out of the cashier's drawer.
                'shift_id' => $method->type === 'cash' ? $this->sales->openShift($user, $this->sales->resolveLocation(null)->id)?->id : null,
            ]);
            Audit::log('supplier.paid', $payment, null, ['supplier' => $supplier->name, 'amount' => $amount]);

            return $payment;
        });
    }

    public static function supplierBalance(int $supplierId): string
    {
        $row = DB::selectOne(
            'SELECT s.opening_balance
                + COALESCE((SELECT SUM(total) FROM goods_receipts WHERE supplier_id = s.id), 0)
                - COALESCE((SELECT SUM(amount) FROM supplier_payments WHERE supplier_id = s.id), 0) AS balance
             FROM suppliers s WHERE s.id = ?', [$supplierId]);

        return Money::of((string) ($row->balance ?? '0'));
    }
}
