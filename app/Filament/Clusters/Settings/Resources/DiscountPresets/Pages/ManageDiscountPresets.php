<?php

namespace App\Filament\Clusters\Settings\Resources\DiscountPresets\Pages;

use App\Filament\Clusters\Settings\Resources\DiscountPresets\DiscountPresetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDiscountPresets extends ManageRecords
{
    protected static string $resource = DiscountPresetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
