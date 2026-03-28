<?php

use Dabashan\BtLaravelWaf\Http\Controllers\StatusController;
use Illuminate\Support\Facades\Route;

Route::prefix('dbswaf')
    ->middleware(['dbswaf.internal'])
    ->withoutMiddleware([\Dabashan\BtLaravelWaf\Middleware\DbswafProtection::class])
    ->group(function () {

        // GET /dbswaf/status - 健康检查
        Route::get('/status', [StatusController::class, 'check'])
            ->name('dbswaf.status');

        // GET /dbswaf/metrics - 详细指标（24 小时统计）
        Route::get('/metrics', [StatusController::class, 'metrics'])
            ->name('dbswaf.metrics');

        // POST /dbswaf/report - 宝塔 WAF 面板下行指令接收
        Route::post('/report', [StatusController::class, 'report'])
            ->name('dbswaf.report');
    });
