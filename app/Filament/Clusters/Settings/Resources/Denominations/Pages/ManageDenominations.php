<?php

namespace App\Filament\Clusters\Settings\Resources\Denominations\Pages;

use App\Filament\Clusters\Settings\Resources\Denominations\DenominationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDenominations extends ManageRecords
{
    protected static string $resource = DenominationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
