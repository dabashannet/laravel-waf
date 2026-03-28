<?php

namespace Dabashan\BtLaravelWaf\Services;

use Dabashan\BtLaravelWaf\Events\IpBanned;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CcProtection
{
    /**
     * 检查请求是否允许通过
     *
     * @return array{allowed: bool, remaining: int, ban_time: int, reason: string}
     */
    public function check(Request $request): array
    {
        $ip   = $request->ip();
        $path = '/' . $request->path();

        $rateConfig = $this->resolveRateConfig($path);

        // 分钟级滑动窗口检查
        $minuteResult = $this->checkSlidingWindow(
            key: "dbswaf:cc:min:{$ip}",
            limit: $rateConfig['rate'],
            window: 60
        );

        if (!$minuteResult['allowed']) {
            $banTime = $rateConfig['ban_time'] ?? config('dbswaf.cc_protection.ban_time', 600);
            $this->banIp($ip, $banTime, '分钟级速率超限');
            return [
                'allowed'   => false,
                'remaining' => 0,
                'ban_time'  => $banTime,
                'reason'    => "请求频率超过 {$rateConfig['rate']} 次/分钟",
            ];
        }

        // 10 秒突发速率检查
        $burstResult = $this->checkSlidingWindow(
            key: "dbswaf:cc:burst:{$ip}",
            limit: $rateConfig['burst'],
            window: 10
        );

        if (!$burstResult['allowed']) {
            $banTime = min($rateConfig['ban_time'] ?? 600, 300);
            $this->banIp($ip, $banTime, '突发速率超限');
            return [
                'allowed'   => false,
                'remaining' => 0,
                'ban_time'  => $banTime,
                'reason'    => "短时突发请求超过 {$rateConfig['burst']} 次/10秒",
            ];
        }

        return [
            'allowed'   => true,
            'remaining' => $minuteResult['remaining'],
            'ban_time'  => 0,
            'reason'    => '',
        ];
    }

    /**
     * 滑动窗口速率限制算法（兼容 file/redis 驱动）
     *
     * @return array{allowed: bool, count: int, remaining: int}
     */
    protected function checkSlidingWindow(string $key, int $limit, int $window): array
    {
        $currentSlot = floor(time() / $window);
        $prevSlot    = $currentSlot - 1;

        $currentKey = "{$key}:{$currentSlot}";
        $prevKey    = "{$key}:{$prevSlot}";

        $elapsed  = time() % $window;
        $weight   = 1 - ($elapsed / $window);

        $currentCount = (int) Cache::get($currentKey, 0);
        $prevCount    = (int) Cache::get($prevKey, 0);

        $estimatedCount = (int) ($prevCount * $weight) + $currentCount;

        if ($estimatedCount >= $limit) {
            return [
                'allowed'   => false,
                'count'     => $estimatedCount,
                'remaining' => 0,
            ];
        }

        $newCount = Cache::increment($currentKey);
        if ($newCount === 1) {
            Cache::put($currentKey, 1, now()->addSeconds($window * 2));
        }

        return [
            'allowed'   => true,
            'count'     => $newCount,
            'remaining' => max(0, $limit - $estimatedCount - 1),
        ];
    }

    protected function resolveRateConfig(string $path): array
    {
        $sensitivePaths = config('dbswaf.cc_protection.sensitive_paths', []);
        $globalRate     = config('dbswaf.cc_protection.rate_limit', 60);
        $globalBurst    = config('dbswaf.cc_protection.burst_limit', 20);

        foreach ($sensitivePaths as $pattern => $config) {
            if ($this->pathMatchesPattern($path, $pattern)) {
                return array_merge([
                    'rate'     => $globalRate,
                    'burst'    => $globalBurst,
                    'ban_time' => config('dbswaf.cc_protection.ban_time', 600),
                ], $config);
            }
        }

        return [
            'rate'     => $globalRate,
            'burst'    => $globalBurst,
            'ban_time' => config('dbswaf.cc_protection.ban_time', 600),
        ];
    }

    public function banIp(string $ip, int $seconds, string $reason = ''): void
    {
        $banKey      = 'dbswaf:banned:' . md5($ip);
        $banCountKey = 'dbswaf:ban_count:' . md5($ip);

        $banCount = (int) Cache::get($banCountKey, 0) + 1;
        Cache::put($banCountKey, $banCount, now()->addDay());

        // 滚雪球策略：累计封禁时间按次数倍增（最长 24 小时）
        $effectiveBanTime = min($seconds * $banCount, 86400);

        $permanentThreshold = config('dbswaf.cc_protection.permanent_ban_threshold', 10);
        if ($banCount >= $permanentThreshold) {
            $effectiveBanTime = 86400 * 30;
            Log::warning('[DBSWAF] IP 触发永久封禁阈值', [
                'ip'        => $ip,
                'ban_count' => $banCount,
                'reason'    => $reason,
            ]);
        }

        Cache::put($banKey, [
            'ip'         => $ip,
            'reason'     => $reason,
            'ban_count'  => $banCount,
            'ban_time'   => $effectiveBanTime,
            'banned_at'  => time(),
            'expires_at' => time() + $effectiveBanTime,
        ], now()->addSeconds($effectiveBanTime));

        Log::info('[DBSWAF] IP 已封禁', [
            'ip'        => $ip,
            'reason'    => $reason,
            'duration'  => $effectiveBanTime . '秒',
            'ban_count' => $banCount,
        ]);

        event(new IpBanned($ip, $reason, $effectiveBanTime));
        $this->syncBanToWafFile($ip, $effectiveBanTime);
    }

    public function unbanIp(string $ip): void
    {
        Cache::forget('dbswaf:banned:' . md5($ip));
        Log::info('[DBSWAF] IP 已解封', ['ip' => $ip]);
    }

    public function getBanInfo(string $ip): ?array
    {
        return Cache::get('dbswaf:banned:' . md5($ip));
    }

    protected function syncBanToWafFile(string $ip, int $banTime): void
    {
        try {
            $dataPath      = config('dbswaf.data_path', '/www/server/dbswaf/data');
            $blacklistFile = $dataPath . '/blackip.json';

            if (!is_writable(dirname($blacklistFile))) {
                return;
            }

            $blacklist = [];
            if (file_exists($blacklistFile)) {
                $content   = file_get_contents($blacklistFile);
                $blacklist = json_decode($content, true) ?? [];
            }

            $blacklist[$ip] = [
                'ban_time'   => $banTime,
                'banned_at'  => time(),
                'expires_at' => time() + $banTime,
                'source'     => 'laravel_cc',
            ];

            $now       = time();
            $blacklist = array_filter($blacklist, fn($item) => $item['expires_at'] > $now);

            file_put_contents($blacklistFile, json_encode($blacklist, JSON_PRETTY_PRINT), LOCK_EX);
        } catch (\Throwable $e) {
            Log::debug('[DBSWAF] 同步黑名单到 WAF 文件失败: ' . $e->getMessage());
        }
    }

    protected function pathMatchesPattern(string $path, string $pattern): bool
    {
        if ($pattern === $path) {
            return true;
        }
        $regex = '#^' . str_replace(['*', '/'], ['[^/]*', '\/'], $pattern) . '#';
        return (bool) preg_match($regex, $path);
    }
}
