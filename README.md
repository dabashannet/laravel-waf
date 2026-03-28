# dabashan/bt-laravel-waf

大巴山宝塔WAF - Laravel 防护中间件

## 简介

本包为 [大巴山宝塔WAF](https://www.dabashan.cc) 提供 Laravel 框架集成支持，实现以下核心功能：

- **CC 防护**：基于滑动窗口算法的速率限制，支持全局速率和敏感路径独立配置
- **威胁检测**：SQL 注入、XSS、路径穿越、命令注入、扫描器识别
- **运营监控**：请求统计、慢请求追踪、错误率监控
- **宝塔集成**：与宝塔 WAF 面板双向通信，支持下发封禁指令

## 安装

```bash
composer require dabashan/bt-laravel-waf
```

Laravel 9+/10+/11+ 通过包自动发现自动注册，无需手动添加 ServiceProvider。

## 发布配置文件

```bash
# 发布配置文件
php artisan vendor:publish --tag=dbswaf-config

# 发布视图（可选，用于自定义拦截页面）
php artisan vendor:publish --tag=dbswaf-views

# 发布数据库迁移（可选）
php artisan vendor:publish --tag=dbswaf-migrations
php artisan migrate
```

## 配置

在 `.env` 文件中添加以下配置项：

```env
# WAF 全局开关
DBSWAF_ENABLED=true

# 宝塔 WAF 面板地址（留空则不上报）
DBSWAF_SERVER=127.0.0.1:8899

# CC 防护
DBSWAF_CC_ENABLED=true
DBSWAF_CC_RATE=60        # 每分钟最大请求数
DBSWAF_CC_BURST=20       # 10 秒内最大突发请求数
DBSWAF_BAN_TIME=600      # 封禁时长（秒）

# IP 白名单（逗号分隔）
DBSWAF_IP_WHITELIST=127.0.0.1,::1

# 运营监控（默认关闭）
DBSWAF_MONITOR=false
DBSWAF_SLOW_THRESHOLD=3000   # 慢请求阈值（毫秒）
```

## 中间件使用

包默认已将 `DbswafProtection` 中间件推入 `web` 和 `api` 中间件组，无需手动添加。

如需单独为某路由添加防护：

```php
// 路由文件中
Route::middleware(['dbswaf.protect'])->group(function () {
    // 受保护的路由
});

// 开启运营监控
Route::middleware(['dbswaf.monitor'])->group(function () {
    // 需要监控的路由
});
```

## 手动封禁/解封 IP

```php
use Dabashan\BtLaravelWaf\Helpers\DbswafHelper;

// 封禁 IP（默认 1 小时）
DbswafHelper::banIp('1.2.3.4', 3600, '手动封禁');

// 解封 IP
DbswafHelper::unbanIp('1.2.3.4');

// 查询 IP 是否被封禁
$isBanned = DbswafHelper::isIpBanned('1.2.3.4');
```

## 监听 WAF 事件

```php
use Dabashan\BtLaravelWaf\Events\AttackDetected;
use Dabashan\BtLaravelWaf\Events\IpBanned;

// 在 EventServiceProvider 中注册监听器
protected $listen = [
    AttackDetected::class => [
        \App\Listeners\LogAttackToDatabase::class,
    ],
    IpBanned::class => [
        \App\Listeners\NotifyAdminOnBan::class,
    ],
];
```

## 监控端点

开启监控后，以下端点可从内网访问（受 `InternalOnly` 中间件保护）：

| 端点 | 说明 |
|------|------|
| `GET /dbswaf/status` | 健康检查，返回 WAF 状态和请求统计 |
| `GET /dbswaf/metrics` | 详细指标，24 小时请求统计 |
| `POST /dbswaf/report` | 接收宝塔面板下发的封禁指令 |

通过 `DBSWAF_MONITOR_IPS` 配置允许访问这些端点的 IP 列表。

## 版权

Copyright © 四川大巴山网络科技有限公司

官网：[https://www.dabashan.cc](https://www.dabashan.cc)

本包基于 MIT 协议开源，仅供与大巴山宝塔WAF配合使用。
