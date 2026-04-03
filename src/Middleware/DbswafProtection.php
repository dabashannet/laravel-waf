<?php

namespace Dabashan\BtLaravelWaf\Middleware;

use Closure;
use Dabashan\BtLaravelWaf\Services\ThreatDetector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * DbswafProtection - 纯遥测中间件
 *
 * 职责（仅遥测，不拦截）：
 *   1. 始终放行所有请求（实际防护由 Nginx access_by_lua 层承担）
 *   2. 收集统一遥测数据（time、ip、domain、method、uri、status_code、response_time_ms）
 *   3. 调用 ThreatDetector 检测异常（仅记录，不拦截）
 *   4. 写入 /www/server/dbswaf/logs/telemetry_laravel_YYYYMMDD.log
 *
 * 兼容：Laravel 9 / 10 / 11 / 12
 */
class DbswafProtection
{
    /** @var array 内存中缓冲的遥测日志行，按日志文件路径分组 */
    private static array $telemetryBuffer = [];

    /** @var bool 是否已注册 shutdown 函数 */
    private static bool $shutdownRegistered = false;

    public function __construct(
        protected ThreatDetector $threatDetector
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!config('dbswaf.enabled', true)) {
            return $next($request);
        }

        $startTime = microtime(true);

        // 始终放行——实际防护由 Nginx Lua 层负责
        $response = $next($request);

        $this->collectTelemetry($request, $response, $startTime);

        return $response;
    }

    /**
     * 收集遥测数据（不阻断请求）
     */
    protected function collectTelemetry(Request $request, Response $response, float $startTime): void
    {
        try {
            $responseTimeMs = round((microtime(true) - $startTime) * 1000, 2);
            $statusCode     = $response->getStatusCode();

            $record = [
                'time'             => date('Y-m-d H:i:s'),
                'ip'               => $request->ip(),
                'domain'           => $request->getHost(),
                'method'           => $request->method(),
                'uri'              => $request->path() === '/' ? '/' : '/' . $request->path(),
                'status_code'      => $statusCode,
                'response_time_ms' => $responseTimeMs,
                'source'           => 'laravel-middleware',
            ];

            // 异常行为检测（仅标记，不拦截）
            $anomaly = $this->threatDetector->detectAnomalies($request);
            if ($anomaly !== null) {
                $record['anomaly'] = $anomaly;
                Log::debug('[DBSWAF] 异常请求遥测', [
                    'ip'     => $record['ip'],
                    'uri'    => $record['uri'],
                    'type'   => $anomaly['type'],
                    'severity' => $anomaly['severity'] ?? 'unknown',
                ]);
            }

            // 写入内存缓冲
            $wafDir  = config('dbswaf.waf_path', '/www/server/dbswaf');
            $logDir  = $wafDir . '/logs';
            $logFile = $logDir . '/telemetry_laravel_' . date('Ymd') . '.log';

            self::$telemetryBuffer[$logFile][] = json_encode($record, JSON_UNESCAPED_UNICODE);

            // 缓冲达到阈值时立即刷写
            $bufferSize = (int) config('dbswaf.telemetry_buffer_size', 50);
            if (count(self::$telemetryBuffer[$logFile]) >= $bufferSize) {
                $this->flushBuffer($logFile, $logDir);
            }

            // 注册进程结束时批量写入
            if (!self::$shutdownRegistered) {
                self::$shutdownRegistered = true;
                register_shutdown_function(static function () {
                    foreach (self::$telemetryBuffer as $file => $lines) {
                        if (empty($lines)) {
                            continue;
                        }
                        $dir = dirname($file);
                        if (!is_dir($dir)) {
                            @mkdir($dir, 0755, true);
                        }
                        @file_put_contents($file, implode("\n", $lines) . "\n", FILE_APPEND | LOCK_EX);
                    }
                    self::$telemetryBuffer = [];
                });
            }

        } catch (\Throwable $e) {
            // 遥测失败不影响业务
        }
    }

    /**
     * 将单个文件的缓冲立即刷写到磁盘
     */
    protected function flushBuffer(string $logFile, string $logDir): void
    {
        if (empty(self::$telemetryBuffer[$logFile])) {
            return;
        }

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        @file_put_contents(
            $logFile,
            implode("\n", self::$telemetryBuffer[$logFile]) . "\n",
            FILE_APPEND | LOCK_EX
        );

        self::$telemetryBuffer[$logFile] = [];
    }
}
