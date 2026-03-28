<?php

/**
 * dbswaf.php - WAF 主配置文件
 *
 * 发布命令: php artisan vendor:publish --tag=dbswaf-config
 */

return [

    // WAF 全局开关
    'enabled' => env('DBSWAF_ENABLED', true),

    // 宝塔 WAF 面板地址（格式：host:port，留空则不上报）
    'waf_server' => env('DBSWAF_SERVER', '127.0.0.1:8899'),

    // 宝塔 WAF 文件路径（仅宝塔环境需要配置）
    'waf_path'   => env('DBSWAF_WAF_PATH', '/www/server/dbswaf'),
    'data_path'  => env('DBSWAF_DATA_PATH', '/www/server/dbswaf/data'),
    'rules_path' => env('DBSWAF_RULES_PATH', '/www/server/dbswaf/rules'),

    // 向宝塔 WAF 面板上报数据的间隔时间（秒），0 表示不主动上报
    'report_interval' => env('DBSWAF_REPORT_INTERVAL', 300),

    // 允许的 Host 列表（留空表示不检查 Host 头）
    'allowed_hosts' => array_filter(
        explode(',', env('DBSWAF_ALLOWED_HOSTS', ''))
    ),

    // 最大请求体大小（字节），默认 10MB
    'max_body_size' => env('DBSWAF_MAX_BODY_SIZE', 10 * 1024 * 1024),

    // CC 防护配置
    'cc_protection' => [

        'enabled'    => env('DBSWAF_CC_ENABLED', true),
        'rate_limit' => (int) env('DBSWAF_CC_RATE', 60),    // 每分钟最大请求数
        'burst_limit' => (int) env('DBSWAF_CC_BURST', 20),   // 10 秒内最大突发请求数
        'ban_time'   => (int) env('DBSWAF_BAN_TIME', 600),   // 封禁时长（秒）

        // 触发永久封禁的累计封禁次数阈值
        'permanent_ban_threshold' => (int) env('DBSWAF_PERM_BAN_THRESHOLD', 10),

        // IP 白名单（永不封禁）
        'whitelist' => array_filter(
            explode(',', env('DBSWAF_IP_WHITELIST', '127.0.0.1,::1'))
        ),

        // 排除路径（不进行 CC 检查）
        'exclude_paths' => [
            '/css/*',
            '/js/*',
            '/images/*',
            '/fonts/*',
            '/favicon.ico',
            '/robots.txt',
            '/sitemap.xml',
        ],

        // 敏感路径（更严格的速率限制）
        'sensitive_paths' => [
            '/login'           => ['rate' => 10, 'burst' => 5,  'ban_time' => 1800],
            '/register'        => ['rate' => 5,  'burst' => 3,  'ban_time' => 3600],
            '/password/reset*' => ['rate' => 5,  'burst' => 3,  'ban_time' => 3600],
            '/api/auth/*'      => ['rate' => 20, 'burst' => 10, 'ban_time' => 1800],
            '/api/*'           => ['rate' => 120, 'burst' => 30, 'ban_time' => 300],
        ],
    ],
];
