<?php

namespace Dabashan\BtLaravelWaf\Middleware;

use Closure;
use Dabashan\BtLaravelWaf\Events\AttackDetected;
use Dabashan\BtLaravelWaf\Events\IpBanned;
use Dabashan\BtLaravelWaf\Services\CcProtection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DbswafProtection
{
    public function __construct(
        protected CcProtection $ccProtection
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!config('dbswaf.enabled', true)) {
            return $next($request);
        }

        $ip   = $request->ip();
        $path = $request->path();

        // 静态资源快速放行
        if ($this->isStaticResource($path)) {
            return $next($request);
        }

        // 白名单 IP 直接放行
        if ($this->isWhitelistedIp($ip)) {
            return $next($request);
        }

        // 检查 IP 是否已被封禁
        if ($this->isIpBanned($ip)) {
            return $this->blockResponse($request, '您的 IP 已被封禁，如有疑问请联系管理员');
        }

        // CC 防护（速率限制）
        $ccResult = $this->ccProtection->check($request);
        if (!$ccResult['allowed']) {
            event(new IpBanned($ip, 'CC攻击', $ccResult['ban_time'] ?? 600));
            $this->reportToWaf($request, 'cc_attack', $ccResult);
            return $this->blockResponse($request, '请求过于频繁，请稍后再试', 429);
        }

        // 检测异常请求特征
        $anomaly = $this->detectAnomalies($request);
        if ($anomaly) {
            event(new AttackDetected($ip, $anomaly['type'], $anomaly['detail'], $request));
            Log::warning('[DBSWAF] 异常请求检测', [
                'ip'     => $ip,
                'path'   => $path,
                'type'   => $anomaly['type'],
                'detail' => $anomaly['detail'],
            ]);
            if ($anomaly['block']) {
                $this->reportToWaf($request, $anomaly['type'], $anomaly);
                return $this->blockResponse($request, '请求被安全策略拦截');
            }
        }

        $response = $next($request);

        if (config('dbswaf.report_interval') > 0) {
            $this->incrementRequestCounter($ip, $path);
        }

        return $response;
    }

    protected function isStaticResource(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'ico', 'svg', 'woff', 'woff2', 'ttf', 'eot', 'map', 'webp'])) {
            return true;
        }

        $excludePaths = config('dbswaf.cc_protection.exclude_paths', []);
        foreach ($excludePaths as $pattern) {
            $normalized = rtrim($pattern, '/*');
            if (str_starts_with('/' . $path, $normalized)) {
                return true;
            }
        }

        return false;
    }

    protected function isWhitelistedIp(string $ip): bool
    {
        $whitelist = config('dbswaf.cc_protection.whitelist', []);
        if (empty($whitelist)) {
            return false;
        }
        return in_array($ip, $whitelist, true);
    }

    protected function isIpBanned(string $ip): bool
    {
        return Cache::has('dbswaf:banned:' . md5($ip));
    }

    protected function detectAnomalies(Request $request): ?array
    {
        // 超大请求体检测
        $contentLength = (int) $request->header('Content-Length', 0);
        $maxSize = config('dbswaf.max_body_size', 10 * 1024 * 1024);
        if ($contentLength > $maxSize) {
            return [
                'type'   => 'oversized_body',
                'detail' => "Content-Length: {$contentLength} bytes 超过限制 {$maxSize}",
                'block'  => true,
            ];
        }

        // 异常 User-Agent 检测
        $ua = $request->userAgent() ?? '';
        if (empty($ua)) {
            return [
                'type'   => 'empty_useragent',
                'detail' => '请求缺少 User-Agent',
                'block'  => false,
            ];
        }

        // 已知扫描器/攻击工具特征检测
        $maliciousUaPatterns = [
            'sqlmap', 'nikto', 'nmap', 'masscan', 'zgrab',
            'python-requests/2.', 'Go-http-client/1.', 'curl/7.',
            'dirbuster', 'w3af', 'acunetix', 'nessus', 'openvas',
        ];
        $uaLower = strtolower($ua);
        foreach ($maliciousUaPatterns as $pattern) {
            if (str_contains($uaLower, $pattern)) {
                return [
                    'type'   => 'scanner_useragent',
                    'detail' => "检测到扫描工具特征: {$pattern}",
                    'block'  => true,
                ];
            }
        }

        // Host 头注入检测
        $host = $request->getHost();
        $allowedHosts = config('dbswaf.allowed_hosts', []);
        if (!empty($allowedHosts) && !in_array($host, $allowedHosts, true)) {
            return [
                'type'   => 'host_injection',
                'detail' => "Host 头异常: {$host}",
                'block'  => true,
            ];
        }

        // 过多请求头检测
        $headerCount = count($request->headers->all());
        if ($headerCount > 50) {
            return [
                'type'   => 'abnormal_headers',
                'detail' => "请求头数量异常: {$headerCount}",
                'block'  => false,
            ];
        }

        return null;
    }

    protected function reportToWaf(Request $request, string $attackType, array $detail = []): void
    {
        try {
            $wafServer = config('dbswaf.waf_server', '127.0.0.1:8899');
            if (empty($wafServer)) {
                return;
            }

            $payload = json_encode([
                'type'      => $attackType,
                'ip'        => $request->ip(),
                'uri'       => $request->getRequestUri(),
                'method'    => $request->method(),
                'ua'        => $request->userAgent(),
                'detail'    => $detail,
                'timestamp' => time(),
                'domain'    => $request->getHost(),
            ]);

            $cacheKey = 'dbswaf:pending_reports';
            $reports  = Cache::get($cacheKey, []);
            $reports[] = $payload;

            if (count($reports) > 100) {
                $reports = array_slice($reports, -100);
            }

            Cache::put($cacheKey, $reports, now()->addMinutes(10));
        } catch (\Throwable $e) {
            Log::debug('[DBSWAF] 上报失败: ' . $e->getMessage());
        }
    }

    protected function incrementRequestCounter(string $ip, string $path): void
    {
        try {
            $hourKey = 'dbswaf:req_count:' . date('YmdH');
            Cache::increment($hourKey);
            Cache::put($hourKey, Cache::get($hourKey, 1), now()->addHours(2));
        } catch (\Throwable $e) {
            // 统计失败不影响主流程
        }
    }

    protected function blockResponse(Request $request, string $message, int $status = 403): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'error'   => true,
                'message' => $message,
                'code'    => $status,
            ], $status);
        }

        return response(view('dbswaf::blocked', compact('message', 'status'))->render(), $status)
            ->header('Content-Type', 'text/html; charset=utf-8');
    }
}
