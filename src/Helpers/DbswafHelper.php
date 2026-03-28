<?php

namespace Dabashan\BtLaravelWaf\Helpers;

use Dabashan\BtLaravelWaf\Services\CcProtection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DbswafHelper
{
    public static function isIpBanned(string $ip): bool
    {
        return Cache::has('dbswaf:banned:' . md5($ip));
    }

    public static function getBanInfo(string $ip): ?array
    {
        return Cache::get('dbswaf:banned:' . md5($ip));
    }

    public static function banIp(string $ip, int $seconds = 3600, string $reason = '手动封禁'): void
    {
        if ($seconds <= 0) {
            $seconds = 86400 * 30;
        }
        app(CcProtection::class)->banIp($ip, $seconds, $reason);
    }

    public static function unbanIp(string $ip): void
    {
        app(CcProtection::class)->unbanIp($ip);
    }

    public static function getRealIp(Request $request): string
    {
        return $request->ip() ?? '0.0.0.0';
    }

    public static function isApiRequest(Request $request): bool
    {
        return $request->expectsJson()
            || $request->is('api/*')
            || str_starts_with($request->path(), 'api/');
    }

    public static function getStatus(): array
    {
        return [
            'enabled'         => config('dbswaf.enabled', true),
            'cc_protection'   => config('dbswaf.cc_protection.enabled', true),
            'monitor_enabled' => config('dbswaf_monitor.enabled', false),
            'waf_server'      => config('dbswaf.waf_server', ''),
            'cache_driver'    => config('cache.default', 'file'),
            'version'         => '2.0.0',
        ];
    }

    public static function cleanupCache(): array
    {
        $cleaned  = 0;
        $prevHour = date('YmdH', strtotime('-1 hour'));
        $keys = [
            "dbswaf:stats:{$prevHour}",
            "dbswaf:slow_requests:{$prevHour}",
            "dbswaf:errors:{$prevHour}",
        ];

        foreach ($keys as $key) {
            if (Cache::forget($key)) {
                $cleaned++;
            }
        }

        return [
            'cleaned_keys' => $cleaned,
            'cleaned_at'   => now()->toIso8601String(),
        ];
    }

    public static function formatAttackType(string $type): string
    {
        return match ($type) {
            'sql_injection'     => 'SQL 注入',
            'xss'               => '跨站脚本攻击',
            'cc_attack'         => 'CC 攻击',
            'path_traversal'    => '路径穿越',
            'cmd_injection'     => '命令注入',
            'scanner_useragent' => '扫描器探测',
            'host_injection'    => 'Host 头注入',
            'oversized_body'    => '超大请求体',
            'rce'               => '远程代码执行',
            default             => $type,
        };
    }

    public static function isPrivateIp(string $ip): bool
    {
        return !filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
