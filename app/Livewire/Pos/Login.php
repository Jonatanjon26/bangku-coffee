<?php

namespace App\Livewire\Pos;

use App\Models\Device;
use App\Models\Shift;
use App\Support\PosLogin;
use App\Support\PosLoginException;
use App\Support\PosSession;
use App\Support\Settings;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Layar masuk tablet (docs/mockup/masuk.html): daftar tablet → pilih kasir → PIN.
 * Komponen hanya tampilan; tablet, karyawan, dan PIN selalu dicek ulang di server (PosLogin).
 */
#[Layout('components.layouts.pos')]
class Login extends Component
{
    #[Locked]
    public string $step = 'people';

    #[Locked]
    public ?int $userId = null;

    #[Locked]
    public int $lockedSeconds = 0;

    public string $error = '';

    #[Url]
    public bool $locked = false;

    public function mount(PosLogin $login, PosSession $session)
    {
        $device = $login->currentDevice(request());

        if (! $device) {
            $this->step = 'pair';

            return;
        }

        // Sudah masuk & masih aktif: langsung ke kasir (kecuali baru dikunci).
        if (! $this->locked && $session->user($device) && ! $session->idleExpired()) {
            return $this->redirectRoute('pos.kasir');
        }
    }

    public function pair(PosLogin $login, string $code): void
    {
        $this->error = '';

        try {
            [$device, $token] = $login->pair($code, request()->ip());
        } catch (PosLoginException $e) {
            $this->error = $e->getMessage();
            $this->dispatch('keypad-failed');

            return;
        }

        Cookie::queue($login->deviceCookie($token));
        // Cookie baru baru terkirim di respons; render di permintaan ini harus sudah mengenali tablet.
        request()->cookies->set(config('pos.device_cookie.name'), $token);
        $this->step = 'people';
        $this->dispatch('toast', message: __('pos.login.paired', ['name' => $device->name]));
    }

    public function select(PosLogin $login, int $userId): void
    {
        $device = $this->device($login);

        if (! $device || ! $login->cashiers()->contains('id', $userId)) {
            return;
        }

        $this->userId = $userId;
        $this->error = '';
        $this->lockedSeconds = $login->lockedFor($device, $userId);
        $this->step = 'pin';
    }

    public function back(): void
    {
        $this->reset('userId', 'error', 'lockedSeconds');
        $this->step = 'people';
    }

    public function login(PosLogin $login, PosSession $session, string $pin)
    {
        $device = $this->device($login);

        if (! $device || $this->userId === null) {
            return null;
        }

        try {
            $user = $login->attempt($device, $this->userId, $pin);
        } catch (PosLoginException $e) {
            $this->error = $e->reason === PosLoginException::LOCKED ? '' : $e->getMessage();
            $this->lockedSeconds = $e->seconds;
            $this->dispatch('keypad-failed');

            return null;
        }

        $session->start($user, $device);

        return $this->redirectRoute('pos.kasir');
    }

    /** Tablet dari cookie, dicek di setiap aksi (bisa dicabut admin kapan saja). */
    private function device(PosLogin $login): ?Device
    {
        $device = $login->currentDevice(request());

        if (! $device) {
            $this->reset('userId', 'error', 'lockedSeconds');
            $this->step = 'pair';
        }

        return $device;
    }

    public function render(PosLogin $login, Settings $settings): View
    {
        $device = $login->currentDevice(request());
        $cashiers = $device ? $login->cashiers() : collect();

        return view('livewire.pos.login', [
            'device' => $device,
            'shift' => $device ? Shift::running()->where('device_id', $device->id)->with('opener')->latest('opened_at')->first() : null,
            'cashiers' => $cashiers,
            'selected' => $selected = $cashiers->firstWhere('id', $this->userId),
            'pinLength' => $login->pinLengthFor($selected),
        ])->layoutData([
            'title' => __('pos.login.title'),
            'storeName' => $settings->get('store.name'),
        ]);
    }
}
