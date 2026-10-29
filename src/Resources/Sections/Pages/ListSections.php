<?php

namespace JFA\PhiCmsCore\Resources\Sections\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Resources\Sections\SectionResource;

class ListSections extends ListRecords
{
    protected static string $resource = SectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->before(function (array $data): void {
                    if (config('phi-cms.editing', false) && ($data['type'] ?? null) === 'services') {
                        throw ValidationException::withMessages([
                            'type' => __('phi-cms-core::phi-cms-core.services_creation_workflow_required'),
                        ]);
                    }
                }),
        ];
    }
}
