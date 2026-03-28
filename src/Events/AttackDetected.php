<?php

namespace Dabashan\BtLaravelWaf\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\SerializesModels;

class AttackDetected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string  $ip,
        public readonly string  $attackType,
        public readonly string  $detail,
        public readonly Request $request
    ) {}

    public function getSeverity(): string
    {
        return match ($this->attackType) {
            'cmd_injection', 'rce'               => 'critical',
            'sql_injection', 'path_traversal'    => 'high',
            'xss', 'scanner_useragent'           => 'medium',
            'cc_attack', 'host_injection'        => 'medium',
            default                               => 'low',
        };
    }

    public function toArray(): array
    {
        return [
            'ip'          => $this->ip,
            'type'        => $this->attackType,
            'severity'    => $this->getSeverity(),
            'detail'      => $this->detail,
            'uri'         => $this->request->getRequestUri(),
            'method'      => $this->request->method(),
            'ua'          => $this->request->userAgent(),
            'domain'      => $this->request->getHost(),
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
