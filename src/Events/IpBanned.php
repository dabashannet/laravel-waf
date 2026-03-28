<?php

namespace Dabashan\BtLaravelWaf\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IpBanned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $ip,
        public readonly string $reason,
        public readonly int    $banTime
    ) {}

    public function isLongBan(): bool
    {
        return $this->banTime >= 3600;
    }

    public function isPermanentBan(): bool
    {
        return $this->banTime >= 86400;
    }

    public function getExpiresAt(): int
    {
        return time() + $this->banTime;
    }

    public function toArray(): array
    {
        return [
            'ip'           => $this->ip,
            'reason'       => $this->reason,
            'ban_time'     => $this->banTime,
            'expires_at'   => date('Y-m-d H:i:s', $this->getExpiresAt()),
            'is_permanent' => $this->isPermanentBan(),
            'banned_at'    => now()->toIso8601String(),
        ];
    }
}
