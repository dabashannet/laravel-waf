<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dbswaf_stats', function (Blueprint $table) {
            $table->id();

            $table->string('domain', 100)->default('*')->index()->comment('域名');
            $table->string('stat_hour', 20)->index()->comment('统计小时（格式：YYYYMMDDhh）');

            $table->unsignedBigInteger('total_requests')->default(0)->comment('总请求数');
            $table->unsignedBigInteger('blocked_requests')->default(0)->comment('被拦截的请求数');
            $table->unsignedBigInteger('errors_4xx')->default(0)->comment('4xx 错误数');
            $table->unsignedBigInteger('errors_5xx')->default(0)->comment('5xx 错误数');

            $table->unsignedBigInteger('cc_attacks')->default(0)->comment('CC 攻击次数');
            $table->unsignedBigInteger('sqli_attacks')->default(0)->comment('SQL 注入次数');
            $table->unsignedBigInteger('xss_attacks')->default(0)->comment('XSS 攻击次数');
            $table->unsignedBigInteger('other_attacks')->default(0)->comment('其他攻击次数');

            $table->unsignedInteger('avg_response_ms')->default(0)->comment('平均响应时间（毫秒）');
            $table->unsignedInteger('max_response_ms')->default(0)->comment('最大响应时间（毫秒）');
            $table->unsignedInteger('slow_requests')->default(0)->comment('慢请求数');
            $table->unsignedInteger('ips_banned')->default(0)->comment('新增封禁 IP 数');

            $table->timestamps();

            $table->unique(['domain', 'stat_hour'], 'uniq_domain_hour');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dbswaf_stats');
    }
};
