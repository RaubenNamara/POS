<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReportExport;
use App\Models\FeedNotification;
use App\Models\GoodsReceipt;
use App\Models\Sale;
use App\Services\ReportExporter;
use App\Services\ReportService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    /**
     * Owner/manager dashboard. Financial figures (capital, profit) only for reports.financial;
     * everything is computed live from the tables (indexed aggregates, no fabricated numbers).
     */
    public function dashboard(Request $request)
    {
        // Cached per view, keyed on the newest transaction ids: any sale, order update, return,
        // expense, payment or stock movement changes the key, so cached figures are never stale.
        $key = 'dashboard:'.($request->user()->can('reports.financial') ? 'fin' : 'ops').':'.now()->toDateString().':'.implode('-', [
            DB::table('sales')->max('id'), DB::table('sales')->max('updated_at'), DB::table('sale_returns')->max('id'), DB::table('expenses')->max('updated_at'),
            DB::table('payments')->max('id'), DB::table('stock_movements')->max('id'), DB::table('supplier_payments')->max('id'), DB::table('notifications_feed')->max('id'),
            DB::table('notifications_feed')->whereNull('read_at')->count(), DB::table('offline_conflicts')->max('updated_at'),
        ]);

        return \Illuminate\Support\Facades\Cache::remember('dash:'.md5($key), 300, fn () => $this->buildDashboard($request));
    }

    private function buildDashboard(Request $request): array
    {
        $user = $request->user();
        $now = CarbonImmutable::now();
        $financial = $user->can('reports.financial');
        $periods = [
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'week' => [$now->startOfWeek(), $now->endOfDay()],
            'month' => [$now->startOfMonth(), $now->endOfDay()],
        ];
        $summary = [];
        foreach ($periods as $k => [$f, $t]) {
            $pl = $this->reports->profitAndLoss($f, $t);
            $summary[$k] = $financial ? $pl : array_intersect_key($pl, array_flip(['sales_count', 'net_revenue', 'returns_count']));
        }
        $inv = $this->reports->inventoryValuation();
        if (! $financial) {
            $inv = array_intersect_key($inv, array_flip(['products', 'units', 'out_of_stock', 'low_stock']));
        }

        return [
            'generated_at' => $now->toDateTimeString(),
            'financial' => $financial,
            'summary' => $summary,
            'inventory' => $inv,
            'balances' => $financial ? $this->reports->balances() : null,
            'cash_flow_month' => $financial ? $this->reports->cashFlow(...$periods['month'])['totals'] : null,
            'series' => $this->reports->salesSeries($now->subDays(29)->startOfDay(), $now->endOfDay()),
            'top_products' => $this->reports->topProducts($now->subDays(29)->startOfDay(), $now->endOfDay(), 5),
            'low_stock' => DB::table('products')->where('type', 'standard')->whereNull('archived_at')->where('is_active', true)
                ->whereColumn('total_stock', '<=', 'min_stock')->orderBy('total_stock')->limit(8)->get(['id', 'name', 'sku', 'total_stock', 'min_stock']),
            'recent_sales' => Sale::with('customer:id,name')->orderByDesc('id')->limit(8)
                ->get(['id', 'number', 'channel', 'status', 'payment_status', 'total', 'customer_id', 'created_at']),
            'recent_purchases' => $user->can('purchases.receive') ? GoodsReceipt::with('supplier:id,name')->orderByDesc('id')->limit(5)
                ->get(['id', 'number', 'supplier_id', 'total', 'received_date']) : [],
            'pending_orders' => Sale::where('channel', 'online')->where('status', 'pending')->count(),
            'open_conflicts' => DB::table('offline_conflicts')->where('status', 'open')->count(),
            'unread_notifications' => FeedNotification::whereNull('read_at')->count(),
        ];
    }

    /** Cheap "has anything changed?" probe the dashboard polls (so a cashier's sale shows up without reloading). */
    public function pulse()
    {
        return [
            'last_sale_id' => (int) Sale::max('id'),
            'last_movement_id' => (int) DB::table('stock_movements')->max('id'),
            'unread_notifications' => FeedNotification::whereNull('read_at')->count(),
        ];
    }

    public function show(Request $request, string $report)
    {
        $v = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'group' => 'nullable|in:day,week,month,year', 'days' => 'nullable|integer|min:1|max:365']);
        $this->authorizeReport($request, $report);
        [$from, $to] = ReportService::range($v['from'] ?? null, $v['to'] ?? null);

        return match ($report) {
            'profit_loss' => $this->reports->profitAndLoss($from, $to),
            'cash_flow' => $this->reports->cashFlow($from, $to),
            'inventory' => $this->reports->inventoryValuation(),
            'balances' => $this->reports->balances(),
            'sales_series' => $this->reports->salesSeries($from, $to, $v['group'] ?? 'day'),
            'top_products' => $this->reports->topProducts($from, $to, 50),
            'slow_moving' => $this->reports->slowMoving($v['days'] ?? 30, 100),
            'stock_insights' => $this->reports->stockInsights($v['days'] ?? 30, $request->user()->can('reports.financial')),
            'cashier_performance' => $this->reports->cashierPerformance($from, $to),
            'reconcile' => $this->reports->reconcile(),
            default => $this->preview($report, $from, $to),
        };
    }

    /** First 200 rows of a tabular report, for on-screen viewing. */
    private function preview(string $report, CarbonImmutable $from, CarbonImmutable $to): array
    {
        abort_unless(isset(ReportExporter::REPORTS[$report]), 404);
        [$headers, $rows] = app(ReportExporter::class)->source($report, $from, $to);
        $out = [];
        foreach ($rows as $r) {
            $out[] = array_values((array) $r);
            if (count($out) >= 200) {
                break;
            }
        }

        return ['headers' => $headers, 'rows' => $out, 'truncated' => count($out) >= 200];
    }

    public function export(Request $request)
    {
        $v = $request->validate([
            'report' => 'required|in:'.implode(',', array_keys(ReportExporter::REPORTS)),
            'format' => 'required|in:csv,xlsx,pdf',
            'from' => 'nullable|date', 'to' => 'nullable|date',
        ]);
        $this->authorizeReport($request, $v['report']);
        [$from, $to] = ReportService::range($v['from'] ?? null, $v['to'] ?? null);
        $id = DB::table('report_exports')->insertGetId([
            'user_id' => $request->user()->id, 'report' => $v['report'], 'format' => $v['format'],
            'params' => json_encode(['from' => $from->toDateString(), 'to' => $to->toDateString()]),
            'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Small exports are produced immediately; big ones go to the queue worker.
        if (ReportExporter::estimateRows($v['report'], $from, $to) <= 3000) {
            GenerateReportExport::dispatchSync($id);
        } else {
            GenerateReportExport::dispatch($id);
        }

        return response()->json(DB::table('report_exports')->find($id), 202);
    }

    public function exportStatus(Request $request, int $id)
    {
        $row = DB::table('report_exports')->where('user_id', $request->user()->id)->findOrFail($id);
        unset($row->path);

        return response()->json($row);
    }

    public function download(Request $request, int $id)
    {
        $row = DB::table('report_exports')->where('user_id', $request->user()->id)->where('status', 'done')->find($id);
        abort_unless($row && Storage::disk('local')->exists($row->path), 404);

        return Storage::disk('local')->download($row->path, basename($row->path));
    }

    private function authorizeReport(Request $request, string $report): void
    {
        $level = ReportExporter::REPORTS[$report] ?? (in_array($report, ['inventory', 'slow_moving', 'stock_insights'], true) ? 'operational' : 'financial');
        abort_unless($request->user()->can('reports.'.$level) || $request->user()->can('reports.financial'), 403, 'Not allowed to view this report');
    }
}
