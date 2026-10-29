<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Debug-only profiling: adds a Server-Timing header (visible in the browser's Network tab)
 * with total time, DB time and query count, and logs any query slower than 100 ms.
 */
class ServerTiming
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.debug') && ! env('SERVER_TIMING')) {
            return $next($request);
        }
        $start = microtime(true);
        $db = 0.0;
        $count = 0;
        DB::listen(function ($q) use (&$db, &$count, $request) {
            $db += $q->time;
            $count++;
            if ($q->time > 100) {
                logger()->warning('Slow query', ['ms' => $q->time, 'sql' => $q->sql, 'url' => $request->path()]);
            }
        });
        $response = $next($request);
        $total = (microtime(true) - $start) * 1000;
        $response->headers->set('Server-Timing', sprintf('app;dur=%.1f, db;dur=%.1f;desc="%d queries"', $total, $db, $count));

        return $response;
    }
}
