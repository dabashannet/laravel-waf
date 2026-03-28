<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="30">
    <title>DBSWAF 状态监控</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #0f172a; color: #e2e8f0; padding: 24px; min-height: 100vh; }
        .header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid #1e293b; }
        .header h1 { font-size: 20px; font-weight: 600; }
        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: 500; }
        .badge-healthy { background: #14532d; color: #86efac; }
        .badge-degraded { background: #78350f; color: #fde68a; }
        .badge-unhealthy { background: #7f1d1d; color: #fca5a5; }
        .dot { width: 8px; height: 8px; border-radius: 50%; }
        .dot-healthy { background: #22c55e; }
        .dot-degraded { background: #f59e0b; }
        .dot-unhealthy { background: #ef4444; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .card { background: #1e293b; border-radius: 8px; padding: 20px; }
        .card-label { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .card-value { font-size: 28px; font-weight: 700; color: #f1f5f9; }
        .card-sub { font-size: 12px; color: #64748b; margin-top: 4px; }
        .section { background: #1e293b; border-radius: 8px; padding: 20px; margin-bottom: 16px; }
        .section h2 { font-size: 15px; font-weight: 600; margin-bottom: 16px; color: #94a3b8; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { text-align: left; padding: 8px 12px; background: #0f172a; color: #64748b; font-weight: 500; }
        td { padding: 8px 12px; border-bottom: 1px solid #0f172a; }
        tr:last-child td { border-bottom: none; }
        .refresh-note { text-align: right; font-size: 12px; color: #475569; margin-top: 16px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🛡️ DBSWAF 状态监控</h1>
        @php $statusClass = $health['status'] === 'healthy' ? 'healthy' : ($health['status'] === 'degraded' ? 'degraded' : 'unhealthy') @endphp
        <span class="badge badge-{{ $statusClass }}">
            <span class="dot dot-{{ $statusClass }}"></span>
            {{ ['healthy' => '健康', 'degraded' => '降级', 'unhealthy' => '异常'][$health['status']] ?? '未知' }}
        </span>
    </div>

    <div class="grid">
        <div class="card">
            <div class="card-label">本小时请求数</div>
            <div class="card-value">{{ number_format($stats['total']) }}</div>
        </div>
        <div class="card">
            <div class="card-label">平均响应时间</div>
            <div class="card-value">{{ $stats['avg_time_ms'] }}<span style="font-size:16px;color:#64748b">ms</span></div>
        </div>
        <div class="card">
            <div class="card-label">错误率</div>
            <div class="card-value" style="{{ (float)rtrim($stats['error_rate'],'%') > 5 ? 'color:#ef4444' : '' }}">{{ $stats['error_rate'] }}</div>
        </div>
        <div class="card">
            <div class="card-label">慢请求数</div>
            <div class="card-value">{{ $health['slow_requests'] }}</div>
            <div class="card-sub">≥ {{ config('dbswaf_monitor.slow_threshold', 3000) }}ms</div>
        </div>
    </div>

    @if(!empty($health['issues']))
    <div class="section">
        <h2>⚠️ 当前问题</h2>
        @foreach($health['issues'] as $issue)
        <div style="color:#fde68a;font-size:13px;padding:6px 0;border-bottom:1px solid #0f172a">{{ $issue }}</div>
        @endforeach
    </div>
    @endif

    @if(!empty($slowRequests))
    <div class="section">
        <h2>🐌 最近慢请求</h2>
        <table>
            <thead>
                <tr><th>路径</th><th>方法</th><th>响应时间</th><th>状态码</th><th>时间</th></tr>
            </thead>
            <tbody>
                @foreach(array_slice($slowRequests, 0, 10) as $req)
                <tr>
                    <td style="color:#93c5fd">{{ Str::limit($req['path'] ?? '-', 60) }}</td>
                    <td>{{ $req['method'] ?? '-' }}</td>
                    <td style="color:#fde68a">{{ $req['duration'] ?? 0 }}ms</td>
                    <td>{{ $req['status'] ?? '-' }}</td>
                    <td style="color:#64748b">{{ $req['timestamp'] ? date('H:i:s', $req['timestamp']) : '-' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <div class="section">
        <h2>⚙️ WAF 配置</h2>
        <table>
            <tr><td style="color:#64748b">WAF 状态</td><td>{{ config('dbswaf.enabled') ? '✅ 已启用' : '❌ 已禁用' }}</td></tr>
            <tr><td style="color:#64748b">CC 防护</td><td>{{ config('dbswaf.cc_protection.enabled') ? '✅ 已启用' : '❌ 已禁用' }}</td></tr>
            <tr><td style="color:#64748b">速率限制</td><td>{{ config('dbswaf.cc_protection.rate_limit', 60) }} 次/分钟</td></tr>
            <tr><td style="color:#64748b">WAF 面板</td><td>{{ config('dbswaf.waf_server') ?: '未配置' }}</td></tr>
            <tr><td style="color:#64748b">PHP 版本</td><td>{{ $health['php_version'] }}</td></tr>
            <tr><td style="color:#64748b">Laravel 版本</td><td>{{ $health['laravel_version'] }}</td></tr>
        </table>
    </div>

    <p class="refresh-note">每 30 秒自动刷新 | 检查时间: {{ $health['checked_at'] }}</p>
</body>
</html>
