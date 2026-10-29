<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Audit;
use App\Services\InventoryService;
use App\Services\SaleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    public function movements(Request $request)
    {
        $v = $request->validate([
            'product_id' => 'nullable|integer', 'location_id' => 'nullable|integer', 'type' => 'nullable|string|max:20',
            'user_id' => 'nullable|integer', 'from' => 'nullable|date', 'to' => 'nullable|date', 'per_page' => 'nullable|integer|max:200',
        ]);
        $q = StockMovement::with(['product:id,name,sku', 'location:id,name,code', 'user:id,name'])->orderByDesc('id');
        foreach (['product_id', 'location_id', 'type', 'user_id'] as $f) {
            if (! empty($v[$f])) {
                $q->where($f, $v[$f]);
            }
        }
        if (! empty($v['from'])) {
            $q->where('created_at', '>=', $v['from']);
        }
        if (! empty($v['to'])) {
            $q->where('created_at', '<=', $v['to'].' 23:59:59');
        }

        return $q->paginate($v['per_page'] ?? 50);
    }

    /**
     * Manual stock changes. Types:
     *  adjustment (+/-), damaged (-), missing (-), opening (+, with cost),
     *  count (set to the physically counted quantity), transfer (between locations).
     */
    public function adjust(Request $request)
    {
        $d = $request->validate([
            'type' => 'required|in:adjustment,damaged,missing,opening,count,transfer',
            'location_id' => 'nullable|exists:locations,id',
            'to_location_id' => 'required_if:type,transfer|nullable|exists:locations,id|different:location_id',
            'reason' => 'required|string|max:255',
            'lines' => 'required|array|min:1|max:500',
            'lines.*.product_id' => 'required|integer|exists:products,id',
            'lines.*.quantity' => 'required|integer|min:-10000000|max:10000000',
            'lines.*.unit_cost' => 'nullable|numeric|min:0',
        ]);
        $loc = app(SaleService::class)->resolveLocation($d['location_id'] ?? null);

        $result = DB::transaction(function () use ($d, $loc) {
            $lines = [];
            if ($d['type'] === 'count') {
                // Lock levels so the difference is computed against the true current quantity.
                $ids = collect($d['lines'])->pluck('product_id')->unique()->sort()->values();
                Product::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                $current = DB::table('stock_levels')->where('location_id', $loc->id)->whereIn('product_id', $ids)->lockForUpdate()->pluck('quantity', 'product_id');
                foreach ($d['lines'] as $l) {
                    if ($l['quantity'] < 0) {
                        throw new BusinessRuleException('Counted quantity cannot be negative', 'invalid_quantity');
                    }
                    $lines[] = ['product_id' => $l['product_id'], 'quantity' => $l['quantity'] - (int) ($current[$l['product_id']] ?? 0)];
                }
            } else {
                foreach ($d['lines'] as $l) {
                    $q = (int) $l['quantity'];
                    $q = match ($d['type']) {
                        'damaged', 'missing', 'transfer' => -abs($q),
                        'opening' => abs($q),
                        default => $q,
                    };
                    $lines[] = ['product_id' => $l['product_id'], 'quantity' => $q, 'unit_cost' => $d['type'] === 'opening' ? (string) ($l['unit_cost'] ?? '0') : null];
                }
            }

            if ($d['type'] === 'transfer') {
                $costs = $this->inventory->move('transfer_out', $loc->id, $lines, null, $d['reason']);
                // Transfers keep the average cost: same units, different shelf.
                $this->inventory->move('transfer_in', (int) $d['to_location_id'],
                    array_map(fn ($l) => ['product_id' => $l['product_id'], 'quantity' => -$l['quantity']], $lines), null, $d['reason']);
            } else {
                $this->inventory->move($d['type'], $loc->id, $lines, null, $d['reason']);
            }
            Audit::log('stock.'.$d['type'], null, null, ['location' => $loc->code, 'reason' => $d['reason'], 'lines' => $lines]);

            return count($lines);
        });

        return response()->json(['message' => "Recorded {$result} line(s)"]);
    }

    public function lowStock(Request $request)
    {
        return Product::where('type', 'standard')->whereNull('archived_at')->where('is_active', true)
            ->whereColumn('total_stock', '<=', 'min_stock')
            ->with('supplier:id,name')
            ->orderBy('total_stock')->orderBy('name')
            ->paginate($request->integer('per_page', 50), ['id', 'name', 'sku', 'total_stock', 'min_stock', 'cost_price', 'supplier_id']);
    }
}
