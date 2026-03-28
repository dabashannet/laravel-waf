<?php

namespace Dabashan\BtLaravelWaf\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MonitorService
{
    public function recordSlowRequest(array $data): void
    {
        $key  = 'dbswaf:slow_requests:' . date('YmdH');
        $list = Cache::get($key, []);

        if (count($list) >= 100) {
            array_shift($list);
        }

        $list[] = $data;
        Cache::put($key, $list, now()->addHours(2));
    }

    public function recordError(array $data): void
    {
        $key  = 'dbswaf:errors:' . date('YmdH');
        $list = Cache::get($key, []);

        if (count($list) >= 200) {
            array_shift($list);
        }

        $list[] = $data;
        Cache::put($key, $list, now()->addHours(2));
    }

    public function incrementStats(string $method, int $statusCode, int $duration): void
    {
        $hourKey = 'dbswaf:stats:' . date('YmdH');

        try {
            $stats = Cache::get($hourKey, [
                'total'      => 0,
                'errors_4xx' => 0,
                'errors_5xx' => 0,
                'total_time' => 0,
                'max_time'   => 0,
            ]);

            $stats['total']++;
            $stats['total_time'] += $duration;

            if ($statusCode >= 500) {
                $stats['errors_5xx']++;
            } elseif ($statusCode >= 400) {
                $stats['errors_4xx']++;
            }

            if ($duration > $stats['max_time']) {
                $stats['max_time'] = $duration;
            }

            Cache::put($hourKey, $stats, now()->addHours(2));
        } catch (\Throwable $e) {
            // 统计失败静默处理
        }
    }

    public function getHourlyStats(?string $hour = null): array
    {
        $hour  = $hour ?? date('YmdH');
        $stats = Cache::get('dbswaf:stats:' . $hour, []);

        if (empty($stats)) {
            return [
                'total'       => 0,
                'errors_4xx'  => 0,
                'errors_5xx'  => 0,
                'avg_time_ms' => 0,
                'max_time_ms' => 0,
                'error_rate'  => '0%',
            ];
        }

        $total     = max($stats['total'], 1);
        $errorRate = round(($stats['errors_4xx'] + $stats['errors_5xx']) / $total * 100, 2);
        $avgTime   = round($stats['total_time'] / $total, 0);

        return [
            'total'       => $stats['total'],
            'errors_4xx'  => $stats['errors_4xx'],
            'errors_5xx'  => $stats['errors_5xx'],
            'avg_time_ms' => $avgTime,
            'max_time_ms' => $stats['max_time'],
            'error_rate'  => $errorRate . '%',
        ];
    }

    public function getSlowRequests(int $limit = 20): array
    {
        $key  = 'dbswaf:slow_requests:' . date('YmdH');
        $list = Cache::get($key, []);
        return array_slice(array_reverse($list), 0, $limit);
    }

    public function getRecentErrors(int $limit = 20): array
    {
        $key  = 'dbswaf:errors:' . date('YmdH');
        $list = Cache::get($key, []);
        return array_slice(array_reverse($list), 0, $limit);
    }

    public function getHealthStatus(): array
    {
        $stats     = $this->getHourlyStats();
        $errorRate = (float) rtrim($stats['error_rate'], '%');
        $avgTime   = $stats['avg_time_ms'];

        $status = 'healthy';
        $issues = [];

        if ($errorRate > 10) {
            $status   = 'degraded';
            $issues[] = "错误率过高: {$stats['error_rate']}";
        }

        if ($errorRate > 30) {
            $status = 'unhealthy';
        }

        if ($avgTime > config('dbswaf_monitor.slow_threshold', 3000)) {
            $status   = 'degraded';
            $issues[] = "平均响应时间过长: {$avgTime}ms";
        }

        return [
            'status'         => $status,
            'issues'         => $issues,
            'stats'          => $stats,
            'slow_requests'  => count($this->getSlowRequests()),
            'checked_at'     => now()->toIso8601String(),
            'uptime'         => $this->getUptime(),
            'php_version'    => PHP_VERSION,
            'laravel_version' => app()->version(),
        ];
    }

    protected function getUptime(): string
    {
        if (PHP_OS_FAMILY === 'Linux' && file_exists('/proc/uptime')) {
            $uptime = (int) explode(' ', file_get_contents('/proc/uptime'))[0];
            return $this->formatDuration($uptime);
        }
        return 'N/A';
    }

    protected function formatDuration(int $seconds): string
    {
        $days    = floor($seconds / 86400);
        $hours   = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        if ($days > 0) {
            return "{$days}天 {$hours}时 {$minutes}分";
        }

        if ($hours > 0) {
            return "{$hours}时 {$minutes}分";
        }

        return "{$minutes}分";
    }

    public function reportToWafPanel(): bool
    {
        $wafServer = config('dbswaf.waf_server', '');
        if (empty($wafServer)) {
            return false;
        }

        try {
            $payload = [
                'type'   => 'monitor_report',
                'data'   => $this->getHealthStatus(),
                'source' => 'laravel',
                'domain' => config('app.url', ''),
            ];

            $pendingReports = Cache::get('dbswaf:pending_reports', []);
            if (!empty($pendingReports)) {
                $payload['attacks'] = $pendingReports;
                Cache::forget('dbswaf:pending_reports');
            }

            $response = Http::timeout(5)
                ->post("http://{$wafServer}/api/laravel/report", $payload);

            if ($response->successful()) {
                Log::info('[DBSWAF] 监控数据上报成功');
                return true;
            }

            Log::warning('[DBSWAF] 监控数据上报失败: HTTP ' . $response->status());
            return false;
        } catch (\Throwable $e) {
            Log::debug('[DBSWAF] 监控上报异常: ' . $e->getMessage());
            return false;
        }
    }

    public function getMetrics(): array
    {
        $hours = [];
        for ($i = 0; $i < 24; $i++) {
            $hour         = date('YmdH', strtotime("-{$i} hours"));
            $hours[$hour] = $this->getHourlyStats($hour);
        }

        return [
            'hourly_stats'  => $hours,
            'slow_requests' => $this->getSlowRequests(50),
            'recent_errors' => $this->getRecentErrors(50),
            'health'        => $this->getHealthStatus(),
            'generated_at'  => now()->toIso8601String(),
        ];
    }
}
