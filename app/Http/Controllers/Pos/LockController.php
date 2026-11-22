<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Support\PosSession;
use Illuminate\Http\RedirectResponse;

/** Tombol kunci / ganti kasir (juga dipanggil kunci otomatis di browser). */
class LockController extends Controller
{
    public function __invoke(PosSession $session): RedirectResponse
    {
        $session->end();

        return redirect()->route('pos.login', ['locked' => 1]);
    }
}
