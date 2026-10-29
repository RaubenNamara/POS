<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\FeedNotification;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The only code path that changes stock. Every change:
 *  - runs inside the caller's DB transaction,
 *  - locks product rows then stock_level rows, always in ascending product id order
 *    (one global lock order = no deadlocks between concurrent cashiers),
 *  - refuses to go below zero (and the DB CHECK constraint backs that up),
 *  - writes a stock_movements row with user, reason, reference and resulting balance,
 *  - keeps products.total_stock and the weighted-average cost in step.
 */
class InventoryService
{
    /**
     * @param  array<int, array{product_id:int, quantity:int, unit_cost?:string|null}>  $lines  signed quantities
     * @return array<int, string> product_id => unit cost applied (avg cost for outbound lines)
     */
    public function move(string $type, int $locationId, array $lines, ?Model $reference = null, ?string $reason = null): array
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('InventoryService::move must run inside a transaction');
        }

        // Merge duplicate product lines so a product is checked against its full requested quantity.
        $merged = [];
        foreach ($lines as $l) {
            $pid = (int) $l['product_id'];
            $qty = (int) $l['quantity'];
            if ($qty === 0) {
                continue;
            }
            if (isset($merged[$pid])) {
                if (isset($l['unit_cost']) && $l['unit_cost'] !== null && $qty > 0) {
                    // Combine inbound costed lines into one weighted line.
                    $prevVal = bcmul((string) $merged[$pid]['quantity'], $merged[$pid]['unit_cost'] ?? '0', 8);
                    $val = bcadd($prevVal, bcmul((string) $qty, (string) $l['unit_cost'], 8), 8);
                    $merged[$pid]['quantity'] += $qty;
                    $merged[$pid]['unit_cost'] = Money::div($val, (string) $merged[$pid]['quantity'], Money::COST_SCALE);
                } else {
                    $merged[$pid]['quantity'] += $qty;
                }
            } else {
                $merged[$pid] = ['quantity' => $qty, 'unit_cost' => isset($l['unit_cost']) ? (string) $l['unit_cost'] : null];
            }
        }
        if (! $merged) {
            return [];
        }
        ksort($merged);
        $ids = array_keys($merged);

        $products = Product::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($ids as $pid) {
            $p = $products[$pid] ?? null;
            if (! $p) {
                throw new BusinessRuleException("Product #{$pid} not found", 'product_missing', ['product_id' => $pid]);
            }
            if ($p->isBundle()) {
                throw new \LogicException('Bundles hold no stock; move their components instead');
            }
        }

        $now = now();
        DB::table('stock_levels')->insertOrIgnore(array_map(fn ($pid) => [
            'product_id' => $pid, 'location_id' => $locationId, 'quantity' => 0, 'created_at' => $now, 'updated_at' => $now,
        ], $ids));
        $levels = DB::table('stock_levels')->where('location_id', $locationId)->whereIn('product_id', $ids)
            ->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');

        // Validate everything before writing anything.
        $short = [];
        foreach ($merged as $pid => $m) {
            $available = (int) $levels[$pid]->quantity;
            if ($available + $m['quantity'] < 0) {
                $short[] = ['product_id' => $pid, 'name' => $products[$pid]->name, 'available' => $available, 'requested' => -$m['quantity']];
            }
        }
        if ($short) {
            $names = implode(', ', array_map(fn ($s) => "{$s['name']} (only {$s['available']} left)", $short));
            throw new BusinessRuleException("Not enough stock: {$names}", 'insufficient_stock', ['items' => $short]);
        }

        $applied = [];
        $movements = [];
        $userId = auth()->id();
        foreach ($merged as $pid => $m) {
            $p = $products[$pid];
            $qty = $m['quantity'];
            $oldTotal = (int) $p->total_stock;
            $cost = (string) $p->cost_price;

            // Inbound units with a known cost (purchases, opening stock, returns at their original
            // sale cost) are folded into the weighted average. Uncosted inbound (found items) is not.
            if ($qty > 0 && $m['unit_cost'] !== null) {
                $cost = Money::of($m['unit_cost'], Money::COST_SCALE);
                $newAvg = Money::weightedAverage($oldTotal, (string) $p->cost_price, $qty, $cost);
                DB::table('products')->where('id', $pid)->update(['cost_price' => $newAvg]);
            }

            $newBalance = (int) $levels[$pid]->quantity + $qty;
            DB::table('stock_levels')->where('id', $levels[$pid]->id)->update(['quantity' => $newBalance, 'updated_at' => $now]);
            DB::table('products')->where('id', $pid)->update(['total_stock' => $oldTotal + $qty, 'updated_at' => $now]);

            $movements[] = [
                'product_id' => $pid,
                'location_id' => $locationId,
                'type' => $type,
                'quantity' => $qty,
                'balance_after' => $newBalance,
                'unit_cost' => $cost,
                'reason' => $reason,
                'user_id' => $userId,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'created_at' => $now,
            ];
            $applied[$pid] = $cost;

            $this->maybeNotifyLowStock($p, $oldTotal, $oldTotal + $qty);
        }
        DB::table('stock_movements')->insert($movements);

        DB::afterCommit(fn () => Cache::increment('catalog_version'));

        return $applied;
    }

    /** Units of a bundle that can be assembled from current component stock. */
    public static function bundleAvailability(Product $bundle): int
    {
        $items = $bundle->relationLoaded('bundleItems') ? $bundle->bundleItems : $bundle->bundleItems()->with('product:id,total_stock')->get();
        if ($items->isEmpty()) {
            return 0;
        }
        $min = PHP_INT_MAX;
        foreach ($items as $i) {
            $min = min($min, intdiv(max(0, (int) $i->product->total_stock), max(1, (int) $i->quantity)));
        }

        return $min === PHP_INT_MAX ? 0 : $min;
    }

    private function maybeNotifyLowStock(Product $p, int $before, int $after): void
    {
        if ($after >= $before) {
            return;
        }
        $min = (int) $p->min_stock;
        if ($after <= 0 && $before > 0) {
            $type = 'out_of_stock';
            $title = "{$p->name} is out of stock";
        } elseif ($after <= $min && $before > $min) {
            $type = 'low_stock';
            $title = "{$p->name} is low ({$after} left, minimum {$min})";
        } else {
            return;
        }
        FeedNotification::create([
            'type' => $type, 'title' => $title, 'body' => "SKU {$p->sku}",
            'subject_type' => $p->getMorphClass(), 'subject_id' => $p->id,
        ]);
    }
}
