<?php

/**
 * dbswaf_monitor.php - 运营监控配置文件
 *
 * 发布命令: php artisan vendor:publish --tag=dbswaf-config
 * 默认关闭，需要在 .env 中设置 DBSWAF_MONITOR=true 开启
 */

return [

    // 监控开关（默认关闭）
    'enabled' => env('DBSWAF_MONITOR', false),

    // 慢请求阈值（毫秒），超过此值的请求将被记录
    'slow_threshold' => (int) env('DBSWAF_SLOW_THRESHOLD', 3000),

    // 是否记录慢 SQL 查询
    'log_queries' => (bool) env('DBSWAF_LOG_QUERIES', false),

    // 慢 SQL 阈值（毫秒）
    'query_threshold' => (int) env('DBSWAF_QUERY_THRESHOLD', 1000),

    // 是否开启指标端点（GET /dbswaf/metrics）
    'metrics_endpoint' => (bool) env('DBSWAF_METRICS_ENDPOINT', true),

    // 允许访问监控端点的 IP 列表
    'allowed_ips' => array_filter(
        explode(',', env('DBSWAF_MONITOR_IPS', '127.0.0.1,::1'))
    ),

    // 监控数据保留时间（小时）
    'retention_hours' => (int) env('DBSWAF_MONITOR_RETENTION', 24),
];
