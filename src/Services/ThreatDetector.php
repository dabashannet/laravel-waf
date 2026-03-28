<?php

namespace Dabashan\BtLaravelWaf\Services;

use Dabashan\BtLaravelWaf\Events\AttackDetected;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ThreatDetector
{
    protected array $sqlPatterns = [
        '/(\b(union|select|insert|update|delete|drop|create|alter|exec|execute)\b.*\b(from|into|where|table|database)\b)/i',
        '/(\'|\")(\s*)(or|and)(\s+)(\'|\"|[0-9]|\()/i',
        '/--(\s|$)/',
        '/\/\*.*\*\//',
        '/\b(sleep|benchmark|waitfor|delay)\s*\(/i',
        '/\bload_file\s*\(/i',
        '/\binto\s+(outfile|dumpfile)\b/i',
        '/\b(information_schema|sysobjects|syscolumns)\b/i',
    ];

    protected array $xssPatterns = [
        '/<\s*script[^>]*>.*?<\s*\/\s*script\s*>/is',
        '/on\w+\s*=\s*["\'][^"\']*["\']/',
        '/<\s*(iframe|object|embed|form|input|button)[^>]*>/i',
        '/javascript\s*:/i',
        '/data\s*:\s*text\/html/i',
        '/eval\s*\(/i',
        '/document\.(cookie|write|location)/i',
        '/window\.(location|open|eval)/i',
    ];

    protected array $pathTraversalPatterns = [
        '/\.\.[\/\\\\]/',
        '/\.\.[%2F%5C]/i',
        '/%2e%2e[%2F%5C]/i',
        '/\.(\/|\\\\)\./',
    ];

    protected array $cmdInjectionPatterns = [
        '/[;&|`](\s*)(ls|cat|wget|curl|bash|sh|python|perl|php|ruby|nc|ncat|netcat)(\s|$)/i',
        '/\$\(.*\)/',
        '/`[^`]*`/',
        '/\|\s*(cat|type|more|less|head|tail|grep|awk|sed)\s/i',
    ];

    public function scan(Request $request): ?array
    {
        $inputs = $this->collectInputs($request);

        if (empty($inputs)) {
            return null;
        }

        foreach ($inputs as $field => $value) {
            if (!is_string($value)) {
                continue;
            }

            $decoded = urldecode($value);

            $result = $this->checkSqlInjection($decoded, $field)
                ?? $this->checkXss($decoded, $field)
                ?? $this->checkPathTraversal($decoded, $field)
                ?? $this->checkCmdInjection($decoded, $field);

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    public function checkSqlInjection(string $input, string $field = ''): ?array
    {
        $cacheKey = 'dbswaf:sqli:' . md5($input);
        $cached   = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached === 'safe' ? null : $cached;
        }

        foreach ($this->sqlPatterns as $pattern) {
            if (preg_match($pattern, $input)) {
                $result = [
                    'threat'   => true,
                    'type'     => 'sql_injection',
                    'detail'   => "SQL 注入特征匹配，字段: {$field}，模式: {$pattern}",
                    'severity' => 'high',
                ];
                Cache::put($cacheKey, $result, now()->addMinutes(5));
                return $result;
            }
        }

        Cache::put($cacheKey, 'safe', now()->addMinutes(5));
        return null;
    }

    public function checkXss(string $input, string $field = ''): ?array
    {
        foreach ($this->xssPatterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return [
                    'threat'   => true,
                    'type'     => 'xss',
                    'detail'   => "XSS 特征匹配，字段: {$field}",
                    'severity' => 'medium',
                ];
            }
        }
        return null;
    }

    public function checkPathTraversal(string $input, string $field = ''): ?array
    {
        foreach ($this->pathTraversalPatterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return [
                    'threat'   => true,
                    'type'     => 'path_traversal',
                    'detail'   => "路径穿越特征匹配，字段: {$field}",
                    'severity' => 'high',
                ];
            }
        }
        return null;
    }

    public function checkCmdInjection(string $input, string $field = ''): ?array
    {
        foreach ($this->cmdInjectionPatterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return [
                    'threat'   => true,
                    'type'     => 'cmd_injection',
                    'detail'   => "命令注入特征匹配，字段: {$field}",
                    'severity' => 'critical',
                ];
            }
        }
        return null;
    }

    public function generateFingerprint(Request $request): string
    {
        return md5(implode('|', [
            $request->ip(),
            $request->userAgent() ?? '',
            $request->method(),
            $request->path(),
        ]));
    }

    protected function collectInputs(Request $request): array
    {
        $inputs = [];

        foreach ($request->query() as $key => $value) {
            $inputs["query.{$key}"] = is_array($value) ? implode(',', $value) : $value;
        }

        foreach ($request->except(['_token', 'password', 'password_confirmation']) as $key => $value) {
            if (!is_array($value)) {
                $inputs["body.{$key}"] = $value;
            }
        }

        $inputs['uri'] = $request->getRequestUri();

        foreach ($request->cookies->all() as $key => $value) {
            if (!in_array($key, ['XSRF-TOKEN', 'laravel_session'])) {
                $inputs["cookie.{$key}"] = $value;
            }
        }

        return $inputs;
    }

    public function logThreat(Request $request, array $threat): void
    {
        try {
            event(new AttackDetected(
                $request->ip(),
                $threat['type'],
                $threat['detail'],
                $request
            ));
        } catch (\Throwable $e) {
            Log::error('[DBSWAF] 威胁记录失败: ' . $e->getMessage());
        }
    }
}
