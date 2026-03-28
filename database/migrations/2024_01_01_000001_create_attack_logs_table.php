<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dbswaf_attack_logs', function (Blueprint $table) {
            $table->id();

            $table->string('ip', 45)->index()->comment('攻击者 IP 地址（支持 IPv6）');
            $table->string('country_code', 10)->nullable()->comment('国家/地区代码');

            $table->string('domain', 100)->nullable()->index()->comment('目标域名');
            $table->string('request_uri', 500)->comment('请求 URI（含查询参数）');
            $table->string('request_method', 10)->default('GET')->comment('HTTP 方法');
            $table->text('user_agent')->nullable()->comment('User-Agent');

            $table->string('attack_type', 50)->index()->comment('攻击类型');
            $table->string('severity', 20)->default('medium')->index()->comment('严重程度');
            $table->text('detail')->nullable()->comment('攻击详情描述');

            $table->string('action_taken', 20)->default('block')->comment('处置动作');

            $table->timestamp('occurred_at')->index()->comment('攻击发生时间');
            $table->timestamps();

            $table->index(['ip', 'attack_type', 'occurred_at'], 'idx_ip_type_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dbswaf_attack_logs');
    }
};
