<?php

namespace App\Jobs;

use App\Services\ReportExporter;
use App\Services\ReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(public int $exportId) {}

    public function handle(ReportExporter $exporter): void
    {
        $row = DB::table('report_exports')->find($this->exportId);
        if (! $row || $row->status === 'done') {
            return;
        }
        DB::table('report_exports')->where('id', $row->id)->update(['status' => 'running', 'updated_at' => now()]);
        try {
            $p = json_decode($row->params, true) ?: [];
            [$from, $to] = ReportService::range($p['from'] ?? null, $p['to'] ?? null);
            [$path, $count] = $exporter->write($row->report, $row->format, $from, $to);
            DB::table('report_exports')->where('id', $row->id)->update(['status' => 'done', 'path' => $path, 'rows' => $count, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
            DB::table('report_exports')->where('id', $row->id)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250), 'updated_at' => now()]);
        }
    }
}
