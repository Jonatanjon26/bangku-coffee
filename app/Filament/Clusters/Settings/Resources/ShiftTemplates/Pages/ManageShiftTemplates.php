<?php

namespace App\Filament\Clusters\Settings\Resources\ShiftTemplates\Pages;

use App\Filament\Clusters\Settings\Resources\ShiftTemplates\ShiftTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageShiftTemplates extends ManageRecords
{
    protected static string $resource = ShiftTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
