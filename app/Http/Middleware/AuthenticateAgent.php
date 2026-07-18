<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token auth for the out-dialing sync agent. The agent presents the
 * permanent key it received at enrollment; we match its sha256 against the
 * agent Device's stored api_key and stash the resolved Device on the request.
 */
class AuthenticateAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        if (! $bearer) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $device = Device::where('api_key', hash('sha256', $bearer))
            ->where('endpoint_type', 'agent')
            ->first();

        if (! $device) {
            return response()->json(['message' => 'Invalid agent key.'], 401);
        }

        $request->attributes->set('agent_device', $device);

        return $next($request);
    }
}
