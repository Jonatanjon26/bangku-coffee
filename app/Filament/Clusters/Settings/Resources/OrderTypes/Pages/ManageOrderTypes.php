<?php

namespace App\Filament\Clusters\Settings\Resources\OrderTypes\Pages;

use App\Filament\Clusters\Settings\Resources\OrderTypes\OrderTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageOrderTypes extends ManageRecords
{
    protected static string $resource = OrderTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
