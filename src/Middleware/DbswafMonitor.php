<?php

namespace Dabashan\BtLaravelWaf\Middleware;

use Closure;
use Dabashan\BtLaravelWaf\Services\MonitorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DbswafMonitor
{
    public function __construct(
        protected MonitorService $monitor
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startTime   = microtime(true);
        $startMemory = memory_get_usage(true);

        $queryLog = config('dbswaf_monitor.log_queries', false);
        if ($queryLog) {
            DB::enableQueryLog();
        }

        $response = $next($request);

        $duration   = (int) round((microtime(true) - $startTime) * 1000);
        $memoryUsed = memory_get_usage(true) - $startMemory;

        try {
            $this->recordMetrics($request, $response, $duration, $memoryUsed, $queryLog);
        } catch (\Throwable $e) {
            Log::debug('[DBSWAF MONITOR] 指标记录失败: ' . $e->getMessage());
        }

        if (config('app.debug') && !$response->isRedirection()) {
            $response->headers->set('X-DBSWAF-Time', $duration . 'ms');
        }

        return $response;
    }

    protected function recordMetrics(
        Request  $request,
        Response $response,
        int      $duration,
        int      $memoryUsed,
        bool     $queryLog
    ): void {
        $statusCode = $response->getStatusCode();
        $path       = $request->path();
        $method     = $request->method();
        $ip         = $request->ip();

        // 慢请求记录
        $slowThreshold = config('dbswaf_monitor.slow_threshold', 3000);
        if ($duration >= $slowThreshold) {
            Log::warning('[DBSWAF MONITOR] 慢请求检测', [
                'method'   => $method,
                'path'     => $path,
                'ip'       => $ip,
                'duration' => $duration . 'ms',
                'memory'   => round($memoryUsed / 1024 / 1024, 2) . 'MB',
                'status'   => $statusCode,
            ]);

            $this->monitor->recordSlowRequest([
                'path'      => $path,
                'method'    => $method,
                'duration'  => $duration,
                'memory'    => $memoryUsed,
                'status'    => $statusCode,
                'timestamp' => time(),
            ]);
        }

        // 错误响应记录（4xx/5xx）
        if ($statusCode >= 400) {
            $this->monitor->recordError([
                'path'      => $path,
                'method'    => $method,
                'status'    => $statusCode,
                'ip'        => $ip,
                'timestamp' => time(),
            ]);
        }

        // 慢 SQL 记录
        if ($queryLog) {
            $queries        = DB::getQueryLog();
            $queryThreshold = config('dbswaf_monitor.query_threshold', 1000);

            foreach ($queries as $query) {
                if ($query['time'] >= $queryThreshold) {
                    Log::warning('[DBSWAF MONITOR] 慢 SQL 检测', [
                        'sql'      => $query['query'],
                        'bindings' => $query['bindings'],
                        'time'     => $query['time'] . 'ms',
                        'path'     => $path,
                    ]);
                }
            }

            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->monitor->incrementStats($method, $statusCode, $duration);
    }
}
