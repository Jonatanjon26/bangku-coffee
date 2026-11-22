<?php

namespace App\Http\Middleware;

use App\Support\PosSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Kasir yang masuk dengan PIN di tablet ini; kunci otomatis bila tidak dipakai. Pakai setelah EnsurePosDevice. */
class EnsurePosCashier
{
    public function __construct(private readonly PosSession $session) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->session->user($request->attributes->get('pos_device'));

        if (! $user) {
            return redirect()->route('pos.login');
        }

        if ($this->session->idleExpired()) {
            $this->session->end();

            return redirect()->route('pos.login', ['locked' => 1]);
        }

        $this->session->touch();
        // auth()->user() di halaman kasir = kasir yang masuk (dipakai log audit & policy).
        Auth::shouldUse('pos');

        return $next($request);
    }
}
