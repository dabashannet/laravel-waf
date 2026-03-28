<?php

namespace Dabashan\BtLaravelWaf\Http\Controllers;

use Dabashan\BtLaravelWaf\Services\CcProtection;
use Dabashan\BtLaravelWaf\Services\MonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class StatusController extends Controller
{
    public function __construct(
        protected MonitorService $monitor,
        protected CcProtection  $cc
    ) {}

    public function check(Request $request): JsonResponse
    {
        $health = $this->monitor->getHealthStatus();

        $statusCode = match ($health['status']) {
            'healthy'   => 200,
            'degraded'  => 207,
            'unhealthy' => 503,
            default     => 200,
        };

        return response()->json([
            'ok'     => $health['status'] === 'healthy',
            'health' => $health,
            'waf'    => [
                'enabled'       => config('dbswaf.enabled', true),
                'cc_protection' => config('dbswaf.cc_protection.enabled', true),
                'waf_server'    => config('dbswaf.waf_server', ''),
            ],
        ], $statusCode);
    }

    public function metrics(Request $request): JsonResponse
    {
        if (!config('dbswaf_monitor.metrics_endpoint', true)) {
            return response()->json(['error' => '指标端点已禁用'], 403);
        }

        return response()->json($this->monitor->getMetrics());
    }

    public function report(Request $request): JsonResponse
    {
        $type = $request->input('type');

        return match ($type) {
            'ban_ip'   => $this->handleBanCommand($request),
            'unban_ip' => $this->handleUnbanCommand($request),
            'ping'     => response()->json(['pong' => true, 'ts' => time()]),
            default    => response()->json(['error' => '未知指令类型: ' . $type], 400),
        };
    }

    protected function handleBanCommand(Request $request): JsonResponse
    {
        $ip      = $request->input('ip');
        $banTime = (int) $request->input('ban_time', 3600);
        $reason  = $request->input('reason', '宝塔面板下发封禁');

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return response()->json(['error' => '无效的 IP 地址'], 400);
        }

        $this->cc->banIp($ip, $banTime, $reason);

        return response()->json([
            'ok'       => true,
            'ip'       => $ip,
            'ban_time' => $banTime,
        ]);
    }

    protected function handleUnbanCommand(Request $request): JsonResponse
    {
        $ip = $request->input('ip');

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return response()->json(['error' => '无效的 IP 地址'], 400);
        }

        $this->cc->unbanIp($ip);

        return response()->json(['ok' => true, 'ip' => $ip]);
    }
}
