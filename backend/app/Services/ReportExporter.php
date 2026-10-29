<?php

namespace App\Services;

use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Turns a report into rows and writes CSV / XLSX / PDF. Row sources use cursors (lazy)
 * so a large export does not load the whole table into memory.
 */
class ReportExporter
{
    public const REPORTS = [
        'sales' => 'financial', 'sale_items' => 'financial', 'profit_loss' => 'financial', 'cash_flow' => 'financial',
        'inventory' => 'financial', 'stock_movements' => 'operational', 'low_stock' => 'operational',
        'top_products' => 'financial', 'slow_moving' => 'operational', 'purchases' => 'financial',
        'supplier_balances' => 'financial', 'customer_debts' => 'financial', 'expenses' => 'financial',
        'cashier_performance' => 'financial', 'returns' => 'financial',
    ];

    public function __construct(private ReportService $reports) {}

    /** @return array{0: list<string>, 1: iterable<array>, 2: string} headers, rows, title */
    public function source(string $report, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $range = [$from, $to];
        $dates = [$from->toDateString(), $to->toDateString()];

        return match ($report) {
            'sales' => [
                ['Number', 'Date', 'Channel', 'Status', 'Customer', 'Cashier', 'Subtotal', 'Discount', 'Tax', 'Total', 'Paid', 'Balance', 'Refunded', 'COGS'],
                DB::table('sales as s')->leftJoin('customers as c', 'c.id', '=', 's.customer_id')->leftJoin('users as u', 'u.id', '=', 's.user_id')
                    ->whereBetween('s.created_at', $range)->orderBy('s.id')
                    ->select('s.number', 's.created_at', 's.channel', 's.status', 'c.name as customer', 'u.name as cashier', 's.subtotal', 's.discount_total',
                        's.tax_total', 's.total', 's.amount_paid', 's.balance_due', 's.refunded_total', 's.cost_total')->cursor(),
                'Sales',
            ],
            'sale_items' => [
                ['Sale', 'Date', 'SKU', 'Product', 'Qty', 'Returned', 'Unit price', 'Discount', 'Amount', 'Cost'],
                DB::table('sale_items as i')->join('sales as s', 's.id', '=', 'i.sale_id')->where('s.status', 'completed')
                    ->whereBetween('s.completed_at', $range)->orderBy('i.id')
                    ->select('s.number', 's.completed_at', 'i.sku', 'i.name', 'i.quantity', 'i.returned_quantity', 'i.unit_price', 'i.discount', 'i.amount', 'i.cost_total')->cursor(),
                'Items sold',
            ],
            'profit_loss' => [
                ['Line', 'Amount'],
                collect($this->reports->profitAndLoss($from, $to))->except(['from', 'to'])->map(fn ($v, $k) => [ucwords(str_replace('_', ' ', $k)), $v])->values(),
                'Profit and loss',
            ],
            'cash_flow' => [
                ['Method', 'Received', 'Refunded', 'Expenses', 'Supplier payments', 'Net'],
                collect($this->reports->cashFlow($from, $to)['methods'])->map(fn ($r) => array_values($r)),
                'Cash flow',
            ],
            'inventory' => [
                ['SKU', 'Product', 'Units', 'Avg cost', 'Cost value', 'Price', 'Retail value'],
                DB::table('products')->where('type', 'standard')->whereNull('archived_at')->orderBy('name')
                    ->selectRaw('sku, name, total_stock, cost_price, total_stock * cost_price AS cost_value, selling_price, total_stock * selling_price AS retail_value')->cursor(),
                'Inventory valuation',
            ],
            'stock_movements' => [
                ['Date', 'SKU', 'Product', 'Location', 'Type', 'Qty', 'Balance', 'Unit cost', 'Reason', 'User'],
                DB::table('stock_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')->join('locations as l', 'l.id', '=', 'm.location_id')
                    ->leftJoin('users as u', 'u.id', '=', 'm.user_id')->whereBetween('m.created_at', $range)->orderBy('m.id')
                    ->select('m.created_at', 'p.sku', 'p.name', 'l.code', 'm.type', 'm.quantity', 'm.balance_after', 'm.unit_cost', 'm.reason', 'u.name as user')->cursor(),
                'Stock movements',
            ],
            'low_stock' => [
                ['SKU', 'Product', 'In stock', 'Minimum', 'Avg cost'],
                DB::table('products')->where('type', 'standard')->whereNull('archived_at')->where('is_active', true)->whereColumn('total_stock', '<=', 'min_stock')
                    ->orderBy('total_stock')->select('sku', 'name', 'total_stock', 'min_stock', 'cost_price')->cursor(),
                'Low and out of stock',
            ],
            'top_products' => [
                ['Product id', 'Name', 'SKU', 'Units', 'Revenue', 'Cost', 'Profit'],
                $this->reports->topProducts($from, $to, 500),
                'Best sellers',
            ],
            'slow_moving' => [
                ['Id', 'Name', 'SKU', 'Stock', 'Avg cost', 'Capital', 'Last sold'],
                $this->reports->slowMoving(max(1, $from->diffInDays($to)), 500),
                'Slow moving stock',
            ],
            'purchases' => [
                ['GRN', 'Date', 'Supplier', 'Invoice', 'Total'],
                DB::table('goods_receipts as g')->leftJoin('suppliers as s', 's.id', '=', 'g.supplier_id')->whereBetween('g.received_date', $dates)
                    ->orderBy('g.id')->select('g.number', 'g.received_date', 's.name', 'g.supplier_invoice_no', 'g.total')->cursor(),
                'Purchases',
            ],
            'supplier_balances' => [
                ['Supplier', 'Phone', 'Opening', 'Purchases', 'Paid', 'Balance'],
                DB::table('suppliers as s')->whereNull('s.archived_at')->orderBy('s.name')->selectRaw('s.name, s.phone, s.opening_balance,
                    COALESCE((SELECT SUM(total) FROM goods_receipts g WHERE g.supplier_id = s.id),0) AS purchases,
                    COALESCE((SELECT SUM(amount) FROM supplier_payments p WHERE p.supplier_id = s.id),0) AS paid,
                    s.opening_balance + COALESCE((SELECT SUM(total) FROM goods_receipts g WHERE g.supplier_id = s.id),0)
                    - COALESCE((SELECT SUM(amount) FROM supplier_payments p WHERE p.supplier_id = s.id),0) AS balance')->cursor(),
                'Supplier balances',
            ],
            'customer_debts' => [
                ['Customer', 'Phone', 'Open sales', 'Balance', 'Oldest'],
                DB::table('sales as s')->join('customers as c', 'c.id', '=', 's.customer_id')->where('s.status', 'completed')->where('s.balance_due', '>', 0)
                    ->groupBy('c.id', 'c.name', 'c.phone')->orderByDesc(DB::raw('SUM(s.balance_due)'))
                    ->selectRaw('c.name, c.phone, COUNT(*) AS sales, SUM(s.balance_due) AS balance, MIN(s.created_at) AS oldest')->cursor(),
                'Customer debts',
            ],
            'expenses' => [
                ['Date', 'Category', 'Description', 'Method', 'Amount', 'Recorded by'],
                DB::table('expenses as e')->join('expense_categories as c', 'c.id', '=', 'e.expense_category_id')
                    ->join('payment_methods as m', 'm.id', '=', 'e.payment_method_id')->leftJoin('users as u', 'u.id', '=', 'e.user_id')
                    ->whereNull('e.voided_at')->whereBetween('e.expense_date', $dates)->orderBy('e.expense_date')
                    ->select('e.expense_date', 'c.name as category', 'e.description', 'm.name as method', 'e.amount', 'u.name as user')->cursor(),
                'Expenses',
            ],
            'cashier_performance' => [
                ['User id', 'Cashier', 'Sales', 'Total', 'Discounts', 'Refunded', 'Average'],
                $this->reports->cashierPerformance($from, $to),
                'Cashier performance',
            ],
            'returns' => [
                ['Return', 'Date', 'Sale', 'Reason', 'Value', 'Cash refunded', 'Cost reversed', 'By'],
                DB::table('sale_returns as r')->join('sales as s', 's.id', '=', 'r.sale_id')->leftJoin('users as u', 'u.id', '=', 'r.user_id')
                    ->whereBetween('r.created_at', $range)->orderBy('r.id')
                    ->select('r.number', 'r.created_at', 's.number as sale', 'r.reason', 'r.refund_total', 'r.cash_refunded', 'r.cost_reversed', 'u.name as user')->cursor(),
                'Returns and refunds',
            ],
            default => throw new \InvalidArgumentException("Unknown report {$report}"),
        };
    }

    /** Writes the file to the local disk and returns [path, rowCount]. */
    public function write(string $report, string $format, CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$headers, $rows, $title] = $this->source($report, $from, $to);
        $name = 'exports/'.$report.'_'.$from->format('Ymd').'-'.$to->format('Ymd').'_'.uniqid().'.'.$format;
        Storage::disk('local')->makeDirectory('exports');
        $full = Storage::disk('local')->path($name);
        $count = 0;
        $norm = fn ($r) => array_map(fn ($v) => $v === null ? '' : (string) $v, array_values((array) $r));

        if ($format === 'csv') {
            $fh = fopen($full, 'w');
            fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens accents correctly
            fputcsv($fh, $headers);
            foreach ($rows as $r) {
                fputcsv($fh, $this->safeCsv($norm($r)));
                $count++;
            }
            fclose($fh);
        } elseif ($format === 'xlsx') {
            $w = new XlsxWriter;
            $w->openToFile($full);
            $w->addRow(Row::fromValues($headers));
            foreach ($rows as $r) {
                $w->addRow(Row::fromValues(array_map(fn ($v) => is_numeric($v) && ! preg_match('/^0\d/', $v) ? $v + 0 : $v, $norm($r))));
                $count++;
            }
            $w->close();
        } else {
            $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:9px}h1{font-size:14px;margin:0}
                table{width:100%;border-collapse:collapse;margin-top:8px}th,td{border:1px solid #ccc;padding:3px 4px;text-align:left}th{background:#f3f3f3}</style></head><body>';
            $html .= '<h1>'.e(Settings::get('shop_name')).': '.e($title).'</h1><div>'.e($from->toDateString()).' to '.e($to->toDateString()).' · generated '.e(now()->toDateTimeString()).'</div>';
            $html .= '<table><thead><tr>'.implode('', array_map(fn ($h) => '<th>'.e($h).'</th>', $headers)).'</tr></thead><tbody>';
            foreach ($rows as $r) {
                $html .= '<tr>'.implode('', array_map(fn ($v) => '<td>'.e($v).'</td>', $norm($r))).'</tr>';
                if (++$count >= 5000) {
                    $html .= '<tr><td colspan="'.count($headers).'">Truncated at 5000 rows. Use CSV or Excel for the full data.</td></tr>';
                    break;
                }
            }
            $html .= '</tbody></table></body></html>';
            $pdf = new Dompdf(['defaultFont' => 'DejaVu Sans']);
            $pdf->loadHtml($html);
            $pdf->setPaper('A4', count($headers) > 7 ? 'landscape' : 'portrait');
            $pdf->render();
            file_put_contents($full, $pdf->output());
        }

        return [$name, $count];
    }

    /** Neutralise spreadsheet formula injection in exported text cells. */
    private function safeCsv(array $row): array
    {
        return array_map(fn ($v) => preg_match('/^[=+\-@\t\r]/', $v) && ! is_numeric($v) ? "'".$v : $v, $row);
    }

    public static function estimateRows(string $report, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return match ($report) {
            'sales' => DB::table('sales')->whereBetween('created_at', [$from, $to])->count(),
            'sale_items' => DB::table('sale_items as i')->join('sales as s', 's.id', '=', 'i.sale_id')->whereBetween('s.completed_at', [$from, $to])->count(),
            'stock_movements' => DB::table('stock_movements')->whereBetween('created_at', [$from, $to])->count(),
            'inventory' => DB::table('products')->count(),
            default => 0,
        };
    }
}
