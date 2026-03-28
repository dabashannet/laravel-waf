<?php

namespace Dabashan\BtLaravelWaf\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InternalOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedIps = config('dbswaf_monitor.allowed_ips', ['127.0.0.1', '::1']);
        $clientIp   = $request->ip();

        if (!in_array($clientIp, $allowedIps, true)) {
            abort(404);
        }

        return $next($request);
    }
}
