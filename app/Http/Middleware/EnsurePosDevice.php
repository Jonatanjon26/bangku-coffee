<?php

namespace App\Http\Middleware;

use App\Support\PosLogin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Hanya tablet terdaftar (token cookie cocok & belum dicabut). Tablet tersedia di $request->attributes('pos_device'). */
class EnsurePosDevice
{
    public function __construct(private readonly PosLogin $login) {}

    public function handle(Request $request, Closure $next): Response
    {
        $device = $this->login->currentDevice($request);

        if (! $device) {
            return redirect()->route('pos.login')->withCookie($this->login->forgetDeviceCookie());
        }

        if (! $device->last_seen_at || $device->last_seen_at->lt(now()->subMinutes(5))) {
            $device->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        $request->attributes->set('pos_device', $device);

        return $next($request);
    }
}
