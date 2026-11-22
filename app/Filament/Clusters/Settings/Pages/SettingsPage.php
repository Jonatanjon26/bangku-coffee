<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\Permission;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Form untuk sekelompok kunci pengaturan (tabel settings). Nilai dikonversi ke tipe bawaannya
 * (config/pos.php) dan disimpan lewat Settings::set (tercatat di log audit). Butuh hak "settings".
 *
 * @property-read Schema $form
 */
abstract class SettingsPage extends Page
{
    protected static ?string $cluster = SettingsCluster::class;

    /** Kunci terjemahan di admin.settings.{section} (judul & keterangan). */
    protected static string $section;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @return list<string> kunci "grup.kunci" yang diatur halaman ini */
    abstract protected function keys(): array;

    /** @return array<Component|Field> */
    abstract protected function components(): array;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission(Permission::Settings);
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.settings.'.static::$section.'.title');
    }

    public function getTitle(): string|Htmlable
    {
        return static::getNavigationLabel();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('admin.settings.'.static::$section.'.description');
    }

    protected function label(string $key): string
    {
        return __('admin.settings.'.static::$section.'.'.$key);
    }

    public function mount(): void
    {
        $settings = app(Settings::class);
        $state = [];

        foreach ($this->keys() as $key) {
            data_set($state, $key, $settings->get($key));
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components($this->components());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label(__('admin.common.save'))->submit('save')->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();
        $settings = app(Settings::class);

        foreach ($this->keys() as $key) {
            $settings->set($key, $this->cast($key, data_get($state, $key)), auth()->user());
        }

        Notification::make()->success()->title(__('admin.common.saved'))->send();
    }

    /** Ikuti tipe nilai bawaan (bool/int/float/string) supaya tersimpan konsisten. */
    private function cast(string $key, mixed $value): mixed
    {
        return match (gettype(config("pos.settings.{$key}"))) {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'double' => (float) $value,
            default => trim((string) $value),
        };
    }
}
