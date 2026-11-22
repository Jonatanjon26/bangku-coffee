<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Support\Csv;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManageCustomers extends ManageRecords
{
    protected static string $resource = CustomerResource::class;

    public function getSubheading(): ?string
    {
        $t = fn (string $key, array $r = []) => __("admin.customers.{$key}", $r);
        $settings = app(Settings::class);
        $parts = [$t('count', ['count' => Customer::count(), 'consent' => Customer::where('contact_consent', true)->count()])];

        if ($settings->get('customers.loyalty_enabled')) {
            $parts[] = $t('loyalty_on');
        }

        if (! $settings->get('customers.enabled')) {
            $parts[] = $t('disabled_note');
        }

        return implode(' · ', $parts);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')
                ->label(__('admin.customers.csv'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn () => $this->downloadCsv()),
            CreateAction::make()->using(fn (array $data) => CustomerResource::persist(new Customer, $data)),
        ];
    }

    public function downloadCsv(): StreamedResponse
    {
        abort_unless(CustomerResource::canViewAny(), 403);
        $t = fn (string $k) => __("admin.customers.{$k}");
        // Data pribadi keluar dari aplikasi: dicatat siapa & berapa baris (tanpa isi datanya).
        AuditLog::record('customer.exported', null, null, ['rows' => Customer::count()]);

        return Csv::download('pelanggan-'.now()->format('Ymd').'.csv',
            [__('admin.common.name'), $t('phone'), $t('visits'), $t('spent'), $t('points'), $t('last_visit'), $t('consent'), $t('notes')],
            Customer::orderBy('name')->lazy()->map(fn (Customer $c) => [
                $c->name, $c->phone, $c->visits_count, $c->total_spent, $c->points, $c->last_visit_at?->format('Y-m-d H:i'),
                $c->contact_consent ? $t('yes') : $t('no'), $c->notes,
            ]),
        );
    }
}
