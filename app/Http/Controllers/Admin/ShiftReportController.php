<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Support\Printing\Documents;
use Filament\Facades\Filament;
use Illuminate\View\View;

/** Laporan shift (isi sama dengan cetakan 57 mm di kasir) untuk dilihat/dicetak dari panel admin. */
class ShiftReportController extends Controller
{
    public function __invoke(Shift $shift, Documents $documents): View
    {
        $user = auth()->user();
        abort_unless($user?->canAccessPanel(Filament::getPanel('admin')) && $user->can('view', $shift) && ! $shift->isOpen(), 403);

        return view('admin.shift-report', ['shift' => $shift, 'doc' => $documents->shiftReport($shift)]);
    }
}
