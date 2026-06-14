<?php

namespace JFA\PhiCmsCore\Resources\Sections\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use JFA\PhiCmsCore\Resources\Sections\SectionResource;

class ListSections extends ListRecords
{
    protected static string $resource = SectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
