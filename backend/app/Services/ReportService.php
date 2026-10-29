<?php

namespace App\Services;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Financial and operational figures, computed from transactions with aggregate SQL.
 *
 * Definitions (all over a date range, inclusive):
 *  revenue (net sales) = Σ completed sales total − tax − (returns value − returns tax)
 *  COGS                = Σ cost of units sold − cost of units returned to stock
 *  gross profit        = revenue − COGS
 *  net profit          = gross profit − operating expenses
 *  Stock purchases are NOT expenses here: they are inventory capital until sold.
 *  Credit sales count as revenue when completed, but not as cash received.
 *  Online orders count only once delivered (status completed).
 */
class ReportService
{
    public static function range(?string $from, ?string $to): array
    {
        $tz = config('app.timezone');
        $f = $from ? CarbonImmutable::parse($from, $tz)->startOfDay() : CarbonImmutable::now($tz)->startOfMonth();
        $t = $to ? CarbonImmutable::parse($to, $tz)->endOfDay() : CarbonImmutable::now($tz)->endOfDay();
        if ($t->lessThan($f)) {
            [$f, $t] = [$t->startOfDay(), $f->endOfDay()];
        }

        return [$f, $t];
    }

    public function profitAndLoss(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $s = DB::table('sales')->where('status', 'completed')->whereBetween('completed_at', [$from, $to])
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(subtotal),0) AS gross, COALESCE(SUM(discount_total),0) AS discounts,
                COALESCE(SUM(tax_total),0) AS tax, COALESCE(SUM(delivery_fee),0) AS delivery, COALESCE(SUM(total),0) AS total,
                COALESCE(SUM(cost_total),0) AS cost')->first();
        $r = DB::table('sale_returns')->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(refund_total),0) AS value, COALESCE(SUM(refund_tax),0) AS tax,
                COALESCE(SUM(cash_refunded),0) AS cash, COALESCE(SUM(cost_reversed),0) AS cost')->first();
        $e = DB::table('expenses')->whereNull('voided_at')->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount),0) AS total')->first();
        // Stock lost (damaged/missing/count shortfalls) is a cost, valued at average cost when written
        // off; stock found by a count or adjustment offsets it.
        $w = DB::table('stock_movements')->whereIn('type', ['damaged', 'missing', 'adjustment', 'count'])->whereBetween('created_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(-quantity * unit_cost),0) AS value')->first();

        $m = fn ($v) => Money::of((string) $v);
        $salesExTax = Money::sub($m($s->total), $m($s->tax));
        $returnsExTax = Money::sub($m($r->value), $m($r->tax));
        $revenue = Money::sub($salesExTax, $returnsExTax);
        $cogs = Money::sub($m($s->cost), $m($r->cost));
        $gross = Money::sub($revenue, $cogs);
        $writeOffs = $m($w->value);
        $expenses = $m($e->total);
        $net = Money::sub(Money::sub($gross, $expenses), $writeOffs);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'sales_count' => (int) $s->n,
            'gross_sales' => $m($s->gross),
            'discounts' => $m($s->discounts),
            'delivery_fees' => $m($s->delivery),
            'tax_collected' => Money::sub($m($s->tax), $m($r->tax)),
            'returns_count' => (int) $r->n,
            'returns_value' => $m($r->value),
            'cash_refunded' => $m($r->cash),
            'net_revenue' => $revenue,
            'cogs' => $cogs,
            'gross_profit' => $gross,
            'gross_margin_pct' => Money::isZero($revenue) ? '0.00' : Money::div(bcmul($gross, '100', 8), $revenue),
            'expenses' => $expenses,
            'expenses_count' => (int) $e->n,
            'stock_write_offs' => $writeOffs,
            'net_profit' => $net,
        ];
    }

    /** Money actually received/paid out per method (cash flow), not revenue. */
    public function cashFlow(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $in = DB::table('payments as p')->join('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->whereBetween('p.paid_at', [$from, $to])->groupBy('m.id', 'm.name')
            ->selectRaw("m.id, m.name, SUM(CASE WHEN p.kind='payment' THEN p.amount ELSE 0 END) AS received,
                SUM(CASE WHEN p.kind='refund' THEN p.amount ELSE 0 END) AS refunded")->get()->keyBy('id');
        $exp = DB::table('expenses')->whereNull('voided_at')->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('payment_method_id')->selectRaw('payment_method_id AS id, SUM(amount) AS total')->pluck('total', 'id');
        $sup = DB::table('supplier_payments')->whereBetween('paid_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('payment_method_id')->selectRaw('payment_method_id AS id, SUM(amount) AS total')->pluck('total', 'id');

        $rows = [];
        $totals = ['received' => '0.00', 'refunded' => '0.00', 'expenses' => '0.00', 'supplier_payments' => '0.00', 'net' => '0.00'];
        foreach (DB::table('payment_methods')->orderBy('sort_order')->get() as $pm) {
            $row = [
                'method' => $pm->name,
                'received' => Money::of((string) ($in[$pm->id]->received ?? 0)),
                'refunded' => Money::of((string) ($in[$pm->id]->refunded ?? 0)),
                'expenses' => Money::of((string) ($exp[$pm->id] ?? 0)),
                'supplier_payments' => Money::of((string) ($sup[$pm->id] ?? 0)),
            ];
            $row['net'] = Money::sub(Money::sub(Money::sub($row['received'], $row['refunded']), $row['expenses']), $row['supplier_payments']);
            foreach ($totals as $k => $v) {
                $totals[$k] = Money::add($v, $row[$k]);
            }
            $rows[] = $row;
        }

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'methods' => $rows, 'totals' => $totals];
    }

    /** Current inventory capital, overall and per location. */
    public function inventoryValuation(): array
    {
        $t = DB::table('products')->where('type', 'standard')
            ->selectRaw('COUNT(*) AS products, COALESCE(SUM(total_stock),0) AS units,
                COALESCE(SUM(total_stock * cost_price),0) AS cost_value,
                COALESCE(SUM(total_stock * selling_price),0) AS retail_value,
                SUM(CASE WHEN total_stock <= 0 AND archived_at IS NULL AND is_active = 1 THEN 1 ELSE 0 END) AS out_of_stock,
                SUM(CASE WHEN total_stock > 0 AND total_stock <= min_stock AND archived_at IS NULL AND is_active = 1 THEN 1 ELSE 0 END) AS low_stock')
            ->first();
        $byLocation = DB::table('stock_levels as s')->join('products as p', 'p.id', '=', 's.product_id')
            ->join('locations as l', 'l.id', '=', 's.location_id')->groupBy('l.id', 'l.name')
            ->selectRaw('l.id, l.name, SUM(s.quantity) AS units, SUM(s.quantity * p.cost_price) AS cost_value, SUM(s.quantity * p.selling_price) AS retail_value')
            ->get()->map(fn ($r) => [
                'location' => $r->name, 'units' => (int) $r->units,
                'cost_value' => Money::of((string) $r->cost_value), 'retail_value' => Money::of((string) $r->retail_value),
            ]);
        $cost = Money::of((string) $t->cost_value);
        $retail = Money::of((string) $t->retail_value);

        return [
            'products' => (int) $t->products,
            'units' => (int) $t->units,
            'cost_value' => $cost,
            'retail_value' => $retail,
            'potential_profit' => Money::sub($retail, $cost),
            'out_of_stock' => (int) $t->out_of_stock,
            'low_stock' => (int) $t->low_stock,
            'by_location' => $byLocation,
        ];
    }

    public function balances(): array
    {
        $receivable = DB::table('sales')->where('status', '!=', 'cancelled')->where('balance_due', '>', 0)
            ->where('status', 'completed')
            ->selectRaw('COUNT(DISTINCT customer_id) AS customers, COALESCE(SUM(balance_due),0) AS total')->first();
        $payable = DB::selectOne('SELECT COALESCE(SUM(b),0) AS total, SUM(CASE WHEN b > 0 THEN 1 ELSE 0 END) AS suppliers FROM (
            SELECT s.opening_balance + COALESCE((SELECT SUM(total) FROM goods_receipts g WHERE g.supplier_id = s.id),0)
                 - COALESCE((SELECT SUM(amount) FROM supplier_payments p WHERE p.supplier_id = s.id),0) AS b
            FROM suppliers s) x');

        return [
            'receivable' => Money::of((string) $receivable->total),
            'receivable_customers' => (int) $receivable->customers,
            'payable' => Money::of((string) $payable->total),
            'payable_suppliers' => (int) $payable->suppliers,
        ];
    }

    public function salesSeries(CarbonImmutable $from, CarbonImmutable $to, string $group = 'day'): array
    {
        $fmt = ['day' => '%Y-%m-%d', 'week' => '%x-W%v', 'month' => '%Y-%m', 'year' => '%Y'][$group] ?? '%Y-%m-%d';
        $sales = DB::table('sales')->where('status', 'completed')->whereBetween('completed_at', [$from, $to])
            ->groupBy('period')->orderBy('period')
            ->selectRaw("DATE_FORMAT(completed_at, '{$fmt}') AS period, COUNT(*) AS count,
                SUM(total - tax_total) AS revenue, SUM(cost_total) AS cost")->get()->keyBy('period');
        $returns = DB::table('sale_returns')->whereBetween('created_at', [$from, $to])->groupBy('period')
            ->selectRaw("DATE_FORMAT(created_at, '{$fmt}') AS period, SUM(refund_total - refund_tax) AS value, SUM(cost_reversed) AS cost")
            ->get()->keyBy('period');
        $periods = $sales->keys()->merge($returns->keys())->unique()->sort()->values();

        return $periods->map(function ($p) use ($sales, $returns) {
            $rev = Money::sub(Money::of((string) ($sales[$p]->revenue ?? 0)), Money::of((string) ($returns[$p]->value ?? 0)));
            $cost = Money::sub(Money::of((string) ($sales[$p]->cost ?? 0)), Money::of((string) ($returns[$p]->cost ?? 0)));

            return ['period' => $p, 'count' => (int) ($sales[$p]->count ?? 0), 'revenue' => $rev, 'cogs' => $cost, 'gross_profit' => Money::sub($rev, $cost)];
        })->all();
    }

    public function topProducts(CarbonImmutable $from, CarbonImmutable $to, int $limit = 10): array
    {
        // Aggregate on the covering indexes by id only, then attach names for the few winners.
        $rows = DB::table('sales as s')->join('sale_items as i', 'i.sale_id', '=', 's.id')
            ->where('s.status', 'completed')->whereBetween('s.completed_at', [$from, $to])
            ->groupBy('i.product_id')
            ->selectRaw('i.product_id, SUM(i.quantity - i.returned_quantity) AS units, SUM(i.amount - i.tax) AS revenue, SUM(i.cost_total) AS cost')
            ->orderByDesc('units')->limit($limit)->get();
        $names = DB::table('products')->whereIn('id', $rows->pluck('product_id'))->get(['id', 'name', 'sku'])->keyBy('id');

        return $rows->map(fn ($r) => [
            'product_id' => $r->product_id, 'name' => $names[$r->product_id]->name ?? '(deleted)', 'sku' => $names[$r->product_id]->sku ?? '',
            'units' => (int) $r->units, 'revenue' => Money::of((string) $r->revenue), 'cost' => Money::of((string) $r->cost),
            'profit' => Money::sub(Money::of((string) $r->revenue), Money::of((string) $r->cost)),
        ])->all();
    }

    /**
     * Stock insights for the last $days days: what is left, what is running out (at the current
     * selling speed), what to reorder, what sells most, profit made and stock lost.
     * "Used" units come from stock movements, so a laptop sold inside a package counts too.
     */
    public function stockInsights(int $days, bool $financial): array
    {
        $from = CarbonImmutable::now()->subDays($days)->startOfDay();
        $to = CarbonImmutable::now();
        $soonDays = 14; // "running out" = less than two weeks left at the current speed

        // Units that left the shelf, net of cancellations and returns put back on the shelf.
        $used = DB::table('stock_movements')->where('created_at', '>=', $from)
            ->whereIn('type', ['sale', 'order_cancel', 'sale_return'])
            ->groupBy('product_id')->selectRaw('product_id, -SUM(quantity) AS units')->pluck('units', 'product_id');

        // Money per item sold (completed sales; returned units taken out proportionally).
        $sold = DB::table('sales as s')->join('sale_items as i', 'i.sale_id', '=', 's.id')
            ->where('s.status', 'completed')->whereBetween('s.completed_at', [$from, $to])->where('i.quantity', '>', 0)
            ->groupBy('i.product_id')
            ->selectRaw('i.product_id, SUM(i.quantity - i.returned_quantity) AS units,
                SUM((i.amount - i.tax) * (i.quantity - i.returned_quantity) / i.quantity) AS revenue,
                SUM(i.cost_total * (i.quantity - i.returned_quantity) / i.quantity) AS cost')
            ->get()->keyBy('product_id');

        $products = DB::table('products')->whereNull('archived_at')->where('is_active', true)
            ->get(['id', 'type', 'name', 'sku', 'image_path', 'total_stock', 'min_stock', 'cost_price', 'selling_price', 'last_sold_at']);
        $names = $products->keyBy('id');

        $rows = [];
        $sum = ['products' => 0, 'units' => 0, 'cost_value' => '0', 'retail_value' => '0', 'out_of_stock' => 0, 'low_stock' => 0, 'running_out' => 0, 'not_selling' => 0];
        foreach ($products as $p) {
            if ($p->type !== 'standard') {
                continue; // packages have no shelf stock of their own
            }
            $stock = (int) $p->total_stock;
            $usedUnits = max(0, (int) ($used[$p->id] ?? 0));
            $perDay = $usedUnits / $days;
            $daysLeft = $perDay > 0 ? (int) floor(max(0, $stock) / $perDay) : null;
            $status = match (true) {
                $stock <= 0 => 'out',
                $stock <= (int) $p->min_stock => 'low',
                $daysLeft !== null && $daysLeft < $soonDays => 'running_out',
                $usedUnits === 0 => 'not_selling',
                default => 'ok',
            };
            // Reorder enough for about 30 days of sales, and at least back to twice the alert level.
            $suggest = in_array($status, ['out', 'low', 'running_out'], true)
                ? max((int) ceil($perDay * 30) - max(0, $stock), (int) $p->min_stock * 2 - max(0, $stock), 1) : 0;
            $value = Money::mul((string) $p->cost_price, (string) max(0, $stock));
            $sum['products']++;
            $sum['units'] += max(0, $stock);
            $sum['cost_value'] = Money::add($sum['cost_value'], $value);
            $sum['retail_value'] = Money::add($sum['retail_value'], Money::mul((string) $p->selling_price, (string) max(0, $stock)));
            $countKey = ['out' => 'out_of_stock', 'low' => 'low_stock', 'running_out' => 'running_out', 'not_selling' => 'not_selling'][$status] ?? null;
            if ($countKey) {
                $sum[$countKey]++;
            }
            $rows[] = [
                'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'image' => $p->image_path,
                'stock' => $stock, 'min_stock' => (int) $p->min_stock, 'used' => $usedUnits,
                'per_day' => round($perDay, 2), 'days_left' => $daysLeft, 'status' => $status, 'reorder' => $suggest,
                'stock_value' => $financial ? $value : null, 'last_sold' => $p->last_sold_at,
            ];
        }

        $restock = collect($rows)->filter(fn ($r) => $r['reorder'] > 0)
            ->sortBy(fn ($r) => [$r['stock'] > 0 ? 1 : 0, $r['days_left'] ?? 9999, $r['stock']])->values()->all();
        $mostUsed = collect($rows)->filter(fn ($r) => $r['used'] > 0)->sortByDesc('used')->take(10)->values()->all();

        $out = [
            'days' => $days, 'from' => $from->toDateString(), 'soon_days' => $soonDays, 'financial' => $financial,
            'summary' => $sum, 'products' => $rows, 'restock' => $restock, 'most_used' => $mostUsed,
        ];
        if (! $financial) {
            unset($out['summary']['cost_value'], $out['summary']['retail_value']);

            return $out;
        }

        // Profit per item sold (packages included), best first.
        $out['most_profitable'] = $sold->map(function ($r) use ($names) {
            $revenue = Money::of((string) $r->revenue);
            $cost = Money::of((string) $r->cost);

            return ['id' => $r->product_id, 'name' => $names[$r->product_id]->name ?? '(deleted)', 'units' => (int) $r->units,
                'revenue' => $revenue, 'profit' => Money::sub($revenue, $cost)];
        })->sortByDesc(fn ($r) => (float) $r['profit'])->take(10)->values()->all();

        $pl = $this->profitAndLoss($from, $to);
        $out['profit'] = ['revenue' => $pl['net_revenue'], 'cogs' => $pl['cogs'], 'gross_profit' => $pl['gross_profit'],
            'margin_pct' => $pl['gross_margin_pct'], 'expenses' => $pl['expenses'], 'net_profit' => $pl['net_profit'], 'sales_count' => $pl['sales_count']];

        // Losses: stock written off (damaged, missing, count/adjustment shortfalls) ...
        $writeOffs = DB::table('stock_movements')->where('created_at', '>=', $from)->where('quantity', '<', 0)
            ->whereIn('type', ['damaged', 'missing', 'count', 'adjustment'])
            ->groupBy('product_id', 'type')->selectRaw('product_id, type, -SUM(quantity) AS units, SUM(-quantity * unit_cost) AS value')->get()
            ->map(fn ($r) => ['id' => $r->product_id, 'name' => $names[$r->product_id]->name ?? '(deleted)', 'reason' => $r->type,
                'units' => (int) $r->units, 'value' => Money::of((string) $r->value)])->sortByDesc(fn ($r) => (float) $r['value'])->values();
        // ... items sold for less than they cost ...
        $belowCost = DB::table('sales as s')->join('sale_items as i', 'i.sale_id', '=', 's.id')
            ->where('s.status', 'completed')->whereBetween('s.completed_at', [$from, $to])
            ->whereRaw('(i.amount - i.tax) < i.cost_total')
            ->groupBy('i.product_id')->selectRaw('i.product_id, SUM(i.quantity) AS units, SUM(i.cost_total - (i.amount - i.tax)) AS value')->get()
            ->map(fn ($r) => ['id' => $r->product_id, 'name' => $names[$r->product_id]->name ?? '(deleted)', 'units' => (int) $r->units,
                'value' => Money::of((string) $r->value)])->sortByDesc(fn ($r) => (float) $r['value'])->values();
        // ... and stock found (counts/adjustments that added stock), shown separately, never hidden in profit.
        $found = DB::table('stock_movements')->where('created_at', '>=', $from)->where('quantity', '>', 0)
            ->whereIn('type', ['count', 'adjustment'])->selectRaw('COALESCE(SUM(quantity * unit_cost),0) AS value, COALESCE(SUM(quantity),0) AS units')->first();

        $writeOffTotal = $writeOffs->reduce(fn ($c, $r) => Money::add($c, $r['value']), '0');
        $belowTotal = $belowCost->reduce(fn ($c, $r) => Money::add($c, $r['value']), '0');
        $out['losses'] = [
            'total' => Money::add($writeOffTotal, $belowTotal),
            'write_offs' => $writeOffs->all(), 'write_off_total' => $writeOffTotal,
            'below_cost' => $belowCost->all(), 'below_cost_total' => $belowTotal,
            'returns_value' => $pl['returns_value'], 'returns_count' => $pl['returns_count'],
            'found_value' => Money::of((string) $found->value), 'found_units' => (int) $found->units,
        ];

        return $out;
    }

    /** In stock but not sold in the last $days days, largest tied-up capital first. */
    public function slowMoving(int $days = 30, int $limit = 20): array
    {
        $since = now()->subDays($days);

        return DB::table('products as p')->where('p.type', 'standard')->whereNull('p.archived_at')->where('p.total_stock', '>', 0)
            ->where(fn ($w) => $w->whereNull('p.last_sold_at')->orWhere('p.last_sold_at', '<', $since))
            ->selectRaw('p.id, p.name, p.sku, p.total_stock, p.cost_price, (p.total_stock * p.cost_price) AS capital, p.last_sold_at AS last_sold')
            ->orderByDesc('capital')->limit($limit)->get()->all();
    }

    public function cashierPerformance(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('sales as s')->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.status', 'completed')->where('s.channel', 'pos')->whereBetween('s.completed_at', [$from, $to])
            ->groupBy('u.id', 'u.name')
            ->selectRaw('u.id, u.name, COUNT(*) AS sales, SUM(s.total) AS total, SUM(s.discount_total) AS discounts,
                SUM(s.refunded_total) AS refunded, AVG(s.total) AS average')
            ->orderByDesc('total')->get()->all();
    }

    /**
     * Cross-checks every denormalised figure against the rows it is derived from.
     * An empty 'problems' list means stock and money tie out.
     */
    public function reconcile(): array
    {
        $problems = [];
        // Each check aggregates its detail table once (derived table), then compares.
        foreach (DB::select('SELECT p.sku, p.total_stock, COALESCE(l.levels,0) AS levels FROM products p
            LEFT JOIN (SELECT product_id, SUM(quantity) AS levels FROM stock_levels GROUP BY product_id) l ON l.product_id = p.id
            WHERE p.type = "standard" AND p.total_stock <> COALESCE(l.levels,0)') as $r) {
            $problems[] = "Product {$r->sku}: total_stock {$r->total_stock} ≠ location sum {$r->levels}";
        }
        foreach (DB::select('SELECT s.product_id, s.location_id, s.quantity, COALESCE(m.moved,0) AS moved FROM stock_levels s
            LEFT JOIN (SELECT product_id, location_id, SUM(quantity) AS moved FROM stock_movements GROUP BY product_id, location_id) m
              ON m.product_id = s.product_id AND m.location_id = s.location_id
            WHERE s.quantity <> COALESCE(m.moved,0)') as $r) {
            $problems[] = "Stock level product {$r->product_id}@{$r->location_id}: {$r->quantity} ≠ movements {$r->moved}";
        }
        foreach (DB::select('SELECT s.number, s.total, s.delivery_fee, COALESCE(i.items,0) AS items FROM sales s
            LEFT JOIN (SELECT sale_id, SUM(amount) AS items FROM sale_items GROUP BY sale_id) i ON i.sale_id = s.id
            WHERE s.total <> COALESCE(i.items,0) + s.delivery_fee') as $r) {
            $problems[] = "Sale {$r->number}: total {$r->total} ≠ lines {$r->items} + delivery {$r->delivery_fee}";
        }
        foreach (DB::select("SELECT s.number, s.amount_paid, COALESCE(p.net,0) AS net FROM sales s
            LEFT JOIN (SELECT sale_id, SUM(CASE WHEN kind='payment' THEN amount ELSE -amount END) AS net FROM payments GROUP BY sale_id) p ON p.sale_id = s.id
            WHERE s.amount_paid <> COALESCE(p.net,0)") as $r) {
            $problems[] = "Sale {$r->number}: amount_paid {$r->amount_paid} ≠ payments {$r->net}";
        }
        foreach (DB::select("SELECT number, total, refunded_total, amount_paid, balance_due FROM sales
            WHERE status <> 'cancelled' AND balance_due <> GREATEST(0, total - refunded_total - amount_paid)") as $r) {
            $problems[] = "Sale {$r->number}: balance_due {$r->balance_due} inconsistent";
        }
        foreach (DB::select('SELECT s.number, s.cost_total, COALESCE(i.items,0) AS items FROM sales s
            LEFT JOIN (SELECT sale_id, SUM(cost_total) AS items FROM sale_items GROUP BY sale_id) i ON i.sale_id = s.id
            WHERE s.cost_total <> COALESCE(i.items,0)') as $r) {
            $problems[] = "Sale {$r->number}: cost_total {$r->cost_total} ≠ line costs {$r->items}";
        }
        foreach (DB::select('SELECT i.id, i.quantity, i.returned_quantity, COALESCE(r.ret,0) AS ret FROM sale_items i
            LEFT JOIN (SELECT sale_item_id, SUM(quantity) AS ret FROM sale_return_items GROUP BY sale_item_id) r ON r.sale_item_id = i.id
            WHERE i.returned_quantity <> COALESCE(r.ret,0) OR COALESCE(r.ret,0) > i.quantity') as $r) {
            $problems[] = "Sale item {$r->id}: returned {$r->returned_quantity} vs return rows {$r->ret} (sold {$r->quantity})";
        }

        return ['ok' => ! $problems, 'problems' => $problems, 'checked_at' => now()->toIso8601String()];
    }
}
