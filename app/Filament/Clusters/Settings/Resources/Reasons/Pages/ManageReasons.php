<?php

namespace App\Filament\Clusters\Settings\Resources\Reasons\Pages;

use App\Filament\Clusters\Settings\Resources\Reasons\ReasonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageReasons extends ManageRecords
{
    protected static string $resource = ReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
